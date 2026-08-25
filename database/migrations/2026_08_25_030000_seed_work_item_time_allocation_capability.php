<?php

use App\Models\Capability;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $capability = Capability::query()->updateOrCreate(
            ['key' => 'visit_time.allocate_work'],
            ['name' => 'Allocate Visit time across Work Items'],
        );
        Role::query()->where('key', 'super_admin')->first()?->capabilities()->syncWithoutDetaching([$capability->id]);
    }

    public function down(): void
    {
        $capability = Capability::query()->where('key', 'visit_time.allocate_work')->first();
        if ($capability) {
            $capability->roles()->detach();
            $capability->delete();
        }
    }
};
