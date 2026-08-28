<?php

namespace App\Jobs;

use App\Models\ProposalPublication;
use App\Support\IncidentRecorder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RenderProposalPublicationPdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $publicationId) {}

    public function handle(IncidentRecorder $incidents): void
    {
        $publication = ProposalPublication::query()->with(['brandAsset', 'revision.document.opportunity.organization'])->findOrFail($this->publicationId);
        if ($publication->pdf_status === 'ready' && $publication->pdf_key) {
            return;
        }
        try {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $dompdf = new Dompdf($options);
            $logoDataUri = $this->logo($publication);
            $dompdf->loadHtml(view('proposals.pdf', compact('publication', 'logoDataUri'))->render());
            $dompdf->setPaper('letter');
            $dompdf->render();
            $contents = $dompdf->output();
            $disk = (string) config('commercial.proposal_disk', 'local');
            $key = 'commercial/proposals/pdfs/'.Str::uuid().'.pdf';
            if (! Storage::disk($disk)->put($key, $contents)) {
                throw new \RuntimeException('Private storage rejected Proposal PDF.');
            }
            $publication->update(['pdf_status' => 'ready', 'pdf_disk' => $disk, 'pdf_key' => $key, 'pdf_sha256' => hash('sha256', $contents), 'pdf_failure_code' => null]);
        } catch (Throwable $exception) {
            $publication->update(['pdf_status' => 'failed', 'pdf_failure_code' => class_basename($exception)]);
            $incidents->record($publication->revision->document->opportunity->organization, null, 'storage_failure', 'error', $publication, ['reason_code' => 'proposal_pdf_generation', 'publication_id' => $publication->id]);
            throw $exception;
        }
    }

    private function logo(ProposalPublication $publication): string
    {
        if ($publication->brandAsset) {
            $contents = Storage::disk($publication->brandAsset->storage_disk)->get($publication->brandAsset->storage_key);

            return 'data:'.$publication->brandAsset->mime_type.';base64,'.base64_encode($contents);
        }
        $contents = file_get_contents(public_path('images/newday-logo.png'));
        if ($contents === false) {
            throw new \RuntimeException('Static Proposal logo is unavailable.');
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }
}
