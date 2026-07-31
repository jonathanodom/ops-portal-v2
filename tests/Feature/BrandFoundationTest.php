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
}
