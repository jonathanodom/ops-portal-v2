<?php

namespace App\Http\Controllers\Office;

use App\Domain\Projects\Attachments\ProjectAttachmentType;
use App\Domain\Projects\Attachments\ProjectAttachmentWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectAttachmentController extends Controller
{
    public function store(Request $request, Project $project, ProjectAttachmentType $types, ProjectAttachmentWorkflow $workflow): RedirectResponse
    {
        $project = $this->project($request, $project);
        Gate::authorize('update', $project);
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(config('project_attachments.categories')))],
            'file' => ['required', 'file', 'max:'.config('project_attachments.max_file_kb')],
            'caption' => ['nullable', 'string', 'max:500'],
        ]);
        $type = $types->inspect($data['file']);
        $workflow->store($project, $request->user(), $data['file'], $data['category'], $data['caption'] ?? null, $type);

        return back()->with('status', 'Project attachment uploaded.');
    }

    public function show(Request $request, Project $project, string $attachment): StreamedResponse
    {
        [$project, $attachment] = $this->stored($request, $project, $attachment);
        Gate::authorize('view', $project);

        return Storage::disk($attachment->storage_disk)->response($attachment->storage_key, $attachment->original_name, $this->headers($attachment));
    }

    public function download(Request $request, Project $project, string $attachment): StreamedResponse
    {
        [$project, $attachment] = $this->stored($request, $project, $attachment);
        Gate::authorize('view', $project);

        return Storage::disk($attachment->storage_disk)->download($attachment->storage_key, $attachment->original_name, $this->headers($attachment));
    }

    public function destroy(Request $request, Project $project, string $attachment, ProjectAttachmentWorkflow $workflow): RedirectResponse
    {
        $project = $this->project($request, $project);
        Gate::authorize('update', $project);
        $attachment = ProjectAttachment::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where('state', 'stored')
            ->findOrFail($attachment);
        $workflow->remove($project, $attachment, $request->user());

        return back()->with('status', 'Project attachment removed.');
    }

    private function project(Request $request, Project $project): Project
    {
        abort_unless($project->organization_id === $request->attributes->get('organization')->id, 404);

        return $project;
    }

    /** @return array{Project, ProjectAttachment} */
    private function stored(Request $request, Project $project, string $attachment): array
    {
        $project = $this->project($request, $project);
        $attachment = ProjectAttachment::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where('state', 'stored')
            ->findOrFail($attachment);

        return [$project, $attachment];
    }

    /** @return array<string, string> */
    private function headers(ProjectAttachment $attachment): array
    {
        return [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
