<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\LiveKitGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileMeetings extends Command
{
    protected $signature = 'meetings:reconcile';

    protected $description = 'End expired meetings and retry LiveKit disconnections';

    public function handle(LiveKitGateway $livekit): int
    {
        Meeting::where('status', '!=', 'ended')->where('expires_at', '<=', now())->eachById(function (Meeting $meeting) {
            DB::transaction(function () use ($meeting) {
                $meeting = Meeting::whereKey($meeting->id)->lockForUpdate()->firstOrFail();
                $meeting->update(['status' => 'ended', 'ended_at' => now()]);
                $meeting->events()->create(['event_type' => 'meeting.expired']);
            });
        });
        $failed = false;
        Meeting::where('status', 'ended')->where('ended_at', '>=', now()->subDay())->eachById(function (Meeting $meeting) use ($livekit, &$failed) {
            try {
                if (! ($meeting->scheduled_at && ! $meeting->livekit_sid)) {
                    $livekit->deleteRoom($meeting->uuid);
                }
                $meeting->participants()->whereNull('left_at')->update(['left_at' => now(), 'lease_until' => null]);
            } catch (\Throwable $e) {
                report($e);
                $failed = true;
                $this->error('Disconnection failed for meeting '.$meeting->uuid);
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
