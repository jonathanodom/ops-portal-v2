<?php

namespace App\Http\Controllers;

use App\Domain\Commercial\ProposalAcceptanceWorkflow;
use App\Domain\Commercial\ProposalAccess;
use App\Domain\Commercial\ProposalAccessResolver;
use App\Domain\Commercial\ProposalEmailVerificationWorkflow;
use App\Domain\Commercial\ProposalEngagementWorkflow;
use App\Domain\Commercial\ProposalResponseWorkflow;
use App\Domain\Commercial\ProposalSelectionCalculator;
use App\Jobs\NotifyProposalOwner;
use App\Models\CommercialRevisionMedia;
use App\Models\ProposalEmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProposalController extends Controller
{
    public function show(Request $request, string $token, ProposalAccessResolver $resolver, ProposalEngagementWorkflow $engagement, ProposalSelectionCalculator $calculator): Response
    {
        $access = $this->access($request, $token, $resolver);
        $event = $engagement->recordView($access, $request);
        $publication = $access->publication->fresh(['comments.staffUser', 'acceptance.milestones']);
        if ($publication->status === 'active' && $publication->expires_at->isPast()) {
            $publication->update(['status' => 'expired']);
        }
        $selections = $engagement->selections($access);
        $totals = $calculator->calculate($publication->snapshot, $selections);
        $verifiedId = session('proposal_verified_'.$publication->id.'_'.$access->tokenHash);

        return $this->secureView('proposals.show', compact('publication', 'access', 'token', 'selections', 'totals', 'verifiedId', 'event'));
    }

    public function options(Request $request, string $token, ProposalAccessResolver $resolver, ProposalEngagementWorkflow $engagement, ProposalSelectionCalculator $calculator): JsonResponse|RedirectResponse
    {
        $access = $this->access($request, $token, $resolver);
        $data = $request->validate(['options' => ['nullable', 'array'], 'options.*' => ['integer']]);
        $selected = array_map('intval', $data['options'] ?? []);
        $selections = [];
        foreach (collect($access->publication->snapshot['lines'])->where('optional', true)->pluck('id') as $id) {
            $selections[(int) $id] = in_array((int) $id, $selected, true);
        }
        $engagement->saveOptions($access, $selections);
        $totals = $calculator->calculate($access->publication->snapshot, $selections);
        if (! $request->expectsJson()) {
            return back()->with('status', 'Proposal options updated.');
        }

        $isChangeOrder = ($access->publication->snapshot['document']['type'] ?? 'quote') === 'change_order';
        $delta = $isChangeOrder ? (int) collect($totals['lines'])->where('included', true)->sum(fn (array $line): int => in_array($line['change_effect'] ?? 'add', ['remove', 'substitute_remove'], true) ? -(int) $line['total_cents'] : (int) $line['total_cents']) : 0;
        $contractBefore = $isChangeOrder ? (int) (($access->publication->snapshot['totals']['resulting_project_total_cents'] ?? 0) - ($access->publication->snapshot['totals']['change_order_delta_cents'] ?? 0)) : 0;

        return response()->json(['subtotal' => $this->money($totals['subtotal_cents']), 'discount' => $this->money($totals['discount_cents']), 'tax' => $this->money($totals['tax_cents']), 'total' => $this->money($totals['total_cents']), 'change_order_delta' => ($delta < 0 ? '−' : '+').$this->money(abs($delta)), 'resulting_project_total' => $this->money(max(0, $contractBefore + $delta))], 200, $this->securityHeaders());
    }

    public function comment(Request $request, string $token, ProposalAccessResolver $resolver, ProposalEngagementWorkflow $engagement): RedirectResponse
    {
        $access = $this->access($request, $token, $resolver);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email:rfc', 'max:255'], 'target_type' => ['required', Rule::in(['proposal', 'section', 'line'])], 'target_reference' => ['nullable', 'string', 'max:100'], 'body' => ['required', 'string', 'max:5000']]);
        $engagement->comment($access, $request, $data);

        return back()->with('status', 'Your comment was added.');
    }

    public function requestChanges(Request $request, string $token, ProposalAccessResolver $resolver, ProposalResponseWorkflow $workflow): RedirectResponse
    {
        $access = $this->access($request, $token, $resolver);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email:rfc', 'max:255'], 'body' => ['required', 'string', 'max:5000'], 'confirm' => ['accepted']]);
        $workflow->requestChanges($access, $request, $data);

        return back()->with('status', 'Your change request was sent. This publication is now view-only.');
    }

    public function requestVerification(Request $request, string $token, ProposalAccessResolver $resolver, ProposalEngagementWorkflow $engagement, ProposalEmailVerificationWorkflow $workflow): RedirectResponse
    {
        $access = $this->access($request, $token, $resolver);
        $engagement->assertActionable($access);
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $verification = $workflow->request($access, $request, $data['email']);

        return back()->with('status', 'A verification code was sent.')->with('proposal_verification_id', $verification->id)->withInput(['signer_email' => $data['email']]);
    }

    public function verify(Request $request, string $token, ProposalEmailVerification $verification, ProposalAccessResolver $resolver, ProposalEmailVerificationWorkflow $workflow): RedirectResponse
    {
        $access = $this->access($request, $token, $resolver);
        $data = $request->validate(['verification_code' => ['required', 'digits:6']]);
        $verified = $workflow->verify($access, $request, $verification, $data['verification_code']);
        session(['proposal_verified_'.$access->publication->id.'_'.$access->tokenHash => $verified->id]);

        return back()->with('status', 'Email verified. You may now accept the Proposal.');
    }

    public function accept(Request $request, string $token, ProposalAccessResolver $resolver, ProposalEngagementWorkflow $engagement, ProposalAcceptanceWorkflow $workflow): Response
    {
        $access = $this->access($request, $token, $resolver);
        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:120'], 'signer_email' => ['required', 'email:rfc', 'max:255'],
            'signer_title' => ['required', 'string', 'max:120'], 'consent' => ['accepted'], 'signature_data' => ['required', 'string', 'max:1500000'],
            'idempotency_token' => ['required', 'uuid'], 'verification_id' => ['nullable', 'integer'],
        ]);
        $data['verification_id'] = session('proposal_verified_'.$access->publication->id.'_'.$access->tokenHash) ?? ($data['verification_id'] ?? null);
        $acceptance = $workflow->accept($access, $request, $data, $engagement->selections($access));

        return $this->secureView('proposals.accepted', compact('acceptance', 'token'));
    }

    public function pdf(Request $request, string $token, ProposalAccessResolver $resolver, ProposalEngagementWorkflow $engagement): StreamedResponse
    {
        $access = $this->access($request, $token, $resolver);
        $publication = $access->publication;
        abort_unless($publication->pdf_status === 'ready' && $publication->pdf_disk && $publication->pdf_key && Storage::disk($publication->pdf_disk)->exists($publication->pdf_key), 404);
        $event = $engagement->event($access, 'pdf_download', $request);
        NotifyProposalOwner::dispatch($event->id)->afterResponse();

        return Storage::disk($publication->pdf_disk)->download($publication->pdf_key, $publication->snapshot['document']['number'].'.pdf', $this->securityHeaders() + ['Content-Type' => 'application/pdf']);
    }

    public function media(Request $request, string $token, CommercialRevisionMedia $media, ProposalAccessResolver $resolver): StreamedResponse
    {
        $access = $this->access($request, $token, $resolver);
        $record = collect($access->publication->snapshot['media'])->firstWhere('id', $media->id);
        abort_unless($record && $media->organization_id === $access->publication->organization_id && $media->commercial_revision_id === $access->publication->commercial_revision_id && $media->state === 'stored' && $media->storage_key && Storage::disk($media->storage_disk)->exists($media->storage_key), 404);

        return Storage::disk($media->storage_disk)->download($media->storage_key, $media->original_name ?: 'proposal-file', $this->securityHeaders() + ['Content-Type' => $media->mime_type]);
    }

    private function access(Request $request, string $token, ProposalAccessResolver $resolver): ProposalAccess
    {
        $key = 'proposal-public:'.hash('sha256', $token).':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 120)) {
            abort(429, 'Too many Proposal requests. Try again shortly.');
        }
        RateLimiter::hit($key, 60);

        return $resolver->resolve($token);
    }

    private function secureView(string $view, array $data): Response
    {
        return response()->view($view, $data, 200, $this->securityHeaders());
    }

    /** @return array<string,string> */
    private function securityHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer'];
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2, '.', ',');
    }
}
