<?php

namespace Database\Seeders;

use App\Domain\VisitCreator;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BetaVolumeSeeder extends Seeder
{
    public function run(VisitCreator $visitCreator): void
    {
        $organization = Organization::query()->where('slug', 'beta-validation')->firstOrFail();
        $admin = OrganizationMembership::query()->where('organization_id', $organization->id)->whereHas('roles', fn ($query) => $query->where('key', 'super_admin'))->firstOrFail();
        $technician = OrganizationMembership::query()->where('organization_id', $organization->id)->whereHas('roles', fn ($query) => $query->where('key', 'technician'))->firstOrFail();
        $customers = collect();
        $locations = collect();

        for ($i = 1; $i <= 247; $i++) {
            $customer = Customer::query()->create([
                'organization_id' => $organization->id, 'type' => $i % 4 === 0 ? 'individual' : 'business',
                'display_name' => sprintf('BETA Load Customer %03d', $i), 'phone' => sprintf('(512) 555-%04d', $i),
                'phone_normalized' => sprintf('512555%04d', $i), 'email' => sprintf('load%03d@example.test', $i),
                'status' => 'active', 'created_by_id' => $admin->user_id, 'updated_by_id' => $admin->user_id,
            ]);
            $customers->push($customer);
            $locationCount = $i <= 150 ? 2 : 1;
            for ($j = 1; $j <= $locationCount; $j++) {
                $locations->push(ServiceLocation::query()->create([
                    'organization_id' => $organization->id, 'customer_id' => $customer->id,
                    'name' => "Load Site {$i}-{$j}", 'address_line_1' => (1000 + $i).' Performance Rd',
                    'city' => $i % 3 === 0 ? 'Round Rock' : 'Austin', 'state' => 'TX',
                    'postal_code' => sprintf('78%03d', $i % 1000), 'timezone' => 'America/Chicago',
                    'is_primary' => $j === 1, 'active' => true,
                ]));
            }
        }

        $visits = collect();
        for ($i = 1; $i <= 497; $i++) {
            $customer = $customers[($i - 1) % $customers->count()];
            $location = $locations->firstWhere('customer_id', $customer->id);
            $ticket = ServiceTicket::query()->create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id,
                'ticket_number' => sprintf('NDT-ST-2026-%04d', 10000 + $i), 'title' => sprintf('BETA load service request %03d', $i),
                'priority' => ['low', 'normal', 'normal', 'high'][$i % 4], 'source' => 'internal', 'status' => 'open',
                'created_by_id' => $admin->user_id, 'updated_by_id' => $admin->user_id,
            ]);
            $visitCount = $i <= 500 - 497 ? 3 : 2;
            for ($j = 1; $j <= $visitCount; $j++) {
                // Keep a representative near-term queue without burying the three
                // hands-on scenarios beneath the performance volume fixture.
                $scheduledDay = $i <= 12
                    ? (($i + $j) % 7) + 1
                    : 30 + (($i + $j) % 335);
                $visit = $visitCreator->create($ticket, [
                    'service_location_id' => $location->id,
                    'status' => 'assigned', 'timezone' => 'America/Chicago',
                    'scheduled_start_at' => now()->startOfDay()->addDays($scheduledDay)->addHours(13 + ($i % 5)),
                    'scheduled_end_at' => now()->startOfDay()->addDays($scheduledDay)->addHours(14 + ($i % 5)),
                    'scheduled_by_id' => $admin->user_id, 'created_by_id' => $admin->user_id, 'updated_by_id' => $admin->user_id,
                ]);
                VisitAssignment::query()->create([
                    'organization_id' => $organization->id, 'visit_id' => $visit->id,
                    'organization_membership_id' => $technician->id, 'is_lead' => true, 'assigned_by_id' => $admin->user_id,
                ]);
                $visits->push($visit);
            }
        }

        foreach ($visits->take(200) as $index => $visit) {
            $closeout = Closeout::query()->create([
                'organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1,
                'status' => 'submitted', 'content_version' => 2, 'outcome' => 'resolved',
                'diagnosis' => 'Synthetic beta diagnosis.', 'work_performed' => 'Synthetic beta work performed.',
                'representative_name' => 'Beta Representative', 'acknowledged_at' => now()->subMinutes($index),
                'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $technician->user_id,
                'submitted_at' => now()->subMinutes($index), 'last_saved_by_id' => $technician->user_id,
            ]);
            $visit->update(['current_closeout_id' => $closeout->id, 'status' => 'pending_closeout']);
            $mediaCount = $index < 100 ? 3 : 2;
            for ($media = 1; $media <= $mediaCount; $media++) {
                VisitMedia::query()->create([
                    'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
                    'uploader_id' => $technician->user_id, 'storage_disk' => 'local',
                    'storage_key' => "beta-media/{$closeout->id}/{$media}.jpg", 'mime_type' => 'image/jpeg',
                    'byte_size' => 68, 'category' => $media === 1 ? 'after' : 'other', 'state' => 'stored',
                ]);
                $bytes = $index === 0 && $media === 1
                    ? str_repeat('B', 5 * 1024 * 1024)
                    : base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q==');
                Storage::disk('local')->put("beta-media/{$closeout->id}/{$media}.jpg", $bytes);
            }
        }
    }
}
