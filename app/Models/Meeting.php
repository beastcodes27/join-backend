<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'expires_at' => 'datetime', 'locked' => 'boolean', 'scheduled_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'meeting_code';
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(MeetingBooking::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(MeetingAdmission::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MeetingEvent::class);
    }
}
