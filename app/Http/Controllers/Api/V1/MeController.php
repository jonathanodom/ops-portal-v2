<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * First authenticated smoke endpoint for the JARVIS/Ops Portal boundary.
 * Confirms the service identity resolved and reports its granted scopes,
 * without exposing any business data.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $membership = $request->attributes->get('membership');

        return ApiResponse::success($request, [
            'actor' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'organization_id' => $membership?->organization_id,
            'scopes' => $token instanceof PersonalAccessToken ? ($token->abilities ?? []) : [],
        ]);
    }
}
