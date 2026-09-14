<?php

namespace App\Enums;

enum MediaType: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
