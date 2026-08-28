<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\ProposalPublicationWorkflow;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverProposalPublication;
use App\Jobs\RenderProposalPublicationPdf;
use App\Models\AuditEvent;
use App\Models\CommercialDocument;
use App\Models\CommercialRevision;
use App\Models\ProposalDeliveryAttempt;
use App\Models\ProposalPublication;
use App\Models\ProposalRecipient;
use App\Models\ProposalShareLink;
use App\Models\ProposalTemplate;
use App\Support\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProposalPublicationController extends Controller
{
    public function store(Request $request, CommercialDocument $quote, CommercialRevision $revision, ProposalPublicationWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->quote($request, $quote, $revision);
        Gate::authorize('publish', $quote);
        $data = $request->validate(['proposal_template_id' => ['required', 'integer'], 'expires_on' => ['required', 'date_format:Y-m-d'], 'acceptance_enabled' => ['nullable', 'boolean'], 'show_line_details' => ['nullable', 'boolean'], 'show_location_totals' => ['nullable', 'boolean'], 'labor_grouping' => ['required', Rule::in(['location', 'system'])], 'show_manufacturer_model' => ['nullable', 'boolean'], 'show_product_images' => ['nullable', 'boolean'], 'show_package_components' => ['nullable', 'boolean']]);
        $template = ProposalTemplate::query()->forOrganization($quote->organization_id)->findOrFail($data['proposal_template_id']);
        foreach (['acceptance_enabled', 'show_line_details', 'show_location_totals', 'show_manufacturer_model', 'show_product_images', 'show_package_components'] as $field) {
            $data[$field] = $request->boolean($field);
        }
        $organizationTimezone = $request->attributes->get('organization')->timezone;
        $expiresOn = CarbonImmutable::parse($data['expires_on'], $organizationTimezone)->startOfDay();
        if ($expiresOn->lessThan(CarbonImmutable::now($organizationTimezone)->startOfDay())) {
            throw ValidationException::withMessages(['expires_on' => 'The expiration date cannot be before today in the organization timezone.']);
        }
        $data['expires_at'] = $expiresOn->endOfDay()->utc();
        $publication = $workflow->publish($revision, $template, $request->user(), $data);

        return redirect()->route('office.proposal-publications.show', $publication)->with('status', 'Immutable Proposal publication created; PDF generation was queued.');
    }

    public function show(Request $request, ProposalPublication $publication): View
    {
        $publication = $this->publication($request, $publication)->load(['revision.document.opportunity.customer', 'template', 'recipients', 'shareLinks', 'deliveries.recipient']);
        Gate::authorize('view', $publication->revision->document);
        $canPublish = Gate::allows('publish', $publication->revision->document);
        $canEngagement = Gate::allows('viewEngagement', $publication->revision->document);
        $opportunity = $publication->revision->document->opportunity;
        $audits = AuditEvent::query()
            ->where('organization_id', $publication->organization_id)
            ->where('subject_type', $opportunity->getMorphClass())
            ->where('subject_id', $opportunity->id)
            ->where(function ($query): void {
                $query->where('event_type', 'like', 'quote.%')->orWhere('event_type', 'like', 'proposal.%');
            })
            ->with('actor')
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        return view('office.proposal-publications.show', compact('publication', 'canPublish', 'canEngagement', 'audits'));
    }

    public function pdf(Request $request, ProposalPublication $publication): StreamedResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('view', $publication->revision->document);
        abort_unless($publication->pdf_status === 'ready' && $publication->pdf_disk && $publication->pdf_key && Storage::disk($publication->pdf_disk)->exists($publication->pdf_key), 404);

        return Storage::disk($publication->pdf_disk)->download($publication->pdf_key, $publication->snapshot['document']['number'].'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store']);
    }

    public function retryPdf(Request $request, ProposalPublication $publication): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        abort_unless($publication->pdf_status === 'failed', 422);
        $publication->update(['pdf_status' => 'pending', 'pdf_failure_code' => null]);
        RenderProposalPublicationPdf::dispatch($publication->id)->afterCommit();

        return back()->with('status', 'Proposal PDF retry queued.');
    }

    public function recipient(Request $request, ProposalPublication $publication, ProposalPublicationWorkflow $workflow): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255'], 'email' => ['required', 'email:rfc', 'max:255']]);
        [$recipient, $token] = $workflow->addRecipient($publication, $request->user(), $data['email'], $data['name'] ?? null);

        return back()->with('status', 'Recipient created. The Phase 4 token is shown once for local infrastructure testing only.')->with('proposal_token_once', $token)->with('proposal_token_record', 'Recipient '.$recipient->id);
    }

    public function shareLink(Request $request, ProposalPublication $publication, ProposalPublicationWorkflow $workflow): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        $data = $request->validate(['label' => ['nullable', 'string', 'max:255']]);
        [$link, $token] = $workflow->addShareLink($publication, $request->user(), $data['label'] ?? null);

        return back()->with('status', 'Generic link record created. Customer routing remains disabled until Phase 5.')->with('proposal_token_once', $token)->with('proposal_token_record', 'Share link '.$link->id);
    }

    public function revokeRecipient(Request $request, ProposalPublication $publication, ProposalRecipient $recipient, AuditRecorder $audit): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        abort_unless($recipient->proposal_publication_id === $publication->id, 404);
        $recipient->update(['revoked_at' => now(), 'revoked_by_id' => $request->user()->id]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'proposal.recipient_revoked', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'recipient_id' => $recipient->id]);

        return back()->with('status', 'Recipient access record revoked.');
    }

    public function revokeShareLink(Request $request, ProposalPublication $publication, ProposalShareLink $shareLink, AuditRecorder $audit): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        abort_unless($shareLink->proposal_publication_id === $publication->id, 404);
        $shareLink->update(['revoked_at' => now(), 'revoked_by_id' => $request->user()->id]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'proposal.share_link_revoked', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'share_link_id' => $shareLink->id]);

        return back()->with('status', 'Generic link record revoked.');
    }

    public function deliver(Request $request, ProposalPublication $publication, ProposalRecipient $recipient, AuditRecorder $audit): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        abort_unless($recipient->proposal_publication_id === $publication->id && ! $recipient->revoked_at, 404);
        $key = 'email-'.$recipient->id.'-'.Str::uuid();
        $attempt = ProposalDeliveryAttempt::query()->create(['organization_id' => $publication->organization_id, 'proposal_publication_id' => $publication->id, 'proposal_recipient_id' => $recipient->id, 'delivery_type' => 'email', 'status' => 'queued', 'idempotency_key' => $key]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'proposal.delivery_queued', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'recipient_id' => $recipient->id, 'delivery_attempt_id' => $attempt->id, 'delivery_type' => 'email']);
        DeliverProposalPublication::dispatch($attempt->id)->afterCommit();

        return back()->with('status', 'Local/test delivery queued.');
    }

    public function withdraw(Request $request, ProposalPublication $publication, ProposalPublicationWorkflow $workflow): RedirectResponse
    {
        $publication = $this->publication($request, $publication);
        Gate::authorize('publish', $publication->revision->document);
        $request->validate(['confirm' => ['accepted']]);
        $workflow->withdraw($publication, $request->user());

        return back()->with('status', 'Publication withdrawn. Immutable history was retained.');
    }

    private function publication(Request $request, ProposalPublication $publication): ProposalPublication
    {
        return ProposalPublication::query()->forOrganization($request->attributes->get('organization')->id)->with('revision.document')->findOrFail($publication->id);
    }

    private function quote(Request $request, CommercialDocument $quote, CommercialRevision $revision): array
    {
        $quote = CommercialDocument::query()->forOrganization($request->attributes->get('organization')->id)->where('document_type', 'quote')->findOrFail($quote->id);

        return [$quote, $quote->revisions()->findOrFail($revision->id)];
    }
}
