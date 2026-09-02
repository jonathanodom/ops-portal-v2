<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closeouts', function (Blueprint $table): void {
            $table->text('result_summary')->nullable()->after('work_performed');
        });
    }

    public function down(): void
    {
        Schema::table('closeouts', function (Blueprint $table): void {
            $table->dropColumn('result_summary');
        });
    }
};
