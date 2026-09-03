<?php

namespace App\Http\Controllers;

use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $this->query($request)
                ->with('event')
                ->orderByDesc(PortalNotificationEvent::query()
                    ->select('occurred_at')
                    ->whereColumn('portal_notification_events.id', 'portal_notification_recipients.portal_notification_event_id'))
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function recent(Request $request): JsonResponse
    {
        $notifications = $this->query($request)
            ->with('event')
            ->orderByDesc(PortalNotificationEvent::query()
                ->select('occurred_at')
                ->whereColumn('portal_notification_events.id', 'portal_notification_recipients.portal_notification_event_id'))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (PortalNotificationRecipient $notification): array => $this->projection($notification));

        return response()->json(['notifications' => $notifications])->header('Cache-Control', 'no-store');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->query($request)->whereNull('read_at')->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, int $notification): JsonResponse|RedirectResponse
    {
        $recipient = $this->find($request, $notification);
        $this->markRead($recipient);

        if ($request->expectsJson()) {
            return response()->json(['read_at' => $recipient->read_at?->toIso8601String()]);
        }

        return back()->with('status', 'Notification marked as read.');
    }

    public function open(Request $request, int $notification): JsonResponse|RedirectResponse
    {
        $recipient = $this->find($request, $notification);
        $this->markRead($recipient);
        $destination = $this->safeDestination($recipient->event->action_url);

        if ($request->expectsJson()) {
            return response()->json(['destination' => $destination]);
        }

        return redirect()->to($destination);
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        $readAt = now();
        $updated = $this->query($request)->whereNull('read_at')->update(['read_at' => $readAt]);

        if ($request->expectsJson()) {
            return response()->json(['updated' => $updated, 'read_at' => $readAt->toIso8601String()]);
        }

        return back()->with('status', 'All notifications marked as read.');
    }

    private function query(Request $request): Builder
    {
        $organization = $request->attributes->get('organization');

        return PortalNotificationRecipient::query()
            ->forOrganization($organization->id)
            ->where('user_id', $request->user()->id)
            ->whereJsonContains('channels', 'in_app');
    }

    private function find(Request $request, int $notification): PortalNotificationRecipient
    {
        return $this->query($request)->with('event')->findOrFail($notification);
    }

    private function markRead(PortalNotificationRecipient $recipient): void
    {
        if ($recipient->read_at === null) {
            $recipient->forceFill(['read_at' => now()])->save();
        }
    }

    /** @return array<string, mixed> */
    private function projection(PortalNotificationRecipient $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->event->title,
            'message' => $notification->event->body,
            'category' => $notification->event->category,
            'priority' => $notification->event->priority,
            'occurred_at' => $notification->event->occurred_at->toIso8601String(),
            'occurred_human' => $notification->event->occurred_at->diffForHumans(),
            'unread' => $notification->read_at === null,
            'open_url' => route('notifications.open', $notification),
        ];
    }

    private function safeDestination(?string $destination): string
    {
        if (! is_string($destination)
            || ! str_starts_with($destination, '/')
            || str_starts_with($destination, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $destination) === 1) {
            return route('notifications.index');
        }

        $parts = parse_url($destination);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return route('notifications.index');
        }

        return $destination;
    }
}
