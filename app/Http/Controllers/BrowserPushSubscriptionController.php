<?php

namespace App\Http\Controllers;

use App\Models\BrowserPushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BrowserPushSubscriptionController extends Controller
{
    public function configuration(Request $request): JsonResponse
    {
        $configured = filled(config('services.web_push.vapid_public_key'))
            && filled(config('services.web_push.vapid_private_key'))
            && filled(config('services.web_push.vapid_subject'));

        return response()->json([
            'configured' => $configured,
            'public_key' => $configured ? config('services.web_push.vapid_public_key') : null,
            'active_subscriptions' => BrowserPushSubscription::query()
                ->forOrganization($request->attributes->get('organization')->id)
                ->where('user_id', $request->user()->id)
                ->whereNull('disabled_at')
                ->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:http,https'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);
        if (! str_starts_with($validated['endpoint'], 'https://')) {
            throw ValidationException::withMessages(['endpoint' => 'The push endpoint must use HTTPS.']);
        }

        $organization = $request->attributes->get('organization');
        $endpointHash = hash('sha256', $validated['endpoint']);
        $subscription = DB::transaction(function () use ($request, $organization, $validated, $endpointHash): BrowserPushSubscription {
            $existing = BrowserPushSubscription::query()
                ->forOrganization($organization->id)
                ->where('endpoint_sha256', $endpointHash)
                ->lockForUpdate()
                ->first();
            if ($existing && $existing->user_id !== $request->user()->id) {
                abort(409, 'This browser subscription belongs to another user.');
            }

            $subscription = $existing ?? new BrowserPushSubscription([
                'organization_id' => $organization->id,
                'user_id' => $request->user()->id,
                'endpoint_sha256' => $endpointHash,
            ]);
            $subscription->fill([
                'endpoint' => $validated['endpoint'],
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'last_registered_at' => now(),
                'disabled_at' => null,
            ])->save();

            return $subscription;
        });

        return response()->json(['status' => 'subscribed', 'subscription_id' => $subscription->id]);
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $organization = $request->attributes->get('organization');

        BrowserPushSubscription::query()
            ->forOrganization($organization->id)
            ->where('user_id', $request->user()->id)
            ->where('endpoint_sha256', hash('sha256', $validated['endpoint']))
            ->delete();

        return response()->noContent();
    }
}
