<?php

namespace App\Http\Controllers;

use App\Models\PaymentReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReceiptController extends Controller
{
    public function show(Request $request, PaymentReceipt $receipt, string $token): Response
    {
        $this->authorizeToken($receipt, $token);
        $receipt->load(['invoice.organization', 'invoice.sellerLogoAsset', 'transaction']);

        return response()->view('payments.receipt', compact('receipt', 'token'))->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function pdf(Request $request, PaymentReceipt $receipt, string $token): StreamedResponse
    {
        $this->authorizeToken($receipt, $token);
        abort_unless($receipt->pdf_status === 'ready' && $receipt->pdf_key, 404);

        return Storage::disk($receipt->pdf_disk)->download($receipt->pdf_key, 'receipt-'.$receipt->invoice->invoice_number.'.pdf', ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }

    public function brand(Request $request, PaymentReceipt $receipt, string $token): StreamedResponse
    {
        $this->authorizeToken($receipt, $token);
        $receipt->load('invoice.sellerLogoAsset');
        $asset = $receipt->invoice->sellerLogoAsset;
        abort_unless($asset && Storage::disk($asset->storage_disk)->exists($asset->storage_key), 404);

        return Storage::disk($asset->storage_disk)->response($asset->storage_key, null, ['Content-Type' => $asset->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }

    private function authorizeToken(PaymentReceipt $receipt, string $token): void
    {
        abort_unless($receipt->public_token_hash && hash_equals($receipt->public_token_hash, hash('sha256', $token)), 404);
    }
}
