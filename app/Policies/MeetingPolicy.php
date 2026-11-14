<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function manage(User $user, Meeting $meeting): bool
    {
        return $user->status === 'active' && $user->id === $meeting->host_id;
    }
}
