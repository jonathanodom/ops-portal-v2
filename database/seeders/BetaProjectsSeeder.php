<?php

namespace Database\Seeders;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use Illuminate\Database\Seeder;

final class BetaProjectsSeeder extends Seeder
{
    public function run(ProjectWorkflow $workflow, ServiceOperationsDirectory $operations): void
    {
        $organization = Organization::query()->where('slug', 'beta-validation')->firstOrFail();
        $actor = OrganizationMembership::query()->where('organization_id', $organization->id)->whereHas('roles', fn ($query) => $query->where('key', 'super_admin'))->with('user')->firstOrFail()->user;
        $customers = Customer::query()->where('organization_id', $organization->id)->where('display_name', 'like', 'BETA Load Customer%')->orderBy('id')->limit(2)->get();
        if ($customers->count() !== 2) {
            return;
        }
        $customers[0]->update(['display_name' => 'ABC Dental']);
        $customers[1]->update(['display_name' => 'Trip Hopper']);

        if (! Project::query()->where('organization_id', $organization->id)->where('name', 'ABC Dental — Network & AV Upgrade')->exists()) {
            $finite = $workflow->create($organization, $actor, ['name' => 'ABC Dental — Network & AV Upgrade', 'type' => 'installation_project', 'status' => 'active', 'customer_id' => $customers[0]->id, 'service_location_id' => $customers[0]->serviceLocations()->value('id'), 'owner_user_id' => $actor->id, 'start_on' => now($organization->timezone)->toDateString(), 'target_end_on' => now($organization->timezone)->addMonths(2)->toDateString(), 'summary' => 'Synthetic finite Project fixture.']);
            foreach (['Design', 'Cabling', 'Networking', 'AV'] as $index => $name) {
                $workflow->addWorkstream($finite, $actor, ['name' => $name, 'status' => $index === 0 ? 'active' : 'planned', 'sort_order' => $index]);
            }
            $workflow->addTask($finite, $actor, ['title' => 'Complete site design', 'status' => 'in_progress', 'priority' => 'high', 'due_on' => now($organization->timezone)->addDays(3)->toDateString()]);
            $workflow->addMilestone($finite, $actor, ['name' => 'Design Approved', 'status' => 'planned', 'target_on' => now($organization->timezone)->addWeeks(2)->toDateString()]);
        }

        if (! Project::query()->where('organization_id', $organization->id)->where('name', 'Trip Hopper — IT Support')->exists()) {
            $ongoing = $workflow->create($organization, $actor, ['name' => 'Trip Hopper — IT Support', 'type' => 'ongoing_support', 'status' => 'active', 'customer_id' => $customers[1]->id, 'owner_user_id' => $actor->id, 'summary' => 'Synthetic ongoing support fixture.']);
            foreach (['Microsoft 365', 'Networking', 'Workstations', 'Security', 'Vendor Coordination', 'Documentation'] as $index => $name) {
                $workflow->addWorkstream($ongoing, $actor, ['name' => $name, 'status' => $index < 2 ? 'active' : 'planned', 'sort_order' => $index]);
            }
            foreach ([
                ['Review tenant security', 'planned', 'normal', now($organization->timezone)->addDays(4)->toDateString(), null],
                ['Replace failed access point', 'blocked', 'high', now($organization->timezone)->subDay()->toDateString(), 'Waiting on vendor shipment.'],
                ['Document network topology', 'done', 'normal', now($organization->timezone)->subDays(2)->toDateString(), null],
            ] as [$title, $status, $priority, $due, $reason]) {
                $workflow->addTask($ongoing, $actor, ['title' => $title, 'status' => $status, 'priority' => $priority, 'due_on' => $due, 'blocked_reason' => $reason]);
            }
            $workflow->addMilestone($ongoing, $actor, ['name' => 'Quarterly Technology Review', 'status' => 'planned', 'target_on' => now($organization->timezone)->addWeeks(3)->toDateString()]);
            $workflow->addNote($ongoing, $actor, ['type' => 'customer_update', 'body' => 'Synthetic beta update for Projects validation.']);
            $ticket = $operations->forCustomer($organization, $customers[1]->id)->first();
            if ($ticket) {
                $workflow->linkTicket($ongoing, $ticket, $actor, true);
            }
        }
    }
}
