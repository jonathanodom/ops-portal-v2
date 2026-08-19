<?php

namespace App\Domain;

use App\Jobs\RenderPaymentReceiptPdf;
use App\Models\Invoice;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Payments\PaymentProviderResolver;
use App\Support\AuditRecorder;
use App\Support\IncidentRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentWorkflow
{
    public function __construct(private readonly PaymentProviderResolver $providers, private readonly AuditRecorder $audit, private readonly IncidentRecorder $incidents) {}

    public function setPreferredProvider(Invoice $invoice, User $actor, ?string $provider): Invoice
    {
        $membership = OrganizationMembership::query()->where('organization_id', $invoice->organization_id)->where('user_id', $actor->id)->where('status', 'active')->first();
        abort_unless($membership?->hasCapability('payments.settings.manage'), 403);

        return DB::transaction(function () use ($invoice, $actor, $provider): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, ['draft', 'ready_for_review', 'issued'], true)) {
                throw ValidationException::withMessages(['invoice' => 'The payment provider cannot be changed for this invoice.']);
            }
            if ($invoice->electronic_payment_provider) {
                throw ValidationException::withMessages(['preferred_payment_provider' => 'The electronic provider is locked after the first successful electronic payment.']);
            }
            if ($invoice->paymentAttempts()->whereIn('status', ['open', 'processing', 'unknown'])->exists()) {
                throw ValidationException::withMessages(['preferred_payment_provider' => 'Expire or reconcile the active checkout before changing its provider.']);
            }
            if ($provider !== null) {
                $this->readyConfiguration($invoice, $provider);
            }
            $invoice->forceFill(['preferred_payment_provider' => $provider])->save();
            $this->audit->record($invoice->organization, $actor, 'invoice.payment_provider_selected', $invoice, ['invoice_id' => $invoice->id, 'provider' => $provider, 'changed_fields' => ['preferred_payment_provider']]);

            return $invoice;
        });
    }

    /** @return array{attempt:PaymentAttempt,return_token:string} */
    public function createCheckout(Invoice $invoice, User $actor, int $amountCents, string $idempotencyKey): array
    {
        $returnToken = Str::random(64);
        $attempt = DB::transaction(function () use ($invoice, $actor, $amountCents, $idempotencyKey, $returnToken): PaymentAttempt {
            if ($existing = PaymentAttempt::query()->where('idempotency_key', $idempotencyKey)->first()) {
                abort_unless((int) $existing->invoice_id === (int) $invoice->id, 422);

                return $existing;
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => 'Only an issued invoice can collect payment.']);
            }
            $balance = $invoice->balanceCents();
            if ($amountCents < 1 || $amountCents > $balance) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive amount no greater than the current balance.']);
            }
            if ($invoice->paymentAttempts()->whereIn('status', ['open', 'processing', 'unknown'])->exists()) {
                throw ValidationException::withMessages(['payment' => 'Expire or reconcile the existing checkout before creating another.']);
            }
            $provider = $this->resolveCheckoutProvider($invoice);
            $configuration = $this->readyConfiguration($invoice, $provider);

            return PaymentAttempt::query()->create([
                'organization_id' => $invoice->organization_id, 'invoice_id' => $invoice->id, 'payment_provider_configuration_id' => $configuration->id,
                'provider' => $provider, 'amount_cents' => $amountCents, 'status' => 'open', 'idempotency_key' => $idempotencyKey,
                'return_token_hash' => hash('sha256', $returnToken), 'initiated_by_id' => $actor->id, 'expires_at' => now()->addMinutes((int) config('payments.attempt_minutes', 60)),
            ]);
        });
        if ($attempt->hosted_url) {
            return ['attempt' => $attempt, 'return_token' => $returnToken];
        }
        try {
            $result = $this->providers->resolve($attempt->configuration)->createCheckout($attempt->configuration, $attempt->load('invoice'), route('payments.return', ['attempt' => $attempt->id, 'token' => $returnToken]));
            $attempt->update(['status' => 'processing', 'hosted_url' => $result['url'], 'provider_session_id' => $result['session_id'], 'provider_order_id' => $result['order_id'], 'expires_at' => $result['expires_at'] ?: $attempt->expires_at]);
            $this->audit->record($attempt->invoice->organization, $actor, 'payment.checkout_created', $attempt, ['invoice_id' => $attempt->invoice_id, 'attempt_id' => $attempt->id, 'provider' => $attempt->provider, 'amount_cents' => $attempt->amount_cents]);
        } catch (Throwable) {
            $attempt->update(['status' => 'unknown', 'safe_failure_code' => 'checkout_creation_ambiguous']);
            $this->incidents->record($attempt->invoice->organization, $actor, 'payment_checkout_failure', 'error', $attempt, ['reason_code' => 'checkout_creation_ambiguous', 'status' => 'unknown']);
            throw ValidationException::withMessages(['payment' => 'The provider response was uncertain. Reconcile this attempt before retrying.']);
        }

        return ['attempt' => $attempt->fresh(), 'return_token' => $returnToken];
    }

    public function expire(PaymentAttempt $attempt, User $actor): PaymentAttempt
    {
        if (! $attempt->isOpen()) {
            return $attempt;
        }
        try {
            $this->providers->resolve($attempt->configuration)->expire($attempt->configuration, $attempt);
        } catch (Throwable) {
            throw ValidationException::withMessages(['payment' => 'The checkout could not be expired. Reconcile it before retrying.']);
        }
        $attempt->update(['status' => 'expired', 'completed_at' => now()]);
        $this->audit->record($attempt->invoice->organization, $actor, 'payment.checkout_expired', $attempt, ['invoice_id' => $attempt->invoice_id, 'attempt_id' => $attempt->id, 'provider' => $attempt->provider]);

        return $attempt;
    }

    public function reconcile(PaymentAttempt $attempt, ?User $actor = null): PaymentAttempt
    {
        $result = $this->providers->resolve($attempt->configuration)->retrieve($attempt->configuration, $attempt);

        return $this->applyAuthoritativeResult($attempt, $result, $actor);
    }

    public function recordManual(Invoice $invoice, User $actor, string $method, int $amountCents, \DateTimeInterface $receivedAt, ?string $reference, string $idempotencyKey, ?string $note = null): PaymentTransaction
    {
        $paymentSource = match ($method) {
            'cash', 'check' => 'manual',
            'credit_card', 'debit_card' => 'square_pos',
            default => throw ValidationException::withMessages(['method' => 'Select an available manual payment method.']),
        };

        return DB::transaction(function () use ($invoice, $actor, $method, $paymentSource, $amountCents, $receivedAt, $reference, $idempotencyKey, $note): PaymentTransaction {
            if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                abort_unless((int) $existing->invoice_id === (int) $invoice->id, 422);

                return $existing;
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => 'Only an issued invoice can receive payment.']);
            }
            if ($invoice->paymentAttempts()->whereIn('status', ['open', 'processing', 'unknown'])->exists()) {
                throw ValidationException::withMessages(['payment' => 'Expire or reconcile the electronic checkout before recording a manual payment.']);
            }
            if ($amountCents < 1 || $amountCents > $invoice->balanceCents()) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive amount no greater than the current balance.']);
            }
            if ($method === 'check' && blank($reference)) {
                throw ValidationException::withMessages(['reference' => 'A check reference is required.']);
            }
            $transaction = PaymentTransaction::query()->create(['organization_id' => $invoice->organization_id, 'invoice_id' => $invoice->id, 'type' => 'payment', 'status' => 'succeeded', 'method' => $method, 'payment_source' => $paymentSource, 'amount_cents' => $amountCents, 'manual_reference' => $reference, 'reason' => $note, 'idempotency_key' => $idempotencyKey, 'received_at' => $receivedAt, 'confirmed_at' => now(), 'recorded_by_id' => $actor->id]);
            $this->createReceipt($transaction);
            $this->audit->record($invoice->organization, $actor, 'payment.manual_recorded', $transaction, ['invoice_id' => $invoice->id, 'transaction_id' => $transaction->id, 'method' => $method, 'payment_source' => $paymentSource, 'amount_cents' => $amountCents]);

            return $transaction;
        });
    }

    public function reverseManual(PaymentTransaction $payment, User $actor, int $amountCents, string $reason, string $idempotencyKey): PaymentTransaction
    {
        if (! $payment->usesManualReversal() || $payment->type !== 'payment' || $payment->status !== 'succeeded') {
            throw ValidationException::withMessages(['payment' => 'Only successful manually recorded payments can be reversed manually.']);
        }

        return $this->createRefundTransaction($payment, $actor, $amountCents, $reason, $idempotencyKey, 'reversal', 'succeeded');
    }

    public function refund(PaymentTransaction $payment, User $actor, int $amountCents, string $reason, string $idempotencyKey): PaymentTransaction
    {
        if (! $payment->provider || $payment->payment_source !== 'hosted_checkout' || $payment->type !== 'payment' || $payment->status !== 'succeeded') {
            throw ValidationException::withMessages(['payment' => 'Only a successful electronic payment can be refunded.']);
        }
        $transaction = $this->createRefundTransaction($payment, $actor, $amountCents, $reason, $idempotencyKey, 'refund', 'pending');
        try {
            $configuration = PaymentProviderConfiguration::query()->forOrganization($payment->organization_id)->where('provider', $payment->provider)->firstOrFail();
            $result = $this->providers->resolve($configuration)->refund($configuration, $payment, $amountCents, $idempotencyKey);
            $transaction->update(['status' => $result['status'], 'provider_transaction_id' => $result['transaction_id'], 'confirmed_at' => $result['status'] === 'succeeded' ? now() : null]);
            if ($transaction->status === 'succeeded') {
                $this->createReceipt($transaction);
            }
        } catch (Throwable) {
            $this->incidents->record($payment->invoice->organization, $actor, 'payment_refund_failure', 'error', $transaction, ['reason_code' => 'provider_refund_unknown', 'status' => 'pending']);
        }

        return $transaction->fresh();
    }

    /** @param array<string,mixed> $event */
    public function processWebhook(PaymentProviderConfiguration $configuration, array $event, string $payloadHash): void
    {
        $receipt = PaymentWebhookEvent::query()->firstOrCreate(['payment_provider_configuration_id' => $configuration->id, 'provider_event_id' => $event['event_id']], ['organization_id' => $configuration->organization_id, 'provider' => $configuration->provider, 'event_type' => $event['type'], 'payload_sha256' => $payloadHash, 'status' => 'received', 'received_at' => now()]);
        if (! $receipt->wasRecentlyCreated) {
            $this->incidents->record($configuration->organization, null, 'payment_webhook_duplicate', 'warning', $configuration, ['reason_code' => 'duplicate_event', 'provider' => $configuration->provider]);

            return;
        }
        if ($receipt->status === 'processed') {
            return;
        }
        $attempt = PaymentAttempt::query()->where('payment_provider_configuration_id', $configuration->id)
            ->when($event['attempt_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when(! ($event['attempt_id'] ?? null) && ($event['session_id'] ?? null), fn ($q) => $q->where('provider_session_id', $event['session_id']))
            ->when(! ($event['attempt_id'] ?? null) && ! ($event['session_id'] ?? null) && ($event['order_id'] ?? null), fn ($q) => $q->where('provider_order_id', $event['order_id']))->first();
        if ($attempt && in_array($event['status'], ['succeeded', 'failed', 'expired'], true)) {
            $this->applyAuthoritativeResult($attempt, ['status' => $event['status'], 'payment_id' => $event['payment_id'] ?? $event['transaction_id'], 'amount_cents' => $event['amount_cents'], 'method' => $event['method'] ?? 'card'], null);
        }
        $refund = null;
        if ($event['status'] === 'refunded' && ($event['transaction_id'] ?? null)) {
            $refund = PaymentTransaction::query()->where('organization_id', $configuration->organization_id)->where('type', 'refund')->where('status', 'pending')->where('provider_transaction_id', $event['transaction_id'])->first();
            if ($refund) {
                $refund->update(['status' => 'succeeded', 'confirmed_at' => now()]);
                $this->createReceipt($refund);
            }
        }
        $handled = $attempt || $refund || $event['status'] === 'ignored';
        $receipt->update(['status' => $handled ? 'processed' : 'unmatched', 'processed_at' => now(), 'safe_failure_code' => $handled ? null : 'attempt_not_found']);
        if (! $handled) {
            $this->incidents->record($configuration->organization, null, 'payment_webhook_unmatched', 'warning', $configuration, ['reason_code' => 'attempt_not_found']);
        }
    }

    /** @return array{receipt:PaymentReceipt,token:string} */
    public function rotateReceiptToken(PaymentReceipt $receipt, User $actor): array
    {
        $token = Str::random(64);
        $receipt->update(['public_token_hash' => hash('sha256', $token), 'token_rotated_at' => now(), 'token_rotated_by_id' => $actor->id]);
        $this->audit->record($receipt->invoice->organization, $actor, 'payment.receipt_link_rotated', $receipt, ['invoice_id' => $receipt->invoice_id, 'receipt_id' => $receipt->id]);

        return ['receipt' => $receipt, 'token' => $token];
    }

    /** @param array{status:string,payment_id:?string,amount_cents:?int,method:?string} $result */
    private function applyAuthoritativeResult(PaymentAttempt $attempt, array $result, ?User $actor): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $result, $actor): PaymentAttempt {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->status === 'succeeded') {
                return $attempt;
            }
            if ($result['status'] === 'succeeded') {
                $amount = (int) ($result['amount_cents'] ?? $attempt->amount_cents);
                if ($amount !== (int) $attempt->amount_cents) {
                    $this->incidents->record($attempt->invoice->organization, $actor, 'payment_amount_mismatch', 'error', $attempt, ['reason_code' => 'provider_amount_mismatch', 'attempt_id' => $attempt->id, 'invoice_id' => $attempt->invoice_id, 'provider' => $attempt->provider]);
                }
                $transaction = PaymentTransaction::query()->firstOrCreate(['provider' => $attempt->provider, 'provider_transaction_id' => $result['payment_id']], ['organization_id' => $attempt->organization_id, 'invoice_id' => $attempt->invoice_id, 'payment_attempt_id' => $attempt->id, 'type' => 'payment', 'status' => 'succeeded', 'method' => $result['method'] ?: 'card', 'payment_source' => 'hosted_checkout', 'amount_cents' => $amount, 'safe_processor_reference' => $result['payment_id'] ? substr((string) $result['payment_id'], -12) : null, 'idempotency_key' => (string) Str::uuid(), 'received_at' => now(), 'confirmed_at' => now()]);
                $attempt->update(['status' => 'succeeded', 'provider_payment_id' => $result['payment_id'], 'completed_at' => now(), 'safe_failure_code' => null]);
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);
                if (! $invoice->electronic_payment_provider) {
                    $invoice->forceFill(['electronic_payment_provider' => $attempt->provider, 'payment_provider_locked_at' => now()])->save();
                }
                $this->createReceipt($transaction);
                if ($attempt->invoice->balanceCents() < 0) {
                    $this->incidents->record($attempt->invoice->organization, $actor, 'payment_overpayment', 'error', $attempt->invoice, ['reason_code' => 'negative_balance']);
                }
            } elseif (in_array($result['status'], ['failed', 'expired', 'canceled'], true)) {
                $attempt->update(['status' => $result['status'], 'completed_at' => now(), 'safe_failure_code' => $result['status'] === 'failed' ? 'provider_payment_failed' : null]);
            } else {
                $attempt->update(['status' => 'processing']);
            }

            return $attempt;
        });
    }

    private function createRefundTransaction(PaymentTransaction $payment, User $actor, int $amountCents, string $reason, string $idempotencyKey, string $type, string $status): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $actor, $amountCents, $reason, $idempotencyKey, $type, $status): PaymentTransaction {
            if ($existing = PaymentTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                abort_unless((int) $existing->original_transaction_id === (int) $payment->id, 422);

                return $existing;
            }
            $payment = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);
            $refunded = PaymentTransaction::query()->where('original_transaction_id', $payment->id)->whereIn('status', ['pending', 'succeeded'])->sum('amount_cents');
            if ($amountCents < 1 || $amountCents > $payment->amount_cents - $refunded) {
                throw ValidationException::withMessages(['amount' => 'The amount exceeds the refundable balance.']);
            }
            $transaction = PaymentTransaction::query()->create(['organization_id' => $payment->organization_id, 'invoice_id' => $payment->invoice_id, 'original_transaction_id' => $payment->id, 'type' => $type, 'status' => $status, 'provider' => $payment->provider, 'method' => $payment->method, 'payment_source' => $payment->payment_source, 'amount_cents' => $amountCents, 'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'received_at' => now(), 'confirmed_at' => $status === 'succeeded' ? now() : null, 'recorded_by_id' => $actor->id]);
            if ($status === 'succeeded') {
                $this->createReceipt($transaction);
            }
            $this->audit->record($payment->invoice->organization, $actor, 'payment.'.$type.'_created', $transaction, ['invoice_id' => $payment->invoice_id, 'transaction_id' => $transaction->id, 'original_transaction_id' => $payment->id, 'method' => $payment->method, 'payment_source' => $payment->payment_source, 'amount_cents' => $amountCents]);

            return $transaction;
        });
    }

    private function createReceipt(PaymentTransaction $transaction): PaymentReceipt
    {
        $receipt = PaymentReceipt::query()->firstOrCreate(['payment_transaction_id' => $transaction->id], ['organization_id' => $transaction->organization_id, 'invoice_id' => $transaction->invoice_id, 'pdf_status' => 'pending']);
        if ($receipt->wasRecentlyCreated) {
            RenderPaymentReceiptPdf::dispatch($receipt->id)->afterCommit();
        }

        return $receipt;
    }

    private function readyConfiguration(Invoice $invoice, string $provider): PaymentProviderConfiguration
    {
        $configuration = PaymentProviderConfiguration::query()->forOrganization($invoice->organization_id)->where('provider', $provider)->first();
        if (! $configuration?->isReady()) {
            throw ValidationException::withMessages(['preferred_payment_provider' => ucfirst($provider).' is not enabled and connection-tested in Billing Settings.']);
        }

        return $configuration;
    }

    private function resolveCheckoutProvider(Invoice $invoice): string
    {
        if ($invoice->electronic_payment_provider) {
            return $invoice->electronic_payment_provider;
        }
        if ($invoice->preferred_payment_provider) {
            return $invoice->preferred_payment_provider;
        }
        $default = OrganizationBillingSetting::query()->where('organization_id', $invoice->organization_id)->value('default_payment_provider');
        if ($default && PaymentProviderConfiguration::query()->forOrganization($invoice->organization_id)->where('provider', $default)->get()->contains->isReady()) {
            return $default;
        }
        $ready = PaymentProviderConfiguration::query()->forOrganization($invoice->organization_id)->whereIn('provider', ['square', 'stripe'])->get()->filter->isReady();
        if ($ready->count() === 1) {
            return (string) $ready->first()->provider;
        }

        throw ValidationException::withMessages(['provider' => $ready->isEmpty()
            ? 'Connect and enable an electronic payment provider in Billing Settings before creating a hosted checkout.'
            : 'Choose an organization default payment provider in Billing Settings before creating a hosted checkout.']);
    }
}
