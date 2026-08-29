<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\OpportunityAttachmentWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\OpportunityAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpportunityAttachmentController extends Controller
{
    public function store(Request $request, Opportunity $opportunity, OpportunityAttachmentWorkflow $workflow): RedirectResponse
    {
        $opportunity = $this->scoped($request, $opportunity);
        Gate::authorize('update', $opportunity);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('commercial.attachment_max_kb')], 'caption' => ['nullable', 'string', 'max:500']]);
        $workflow->store($opportunity, $request->user(), $data['file'], $data['caption'] ?? null);

        return back()->with('status', 'Opportunity file uploaded.');
    }

    public function show(Request $request, Opportunity $opportunity, OpportunityAttachment $attachment): StreamedResponse
    {
        [$opportunity, $attachment] = $this->stored($request, $opportunity, $attachment);
        Gate::authorize('view', $opportunity);

        return Storage::disk($attachment->storage_disk)->response($attachment->storage_key, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, Opportunity $opportunity, OpportunityAttachment $attachment, OpportunityAttachmentWorkflow $workflow): RedirectResponse
    {
        [$opportunity, $attachment] = $this->stored($request, $opportunity, $attachment);
        Gate::authorize('update', $opportunity);
        $workflow->remove($opportunity, $attachment, $request->user());

        return back()->with('status', 'Opportunity file removed.');
    }

    private function scoped(Request $request, Opportunity $opportunity): Opportunity
    {
        abort_unless($opportunity->organization_id === $request->attributes->get('organization')->id, 404);

        return $opportunity;
    }

    private function stored(Request $request, Opportunity $opportunity, OpportunityAttachment $attachment): array
    {
        $opportunity = $this->scoped($request, $opportunity);
        abort_unless($attachment->organization_id === $opportunity->organization_id && $attachment->opportunity_id === $opportunity->id && $attachment->state === 'stored', 404);

        return [$opportunity, $attachment];
    }
}
