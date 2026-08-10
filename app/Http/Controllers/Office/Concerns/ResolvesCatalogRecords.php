<?php

namespace App\Http\Controllers\Office\Concerns;

use App\Support\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesCatalogRecords
{
    /** @template T of Model @param class-string<T> $modelClass @return T */
    protected function catalogRecord(Request $request, string $modelClass, string $id, string $recordType): Model
    {
        $organization = $request->attributes->get('organization');
        $record = $modelClass::query()->where('organization_id', $organization->id)->find($id);
        if (! $record && $modelClass::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                'record_type' => $recordType,
                'record_id' => (int) $id,
            ]);
        }

        return $record ?? abort(404);
    }
}
