<?php

namespace App\Http\Controllers;

use App\Models\CloseoutAcknowledgmentSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CloseoutAcknowledgmentSignatureController extends Controller
{
    public function __invoke(Request $request, string $signature): StreamedResponse
    {
        $membership = $request->attributes->get('membership');
        $signature = CloseoutAcknowledgmentSignature::query()
            ->where('organization_id', $membership->organization_id)
            ->with('closeout.visit')
            ->findOrFail($signature);
        $visit = $signature->closeout->visit;
        $office = $membership->hasCapability('experience.office.access') && $membership->hasCapability('closeouts.inspect');
        $field = $membership->hasCapability('experience.field.access') && Gate::allows('view', $visit);
        abort_unless($office || $field, 403);
        abort_unless(Storage::disk($signature->storage_disk)->exists($signature->storage_key), 404);

        return Storage::disk($signature->storage_disk)->response($signature->storage_key, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
