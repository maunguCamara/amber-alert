<?php

declare(strict_types=1);

namespace App\Enums;

enum CaseStatus: string
{
    case Active   = 'active';
    case Review   = 'review';
    case Resolved = 'resolved';
    case Closed   = 'closed';

    public function label(): string
    {
        return match($this) {
            self::Active   => 'Active',
            self::Review   => 'Under Review',
            self::Resolved => 'Resolved',
            self::Closed   => 'Closed',
        };
    }

    public function isLive(): bool
    {
        return $this === self::Active;
    }

    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Review   => in_array($next, [self::Active, self::Closed], true),
            self::Active   => in_array($next, [self::Resolved, self::Closed], true),
            self::Resolved => false,
            self::Closed   => false,
        };
    }
}