<?php

namespace App\Enums;

enum UserRoles: string
{
    case User = 'user';
    case Admin = 'admin';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
