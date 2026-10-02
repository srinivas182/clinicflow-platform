<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Enums;

enum ConsultType: string
{
    case InPerson = 'in_person';
    case Video = 'video';
    case Audio = 'audio';
    case Chat = 'chat';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In person',
            self::Video => 'Video',
            self::Audio => 'Audio call',
            self::Chat => 'Chat',
        };
    }

    public function isRemote(): bool
    {
        return $this !== self::InPerson;
    }
}
