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
}
