<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PhaseUpdateWorkflowTest extends TestCase
{
    public function test_phase_update_is_incremental_and_non_destructive(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $commands = implode("\n", $composer['scripts']['phase:update']);

        $this->assertStringContainsString('artisan migrate --force', $commands);
        $this->assertStringContainsString('artisan db:seed --force', $commands);
        $this->assertStringNotContainsString('migrate:fresh', $commands);
        $this->assertStringNotContainsString('db:wipe', $commands);
        $this->assertStringNotContainsString('down -v', $commands);
    }
}
