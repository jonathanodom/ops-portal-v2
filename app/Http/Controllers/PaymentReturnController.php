<?php

namespace App\Http\Controllers;

use App\Models\PaymentAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PaymentReturnController extends Controller
{
    public function __invoke(Request $request, PaymentAttempt $attempt): View|RedirectResponse
    {
        $token = (string) $request->query('token');
        abort_unless(strlen($token) >= 40 && hash_equals($attempt->return_token_hash, hash('sha256', $token)), 404);
        $attempt->load('transactions.receipt');
        $transaction = $attempt->transactions->firstWhere('status', 'succeeded');
        if ($transaction?->receipt) {
            $receipt = $transaction->receipt;
            $public = Str::random(64);
            $receipt->update(['public_token_hash' => hash('sha256', $public), 'token_rotated_at' => now()]);

            return redirect()->route('payments.receipts.show', ['receipt' => $receipt, 'token' => $public]);
        }
        $message = match ($attempt->status) {
            'failed' => 'Payment was not completed.','expired' => 'This checkout expired.','succeeded' => 'Payment was confirmed.','unknown' => 'Payment status is uncertain and staff must reconcile it.',default => 'Payment confirmation is still processing.'
        };

        return view('payments.return', compact('attempt', 'message'));
    }
}
