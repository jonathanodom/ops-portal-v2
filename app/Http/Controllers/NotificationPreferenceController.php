<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\NotificationPreferenceCatalog;
use App\Models\PortalNotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, NotificationPreferenceCatalog $catalog): View
    {
        $organization = $request->attributes->get('organization');
        $categories = $catalog->categories();
        $preferences = PortalNotificationPreference::query()
            ->forOrganization($organization->id)
            ->where('user_id', $request->user()->id)
            ->whereIn('event_key', collect($categories)->pluck('event_keys')->flatten())
            ->get()
            ->keyBy('event_key');

        $settings = collect($categories)->map(function (array $category) use ($preferences): array {
            $records = collect($category['event_keys'])->map(fn (string $key) => $preferences->get($key));

            return [
                ...$category,
                'email_enabled' => $records->every(fn ($record): bool => $record?->email_enabled !== false),
                'push_enabled' => $records->every(fn ($record): bool => $record?->push_enabled !== false),
            ];
        });

        return view('notifications.preferences', ['preferenceCategories' => $settings]);
    }

    public function update(Request $request, NotificationPreferenceCatalog $catalog): RedirectResponse
    {
        $categories = $catalog->categories();
        $rules = ['preferences' => ['required', 'array:'.implode(',', array_keys($categories))]];
        foreach (array_keys($categories) as $key) {
            $rules["preferences.{$key}"] = ['required', 'array:email,push'];
            $rules["preferences.{$key}.email"] = ['required', 'boolean'];
            $rules["preferences.{$key}.push"] = ['required', 'boolean'];
        }
        $validated = $request->validate($rules);
        $organization = $request->attributes->get('organization');

        DB::transaction(function () use ($request, $organization, $categories, $validated): void {
            foreach ($categories as $categoryKey => $category) {
                foreach ($category['event_keys'] as $eventKey) {
                    PortalNotificationPreference::query()->updateOrCreate(
                        [
                            'organization_id' => $organization->id,
                            'user_id' => $request->user()->id,
                            'event_key' => $eventKey,
                        ],
                        [
                            'in_app_enabled' => true,
                            'email_enabled' => (bool) $validated['preferences'][$categoryKey]['email'],
                            'push_enabled' => (bool) $validated['preferences'][$categoryKey]['push'],
                        ],
                    );
                }
            }
        });

        return back()->with('status', 'Notification preferences saved.');
    }
}
