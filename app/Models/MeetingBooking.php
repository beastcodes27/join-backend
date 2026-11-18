<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MeetingBooking extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reminder_due_at' => 'datetime', 'reminder_notified_at' => 'datetime', 'reminder_emailed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            $booking->reminder_uuid ??= (string) Str::uuid();
        });
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUpcoming(): bool
    {
        return ! $this->cancelled_at && $this->user->status === 'active' && $this->meeting->status !== 'ended' && $this->meeting->scheduled_at?->isFuture();
    }
}
