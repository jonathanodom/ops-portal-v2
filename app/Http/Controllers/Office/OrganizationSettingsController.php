<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteUnusedOrganizationBrandAsset;
use App\Models\OrganizationBrandAsset;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrganizationSettingsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $membership = $request->attributes->get('membership');
        if ($membership->hasCapability('organization.settings.manage')) {
            return redirect()->route('office.settings.organization.edit');
        }
        if ($membership->hasCapability('billing.settings.manage')) {
            return redirect()->route('office.settings.billing.edit');
        }
        if ($membership->hasCapability('payments.view')) {
            return redirect()->route('office.settings.billing.edit');
        }
        if ($membership->hasCapability('opportunities.admin')) {
            return redirect()->route('office.settings.commercial.edit');
        }
        if ($membership->hasCapability('proposal.templates.manage')) {
            return redirect()->route('office.commercial-library.index');
        }

        abort(403);
    }

    public function edit(Request $request): View
    {
        $organization = $request->attributes->get('organization')->loadMissing(['currentFullLogo', 'currentMarkLogo']);
        $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, 'US');

        return view('office.settings.organization', compact('organization', 'timezones'));
    }

    public function update(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['required', 'timezone'],
            'confirm_timezone_change' => ['nullable', 'accepted'],
        ]);
        if ($data['timezone'] !== $organization->timezone && ! $request->boolean('confirm_timezone_change')) {
            $audit->record($organization, $request->user(), 'organization.settings_update_rejected', $organization, ['changed_fields' => ['timezone'], 'reason_code' => 'confirmation_required']);
            throw ValidationException::withMessages(['confirm_timezone_change' => 'Confirm the effect of changing the organization timezone.']);
        }
        unset($data['confirm_timezone_change']);
        $data['state'] = isset($data['state']) ? strtoupper($data['state']) : null;
        $changed = collect($data)->filter(fn ($value, $field) => $organization->{$field} !== $value)->keys()->all();
        $organization->update($data + ['country_code' => 'US']);
        $audit->record($organization, $request->user(), 'organization.settings_updated', $organization, ['changed_fields' => $changed]);

        return back()->with('status', 'Organization settings saved.');
    }

    public function upload(Request $request, string $variant, AuditRecorder $audit): RedirectResponse
    {
        abort_unless(in_array($variant, ['full', 'mark'], true), 404);
        $data = $request->validate([
            'logo' => ['required', 'file', 'max:'.config('organization.max_logo_kb'), 'mimetypes:image/png,image/jpeg,image/webp', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
        ]);
        $contents = $data['logo']->get();
        if (str_contains($contents, 'acTL') || str_contains($contents, 'ANIM')) {
            throw ValidationException::withMessages(['logo' => 'Animated logo files are not supported.']);
        }
        $dimensions = getimagesize($data['logo']->getPathname());
        if ($dimensions === false) {
            throw ValidationException::withMessages(['logo' => 'The uploaded logo is not a valid image.']);
        }
        $mime = $dimensions['mime'] ?? $data['logo']->getMimeType();
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $extension) {
            throw ValidationException::withMessages(['logo' => 'Use a PNG, JPEG, or WebP logo.']);
        }
        $disk = (string) config('organization.branding_disk', 'local');
        $key = 'organization-branding/'.Str::uuid().'.'.$extension;
        if (! Storage::disk($disk)->put($key, $contents)) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be stored. Retry the upload.']);
        }
        try {
            [$asset, $oldId] = DB::transaction(function () use ($request, $variant, $disk, $key, $mime, $dimensions, $data): array {
                $organization = $request->attributes->get('organization')->newQuery()->lockForUpdate()->findOrFail($request->attributes->get('organization')->id);
                $pointer = $variant === 'full' ? 'full_logo_asset_id' : 'mark_logo_asset_id';
                $oldId = $organization->{$pointer};
                $asset = OrganizationBrandAsset::query()->create([
                    'organization_id' => $organization->id, 'variant' => $variant, 'storage_disk' => $disk, 'storage_key' => $key,
                    'mime_type' => $mime, 'byte_size' => $data['logo']->getSize(), 'width' => $dimensions[0], 'height' => $dimensions[1],
                    'uploaded_by_id' => $request->user()->id,
                ]);
                $organization->update([$pointer => $asset->id]);

                return [$asset, $oldId];
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($key);
            throw $exception;
        }
        $audit->record($request->attributes->get('organization'), $request->user(), 'organization.brand_asset_uploaded', $asset, [
            'asset_id' => $asset->id, 'variant' => $variant, 'mime_type' => $mime, 'byte_size' => $asset->byte_size, 'width' => $asset->width, 'height' => $asset->height,
        ]);
        if ($oldId) {
            DeleteUnusedOrganizationBrandAsset::dispatch($oldId)->afterCommit();
        }

        return back()->with('status', ucfirst($variant).' logo saved.');
    }

    public function remove(Request $request, string $variant, AuditRecorder $audit): RedirectResponse
    {
        abort_unless(in_array($variant, ['full', 'mark'], true), 404);
        $pointer = $variant === 'full' ? 'full_logo_asset_id' : 'mark_logo_asset_id';
        [$organization, $oldId] = DB::transaction(function () use ($request, $pointer): array {
            $organization = $request->attributes->get('organization')->newQuery()->lockForUpdate()->findOrFail($request->attributes->get('organization')->id);
            $oldId = $organization->{$pointer};
            if ($oldId) {
                $organization->update([$pointer => null]);
            }

            return [$organization, $oldId];
        });
        if ($oldId) {
            $audit->record($organization, $request->user(), 'organization.brand_asset_reset', $organization, ['asset_id' => $oldId, 'variant' => $variant, 'changed_fields' => [$pointer]]);
            DeleteUnusedOrganizationBrandAsset::dispatch($oldId)->afterCommit();
        }

        return back()->with('status', ucfirst($variant).' logo reset to the default.');
    }

    public function asset(Request $request, string $variant): StreamedResponse
    {
        abort_unless(in_array($variant, ['full', 'mark'], true), 404);
        $organization = $request->attributes->get('organization');
        $asset = $variant === 'mark' ? ($organization->currentMarkLogo ?: $organization->currentFullLogo) : $organization->currentFullLogo;
        abort_unless($asset && Storage::disk($asset->storage_disk)->exists($asset->storage_key), 404);

        return Storage::disk($asset->storage_disk)->response($asset->storage_key, null, [
            'Content-Type' => $asset->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
