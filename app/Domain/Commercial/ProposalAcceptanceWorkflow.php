<?php

namespace App\Domain\Commercial;

use App\Domain\CloseoutAcknowledgmentSignatureCapture;
use App\Jobs\NotifyProposalOwner;
use App\Models\Opportunity;
use App\Models\ProposalAcceptance;
use App\Models\ProposalEngagementEvent;
use App\Models\ProposalPublication;
use App\Support\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProposalAcceptanceWorkflow
{
    public function __construct(
        private readonly ProposalSelectionCalculator $calculator,
        private readonly ProposalEmailVerificationWorkflow $verification,
        private readonly CloseoutAcknowledgmentSignatureCapture $signatureValidator,
        private readonly CommercialOpportunityAutomation $opportunities,
        private readonly ProposalDepositInvoiceWorkflow $depositInvoices,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string,mixed> $data @param array<int,bool> $selections */
    public function accept(ProposalAccess $access, Request $request, array $data, array $selections): ProposalAcceptance
    {
        $existing = ProposalAcceptance::query()->where('proposal_publication_id', $access->publication->id)->first();
        if ($existing) {
            if ($existing->idempotency_token === $data['idempotency_token']) {
                return $existing;
            }
            throw ValidationException::withMessages(['proposal' => 'This Proposal has already been accepted.']);
        }
        $this->verification->assertVerified($access, $data['signer_email'], isset($data['verification_id']) ? (int) $data['verification_id'] : null);
        $decoded = $this->signatureValidator->decode(
            $data['signature_data'],
            (int) config('commercial.proposal_signature_max_bytes', 1048576),
            'Draw a signature before accepting the Proposal.',
        );
        $disk = (string) config('commercial.proposal_disk', 'local');
        $key = 'commercial/acceptance-signatures/'.now()->format('Y/m').'/'.Str::uuid().'.png';
        if (! Storage::disk($disk)->put($key, $decoded['bytes'])) {
            throw ValidationException::withMessages(['signature_data' => 'The signature was not stored. Please retry.']);
        }
        try {
            $acceptance = DB::transaction(function () use ($access, $request, $data, $selections, $decoded, $disk, $key): ProposalAcceptance {
                $publication = ProposalPublication::query()->with('revision.document.opportunity.organization')->whereKey($access->publication->id)->lockForUpdate()->firstOrFail();
                $existing = ProposalAcceptance::query()->where('proposal_publication_id', $publication->id)->first();
                if ($existing) {
                    if ($existing->idempotency_token === $data['idempotency_token']) {
                        return $existing;
                    }
                    throw ValidationException::withMessages(['proposal' => 'This Proposal has already been accepted.']);
                }
                if ($publication->status === 'active' && $publication->expires_at->isPast()) {
                    $publication->update(['status' => 'expired']);
                }
                if ($publication->status !== 'active' || ! $publication->acceptance_enabled) {
                    throw ValidationException::withMessages(['proposal' => 'This Proposal is not currently available for acceptance.']);
                }
                if ($publication->publication_hash !== $access->publication->publication_hash || $publication->revision_content_hash !== $publication->revision->content_hash) {
                    throw ValidationException::withMessages(['proposal' => 'The immutable Proposal identity could not be verified.']);
                }
                $totals = $this->calculator->calculate($publication->snapshot, $selections);
                $milestones = $this->milestones($publication->snapshot['milestones'] ?? [], $totals['total_cents']);
                $acceptedSnapshot = $publication->snapshot;
                $acceptedSnapshot['accepted_options'] = collect($totals['lines'])->map(fn ($line) => ['line_id' => $line['id'], 'optional' => $line['optional'], 'included' => $line['included']])->all();
                $acceptedSnapshot['accepted_totals'] = collect($totals)->except('lines')->all();
                $acceptedSnapshot['accepted_payment_milestones'] = $milestones;
                $acceptedHash = hash('sha256', json_encode($acceptedSnapshot, JSON_THROW_ON_ERROR));
                $ip = $request->ip();
                $acceptance = ProposalAcceptance::query()->create([
                    'organization_id' => $publication->organization_id, 'proposal_publication_id' => $publication->id,
                    'commercial_revision_id' => $publication->commercial_revision_id, 'proposal_recipient_id' => $access->recipientId(),
                    'proposal_share_link_id' => $access->shareLinkId(), 'publication_hash' => $publication->publication_hash,
                    'revision_content_hash' => $publication->revision_content_hash, 'accepted_snapshot' => $acceptedSnapshot,
                    'accepted_snapshot_hash' => $acceptedHash, 'subtotal_cents' => $totals['subtotal_cents'],
                    'discount_cents' => $totals['discount_cents'], 'tax_cents' => $totals['tax_cents'], 'total_cents' => $totals['total_cents'],
                    'signer_name' => $data['signer_name'], 'signer_email' => strtolower(trim($data['signer_email'])), 'signer_title' => $data['signer_title'],
                    'consent_statement' => $publication->snapshot['acceptance']['statement'], 'consent_version' => $publication->snapshot['acceptance']['version'],
                    'signature_disk' => $disk, 'signature_key' => $key, 'signature_mime_type' => 'image/png',
                    'signature_byte_size' => strlen($decoded['bytes']), 'signature_width' => $decoded['width'], 'signature_height' => $decoded['height'],
                    'signature_sha256' => $decoded['sha256'], 'signed_at' => now(), 'encrypted_ip' => $ip,
                    'ip_hash' => $ip ? hash('sha256', strtolower(trim($ip))) : null, 'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
                    'idempotency_token' => $data['idempotency_token'],
                ]);
                foreach ($totals['lines'] as $line) {
                    $acceptance->selections()->create(['organization_id' => $publication->organization_id, 'publication_line_id' => $line['id'], 'optional' => $line['optional'], 'included' => $line['included'], 'line_snapshot' => $line]);
                }
                foreach ($milestones as $milestone) {
                    $acceptance->milestones()->create(['organization_id' => $publication->organization_id, ...$milestone]);
                }
                $publication->update(['status' => 'accepted', 'accepted_at' => now()]);
                $opportunity = Opportunity::query()->whereKey($publication->revision->document->opportunity_id)->lockForUpdate()->firstOrFail();
                $this->opportunities->won($opportunity, $publication->id);
                $this->depositInvoices->createForAcceptance($acceptance, $opportunity->owner);
                $event = ProposalEngagementEvent::query()->create([
                    'organization_id' => $publication->organization_id, 'proposal_publication_id' => $publication->id,
                    'proposal_recipient_id' => $access->recipientId(), 'proposal_share_link_id' => $access->shareLinkId(),
                    'event_type' => 'accepted', 'encrypted_ip' => $ip, 'ip_hash' => $ip ? hash('sha256', strtolower(trim($ip))) : null,
                    'user_agent' => Str::limit((string) $request->userAgent(), 512, ''), 'safe_metadata' => ['acceptance_id' => $acceptance->id, 'accepted_snapshot_hash' => $acceptedHash, 'total_cents' => $totals['total_cents']], 'occurred_at' => now(),
                ]);
                $this->audit->record($publication->revision->document->opportunity->organization, null, 'proposal.accepted', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'revision_id' => $publication->commercial_revision_id, 'acceptance_id' => $acceptance->id, 'accepted_snapshot_hash' => $acceptedHash, 'total_cents' => $totals['total_cents']]);
                NotifyProposalOwner::dispatch($event->id)->afterCommit();

                return $acceptance->fresh(['selections', 'milestones']);
            });
            if ($acceptance->signature_key !== $key) {
                Storage::disk($disk)->delete($key);
            }

            return $acceptance;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($key);
            throw $exception;
        }
    }

    /** @param array<int,array<string,mixed>> $source @return array<int,array<string,mixed>> */
    private function milestones(array $source, int $total): array
    {
        if ($source === []) {
            return [];
        }
        $result = [];
        $allocated = 0;
        $balancingIndex = null;
        foreach ($source as $index => $milestone) {
            if ($milestone['is_balancing']) {
                if ($balancingIndex !== null) {
                    throw ValidationException::withMessages(['payment_schedule' => 'Only one balancing milestone is allowed.']);
                }
                $balancingIndex = $index;
                $amount = 0;
            } else {
                $amount = $milestone['amount_type'] === 'percent'
                    ? intdiv($total * (int) $milestone['amount_value'] + 5000, 10000)
                    : (int) $milestone['amount_value'];
                $allocated += $amount;
            }
            $result[$index] = ['source_milestone_id' => $milestone['id'] ?? null, 'name' => $milestone['name'], 'amount_type' => $milestone['amount_type'], 'amount_value' => (int) $milestone['amount_value'], 'allocated_cents' => $amount, 'is_balancing' => (bool) $milestone['is_balancing'], 'sort_order' => (int) $milestone['sort_order']];
        }
        if ($allocated > $total) {
            throw ValidationException::withMessages(['payment_schedule' => 'The accepted payment schedule exceeds the accepted total.']);
        }
        if ($balancingIndex !== null) {
            $result[$balancingIndex]['allocated_cents'] = $total - $allocated;
            $allocated = $total;
        }
        if ($allocated !== $total) {
            throw ValidationException::withMessages(['payment_schedule' => 'The accepted payment schedule must reconcile exactly to the accepted total.']);
        }

        return array_values($result);
    }
}
