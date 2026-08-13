<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->unsignedInteger('next_visit_number')->default(1)->after('ticket_number');
        });
        Schema::table('visits', function (Blueprint $table): void {
            $table->unsignedInteger('ticket_visit_number')->nullable()->after('service_ticket_id');
        });

        DB::table('service_tickets')->select('id')->orderBy('id')->each(function (object $ticket): void {
            $number = 1;
            DB::table('visits')->where('service_ticket_id', $ticket->id)
                ->orderBy('created_at')->orderBy('id')
                ->select('id')->each(function (object $visit) use (&$number): void {
                    DB::table('visits')->where('id', $visit->id)->update(['ticket_visit_number' => $number++]);
                });
            DB::table('service_tickets')->where('id', $ticket->id)->update(['next_visit_number' => $number]);
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->unsignedInteger('ticket_visit_number')->nullable(false)->change();
            $table->unique(['service_ticket_id', 'ticket_visit_number'], 'visits_ticket_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropUnique('visits_ticket_number_unique');
            $table->dropColumn('ticket_visit_number');
        });
        Schema::table('service_tickets', fn (Blueprint $table) => $table->dropColumn('next_visit_number'));
    }
};
