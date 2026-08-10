<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Support\IncidentRecorder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RenderInvoicePdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $invoiceId) {}

    public function handle(IncidentRecorder $incidents): void
    {
        $invoice = Invoice::query()->with(['organization', 'serviceTicket', 'lines'])->findOrFail($this->invoiceId);
        if ($invoice->status !== 'issued' || ($invoice->pdf_status === 'ready' && $invoice->pdf_key)) {
            return;
        }
        try {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml(view('invoices.pdf', compact('invoice'))->render());
            $dompdf->setPaper('letter');
            $dompdf->render();
            $contents = $dompdf->output();
            $disk = config('filesystems.private', 'local');
            $key = 'invoices/'.Str::uuid().'.pdf';
            if (! Storage::disk($disk)->put($key, $contents)) {
                throw new \RuntimeException('Private storage rejected invoice PDF.');
            }
            $invoice->update(['pdf_status' => 'ready', 'pdf_disk' => $disk, 'pdf_key' => $key, 'pdf_sha256' => hash('sha256', $contents), 'pdf_failure_code' => null]);
        } catch (Throwable $exception) {
            $invoice->update(['pdf_status' => 'failed', 'pdf_failure_code' => class_basename($exception)]);
            $incidents->record($invoice->organization, null, 'storage_failure', 'error', $invoice, ['reason_code' => 'invoice_pdf_generation', 'invoice_id' => $invoice->id]);
            throw $exception;
        }
    }
}
