<?php

namespace App\Domain\Commercial;

use App\Models\ProposalEmailVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class ProposalEmailVerificationWorkflow
{
    public function request(ProposalAccess $access, Request $request, string $email): ProposalEmailVerification
    {
        $normalized = strtolower(trim($email));
        $rateKey = 'proposal-verify-request:'.$access->tokenHash.':'.hash('sha256', $normalized).':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many verification requests. Try again later.']);
        }
        RateLimiter::hit($rateKey, 900);
        $code = (string) random_int(100000, 999999);
        $verification = ProposalEmailVerification::query()->create([
            'organization_id' => $access->publication->organization_id, 'proposal_publication_id' => $access->publication->id,
            'proposal_recipient_id' => $access->recipientId(), 'proposal_share_link_id' => $access->shareLinkId(),
            'email' => $normalized, 'email_hash' => hash('sha256', $normalized), 'challenge_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('commercial.verification_minutes', 15)),
        ]);
        Mail::raw("Your NewDay Proposal verification code is {$code}. It expires in ".config('commercial.verification_minutes', 15).' minutes.', function ($message) use ($normalized): void {
            $message->to($normalized)->subject('Verify your Proposal acceptance email');
        });

        return $verification;
    }

    public function verify(ProposalAccess $access, Request $request, ProposalEmailVerification $verification, string $code): ProposalEmailVerification
    {
        $verification = ProposalEmailVerification::query()->where('organization_id', $access->publication->organization_id)
            ->where('proposal_publication_id', $access->publication->id)->findOrFail($verification->id);
        abort_unless($verification->proposal_recipient_id === $access->recipientId() && $verification->proposal_share_link_id === $access->shareLinkId(), 404);
        $rateKey = 'proposal-verify-code:'.$verification->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 8) || $verification->attempt_count >= 8) {
            $verification->update(['status' => 'locked']);
            throw ValidationException::withMessages(['verification_code' => 'This verification challenge is locked. Request a new code.']);
        }
        RateLimiter::hit($rateKey, 900);
        if ($verification->status !== 'pending' || $verification->expires_at->isPast()) {
            $verification->update(['status' => 'expired']);
            throw ValidationException::withMessages(['verification_code' => 'This verification code has expired.']);
        }
        if (! Hash::check($code, $verification->challenge_hash)) {
            $verification->increment('attempt_count');
            throw ValidationException::withMessages(['verification_code' => 'The verification code is invalid.']);
        }
        $verification->update(['status' => 'verified', 'verified_at' => now()]);

        return $verification->fresh();
    }

    public function assertVerified(ProposalAccess $access, string $email, ?int $verificationId): void
    {
        $normalized = strtolower(trim($email));
        if ($access->recipient && hash_equals(hash('sha256', strtolower(trim($access->recipient->email))), hash('sha256', $normalized))) {
            return;
        }
        if (! $verificationId) {
            throw ValidationException::withMessages(['signer_email' => 'Verify this email address before accepting.']);
        }
        $verified = ProposalEmailVerification::query()->whereKey($verificationId)->where('proposal_publication_id', $access->publication->id)
            ->where('proposal_recipient_id', $access->recipientId())->where('proposal_share_link_id', $access->shareLinkId())
            ->where('email_hash', hash('sha256', $normalized))->where('status', 'verified')->whereNotNull('verified_at')->first();
        if (! $verified) {
            throw ValidationException::withMessages(['signer_email' => 'Verify this email address before accepting.']);
        }
    }
}
