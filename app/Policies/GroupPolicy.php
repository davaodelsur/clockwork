<?php

namespace App\Policies;

use App\Enums\UserPermission;
use App\Models\User;
use Filament\Facades\Filament;

class GroupPolicy
{
    public function viewAny(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return match (Filament::getCurrentPanel()->getId()) {
            'superuser' => $user?->hasPermission(UserPermission::GROUP) ?? false,
            'secretary' => true,
            default => false,
        };
    }
}