<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BrandFoundationTest extends TestCase
{
    public function test_login_uses_newday_brand_and_accessible_form_contracts(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('NewDay Ops Portal')
            ->assertSee('newday-logo.png')
            ->assertSee('autocomplete="username"', false)
            ->assertSee('Forgot password?');
    }

    public function test_brand_tokens_are_declared_in_tailwind_source(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--color-brand-blue: #1d80f7', $css);
        $this->assertStringContainsString('--color-brand-orange: #f7941d', $css);
        $this->assertStringContainsString('min-h-11', $css);
    }

    public function test_customer_interfaces_keep_accessible_operational_controls(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $office = file_get_contents(resource_path('views/office/customers/index.blade.php'));
        $field = file_get_contents(resource_path('views/components/layouts/field.blade.php'));

        $this->assertStringContainsString('min-h-11', $css);
        $this->assertStringContainsString('status-active', $css);
        $this->assertStringContainsString('label for="search"', $office);
        $this->assertStringContainsString("request()->routeIs('field.customers.*')", $field);
        $this->assertStringContainsString('min-h-14', $field);
    }

    public function test_operational_timestamps_render_in_the_requested_local_timezone(): void
    {
        $html = Blade::render(
            '<x-local-time :value="$value" timezone="America/Chicago" />',
            ['value' => Carbon::parse('2026-07-31 18:00:00', 'UTC')],
        );

        $this->assertStringContainsString('Jul 31, 2026 1:00 PM CDT', $html);
        $this->assertStringContainsString('datetime="2026-07-31T18:00:00+00:00"', $html);
        $this->assertStringContainsString('title="America/Chicago"', $html);

        $field = file_get_contents(resource_path('views/field/visits/show.blade.php'));
        $office = file_get_contents(resource_path('views/office/service-tickets/show.blade.php'));

        $this->assertStringContainsString(':timezone="$visit->timezone"', $field);
        $this->assertStringContainsString(':timezone="$activeOrganization->timezone"', $office);
    }

    public function test_field_closeout_form_has_clear_semantic_groups_and_customer_friendly_labels(): void
    {
        $field = file_get_contents(resource_path('views/field/visits/show.blade.php'));

        foreach (['Visit outcome', 'Work summary', 'Return trip or hold details', 'Customer unavailable', 'Customer acknowledgment', 'No-photo fallback'] as $section) {
            $this->assertStringContainsString($section, $field);
        }

        $this->assertStringContainsString('Customer or point-of-contact name', $field);
        $this->assertStringContainsString('aria-invalid="true"', $field);
        $this->assertStringContainsString('<x-field-error', $field);
        $this->assertStringContainsString('Couldn’t obtain acknowledgment?', $field);
        $this->assertStringNotContainsString('Representative name', $field);
    }

    public function test_field_workspace_exposes_clear_connectivity_navigation_and_outcome_contracts(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/field.blade.php'));
        $field = file_get_contents(resource_path('views/field/visits/show.blade.php'));
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('data-connectivity-status', $layout);
        $this->assertStringContainsString('data-connectivity-label', $layout);
        $this->assertStringContainsString('Writes and uploads are disabled', $layout);
        $this->assertStringContainsString('aria-label="Visit workspace sections"', $field);
        foreach (['#visit-time', '#visit-closeout', '#visit-photos', '#visit-parts'] as $anchor) {
            $this->assertStringContainsString($anchor, $field);
        }
        $this->assertStringContainsString('data-outcome-selector', $field);
        $this->assertStringContainsString('data-selected-outcome', $field);
        $this->assertStringContainsString('Saved successfully', $field);
        $this->assertStringContainsString("navigator.onLine ? 'Online' : 'Offline'", $script);
    }
}
