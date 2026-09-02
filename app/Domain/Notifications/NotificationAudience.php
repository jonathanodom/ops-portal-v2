<?php

namespace App\Domain\Notifications;

use InvalidArgumentException;

final readonly class NotificationAudience
{
    private function __construct(public string $type, public array|string $value) {}

    /** @param array<int, int> $userIds */
    public static function users(array $userIds): self
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (array_filter($ids, fn (int $id): bool => $id < 1) !== []) {
            throw new InvalidArgumentException('Notification recipient user IDs must be positive integers.');
        }

        sort($ids);

        return new self('users', $ids);
    }

    public static function capability(string $capability): self
    {
        return new self('capability', self::requiredKey($capability));
    }

    public static function role(string $role): self
    {
        return new self('role', self::requiredKey($role));
    }

    /** @return array{type: string, value: array<int, int>|string} */
    public function normalized(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }

    private static function requiredKey(string $value): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Notification audience keys may not be empty.');
        }

        return trim($value);
    }
}
