<?php

use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AccessControlSeeder::class)->run();
    }

    public function down(): void {}
};
