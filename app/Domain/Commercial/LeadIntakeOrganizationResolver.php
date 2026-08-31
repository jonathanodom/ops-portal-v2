<?php

namespace App\Domain\Commercial;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class LeadIntakeOrganizationResolver
{
    public function resolve(): Organization
    {
        $slug = trim((string) config('lead-intake.organization_slug'));

        if ($slug !== '') {
            $organization = Organization::query()
                ->where('slug', $slug)
                ->where('active', true)
                ->first();

            return $organization ?? $this->unavailable('configured_organization_unavailable');
        }

        $organizations = Organization::query()
            ->where('active', true)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $organizations->count() === 1
            ? $organizations->first()
            : $this->unavailable('active_organization_not_unique', ['active_organization_count' => $organizations->count()]);
    }

    private function unavailable(string $reasonCode, array $context = []): never
    {
        Log::warning('Public lead intake organization resolution failed.', [
            'reason_code' => $reasonCode,
            ...$context,
        ]);

        throw new ServiceUnavailableHttpException(null, 'Lead intake is temporarily unavailable.');
    }
}
