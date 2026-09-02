<?php

namespace App\Domain\Notifications;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class PortalNotificationPayload
{
    public const CHANNELS = ['in_app', 'email', 'push'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public CarbonImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $defaultChannels
     * @param  list<string>  $requiredChannels
     */
    public function __construct(
        public string $eventKey,
        public string $category,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $relatedType = null,
        public ?int $relatedId = null,
        public ?int $actorId = null,
        public string $priority = 'normal',
        public array $metadata = [],
        public array $defaultChannels = ['in_app'],
        public array $requiredChannels = [],
        public ?string $idempotencyKey = null,
        ?CarbonInterface $occurredAt = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $eventKey)) {
            throw new InvalidArgumentException('Notification event keys must be normalized lowercase identifiers.');
        }
        $this->assertText($category, 60, 'category');
        $this->assertText($title, 180, 'title');
        $this->assertText($body, 10000, 'body');
        if ($actionUrl !== null && (
            mb_strlen($actionUrl) > 2048
            || ! str_starts_with($actionUrl, '/')
            || str_starts_with($actionUrl, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $actionUrl) === 1
        )) {
            throw new InvalidArgumentException('Notification action URLs must be safe internal application paths.');
        }
        if (($relatedType === null) !== ($relatedId === null)) {
            throw new InvalidArgumentException('Notification related type and ID must be provided together.');
        }
        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Unsupported notification priority.');
        }
        $this->assertChannels($defaultChannels);
        $this->assertChannels($requiredChannels);
        if (array_diff($requiredChannels, $defaultChannels) !== []) {
            throw new InvalidArgumentException('Required notification channels must also be default channels.');
        }
        if ($idempotencyKey !== null && (mb_strlen($idempotencyKey) < 8 || mb_strlen($idempotencyKey) > 200)) {
            throw new InvalidArgumentException('Notification idempotency keys must contain 8 to 200 characters.');
        }

        $this->occurredAt = CarbonImmutable::instance($occurredAt ?? now())->utc();
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return [
            'event_key' => $this->eventKey,
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'related_type' => $this->relatedType,
            'related_id' => $this->relatedId,
            'actor_id' => $this->actorId,
            'priority' => $this->priority,
            'metadata' => $this->canonicalize($this->metadata),
            'default_channels' => array_values(array_unique($this->defaultChannels)),
            'required_channels' => array_values(array_unique($this->requiredChannels)),
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    public function sha256(): string
    {
        return hash('sha256', json_encode($this->normalized(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function assertText(string $value, int $max, string $field): void
    {
        if (trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Notification {$field} is required and may not exceed {$max} characters.");
        }
    }

    /** @param list<string> $channels */
    private function assertChannels(array $channels): void
    {
        if (array_diff($channels, self::CHANNELS) !== []) {
            throw new InvalidArgumentException('Unsupported notification channel.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }
}
