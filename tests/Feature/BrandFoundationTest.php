<?php

namespace Tests\Feature;

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
}
