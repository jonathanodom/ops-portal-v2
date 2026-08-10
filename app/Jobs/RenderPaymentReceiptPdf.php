<?php

namespace App\Jobs;

use App\Models\PaymentReceipt;
use App\Support\IncidentRecorder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RenderPaymentReceiptPdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $receiptId) {}

    public function handle(IncidentRecorder $incidents): void
    {
        $receipt = PaymentReceipt::query()->with(['invoice.organization', 'invoice.sellerLogoAsset', 'transaction'])->findOrFail($this->receiptId);
        if ($receipt->pdf_status === 'ready' && $receipt->pdf_key) {
            return;
        }
        try {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $dompdf = new Dompdf($options);
            $logoDataUri = $this->logoDataUri($receipt);
            $dompdf->loadHtml(view('payments.receipt-pdf', compact('receipt', 'logoDataUri'))->render());
            $dompdf->setPaper('letter');
            $dompdf->render();
            $contents = $dompdf->output();
            $disk = config('payments.private_disk', 'local');
            $key = 'payment-receipts/'.Str::uuid().'.pdf';
            if (! Storage::disk($disk)->put($key, $contents)) {
                throw new \RuntimeException('receipt_storage_failed');
            }
            $receipt->update(['pdf_status' => 'ready', 'pdf_disk' => $disk, 'pdf_key' => $key, 'pdf_sha256' => hash('sha256', $contents), 'pdf_failure_code' => null, 'generated_at' => now()]);
        } catch (Throwable $exception) {
            $receipt->update(['pdf_status' => 'failed', 'pdf_failure_code' => 'receipt_pdf_failed']);
            $incidents->record($receipt->invoice->organization, null, 'payment_receipt_failure', 'error', $receipt, ['reason_code' => 'receipt_pdf_failed']);
            throw $exception;
        }
    }

    private function logoDataUri(PaymentReceipt $receipt): string
    {
        if ($receipt->invoice->sellerLogoAsset) {
            $asset = $receipt->invoice->sellerLogoAsset;

            return 'data:'.$asset->mime_type.';base64,'.base64_encode(Storage::disk($asset->storage_disk)->get($asset->storage_key));
        }
        $contents = file_get_contents(public_path('images/newday-logo.png'));
        if ($contents === false) {
            throw new \RuntimeException('receipt_logo_unavailable');
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }
}
