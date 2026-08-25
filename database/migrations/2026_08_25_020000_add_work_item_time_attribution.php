<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_time_entries', function (Blueprint $table): void {
            $table->foreignId('service_ticket_work_item_id')->nullable()->after('closeout_id');
            $table->foreign('service_ticket_work_item_id', 'vte_work_item_fk')->references('id')->on('service_ticket_work_items')->restrictOnDelete();
            $table->index(['visit_id', 'service_ticket_work_item_id'], 'vte_visit_work_item_idx');
        });

        Schema::create('visit_time_allocation_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_time_entry_id')->constrained(indexName: 'vtas_entry_fk')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->text('reason');
            $table->foreignId('allocated_by_id')->constrained('users', indexName: 'vtas_actor_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['visit_time_entry_id', 'sequence'], 'vtas_entry_sequence_unique');
            $table->index(['organization_id', 'created_at'], 'vtas_org_created_idx');
        });

        Schema::create('visit_time_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_time_allocation_set_id')->constrained(indexName: 'vta_set_fk')->cascadeOnDelete();
            $table->foreignId('service_ticket_work_item_id')->nullable();
            $table->foreign('service_ticket_work_item_id', 'vta_work_item_fk')->references('id')->on('service_ticket_work_items')->restrictOnDelete();
            $table->unsignedInteger('allocated_seconds');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['visit_time_allocation_set_id', 'service_ticket_work_item_id'], 'vta_set_item_unique');
            $table->unique(['visit_time_allocation_set_id', 'position'], 'vta_set_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_time_allocations');
        Schema::dropIfExists('visit_time_allocation_sets');
        Schema::table('visit_time_entries', function (Blueprint $table): void {
            $table->dropIndex('vte_visit_work_item_idx');
            if (DB::getDriverName() === 'sqlite') {
                $table->dropForeign(['service_ticket_work_item_id']);
            } else {
                $table->dropForeign('vte_work_item_fk');
            }
            $table->dropColumn('service_ticket_work_item_id');
        });
    }
};
