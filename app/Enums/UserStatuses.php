<?php

namespace App\Enums;

enum UserStatuses: string
{
    case APPROVED = 'approved';
    case PENDING = 'pending';
    case REJECTED = 'rejected';
    case BANNED = 'banned';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
