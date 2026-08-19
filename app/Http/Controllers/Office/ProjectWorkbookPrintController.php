<?php

namespace App\Http\Controllers\Office;

use App\Domain\Projects\Queries\ProjectWorkbookQuery;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class ProjectWorkbookPrintController extends Controller
{
    public function __invoke(Request $request, Project $project, ProjectWorkbookQuery $query): Response
    {
        $organization = $request->attributes->get('organization');
        abort_unless($project->organization_id === $organization->id, 404);
        Gate::authorize('view', $project);

        return response()->view('office.projects.workbook-print', $query->build($organization, $project))->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
