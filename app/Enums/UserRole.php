<?php

namespace App\Enums;

enum UserRole: int
{
    case DEVELOPER = -1;
    case USER = 0;
    case ADMINISTRATOR = 1;
    case SYSTEM = 2;
    case DEPARTMENT_HEAD = 3;
    case ADMINISTRATIVE_OFFICER = 4;

    public function label(): string
    {
        return match ($this) {
            self::USER => 'User',
            self::SYSTEM => 'System',
            self::DEVELOPER, self::ADMINISTRATOR => 'Administrator',
            self::DEPARTMENT_HEAD => 'Department Head',
            self::ADMINISTRATIVE_OFFICER => 'Administrative Officer'
        };
    }
}