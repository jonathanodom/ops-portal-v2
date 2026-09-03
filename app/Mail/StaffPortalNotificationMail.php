<?php

namespace App\Mail;

use App\Models\PortalNotificationEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class StaffPortalNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PortalNotificationEvent $event,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->event->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff.portal-notification',
            text: 'emails.staff.portal-notification-text',
            with: [
                'title' => $this->event->title,
                'notificationMessage' => $this->event->body,
                'category' => $this->event->category,
                'actionUrl' => $this->event->action_url ? url($this->event->action_url) : null,
                'actionLabel' => data_get($this->event->metadata, 'action_label', 'View in Ops Portal'),
                'recipientName' => $this->recipient->name,
            ],
        );
    }
}
