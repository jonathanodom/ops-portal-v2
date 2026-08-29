<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialRevisionMediaWorkflow;
use App\Domain\Commercial\QuoteWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CommercialContentBlock;
use App\Models\CommercialDocument;
use App\Models\CommercialRevision;
use App\Models\CommercialRevisionMedia;
use App\Models\CommercialTermsSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommercialRevisionContentController extends Controller
{
    public function terms(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'terms_set_id' => ['nullable', 'integer'], 'terms_body' => ['nullable', 'string', 'max:50000']]);
        $terms = filled($data['terms_set_id'] ?? null) ? CommercialTermsSet::query()->forOrganization($quote->organization_id)->where('active', true)->findOrFail($data['terms_set_id']) : null;
        $workflow->updateTerms($revision, $terms, $request->user(), (int) $data['content_version'], $data['terms_body'] ?? null);

        return back()->with('status', 'Proposal terms snapshot updated.');
    }

    public function block(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'content_block_id' => ['required', 'integer']]);
        $block = CommercialContentBlock::query()->forOrganization($quote->organization_id)->where('active', true)->findOrFail($data['content_block_id']);
        $workflow->addContentBlock($revision, $block, $request->user(), (int) $data['content_version']);

        return back()->with('status', 'Scope block copied into this revision.');
    }

    public function upload(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionMediaWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('commercial.proposal_media_max_kb')], 'caption' => ['nullable', 'string', 'max:500']]);
        $workflow->upload($revision, $request->user(), $data['file'], $data['caption'] ?? null);

        return back()->with('status', 'Private Proposal media uploaded.');
    }

    public function embed(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionMediaWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['embed_url' => ['required', 'url:https', 'max:2048', Rule::notIn(['https://localhost'])], 'caption' => ['nullable', 'string', 'max:500']]);
        $workflow->embed($revision, $request->user(), $data['embed_url'], $data['caption'] ?? null);

        return back()->with('status', 'HTTPS video reference added.');
    }

    public function show(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionMedia $media): StreamedResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('view', $quote);
        $media = $revision->media()->where('state', 'stored')->findOrFail($media->id);
        abort_unless($media->storage_disk && $media->storage_key && Storage::disk($media->storage_disk)->exists($media->storage_key), 404);

        return Storage::disk($media->storage_disk)->response($media->storage_key, $media->original_name, ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionMedia $media, CommercialRevisionMediaWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        abort_unless($media->commercial_revision_id === $revision->id, 404);
        $workflow->remove($revision, $media, $request->user());

        return back()->with('status', 'Proposal media removed from the Draft.');
    }

    private function scoped(Request $request, CommercialDocument $quote, CommercialRevision $revision): array
    {
        $quote = CommercialDocument::query()->forOrganization($request->attributes->get('organization')->id)->whereIn('document_type', ['quote', 'change_order'])->findOrFail($quote->id);

        return [$quote, $quote->revisions()->findOrFail($revision->id)];
    }
}
