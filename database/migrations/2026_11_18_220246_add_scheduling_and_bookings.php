<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->timestamp('scheduled_at')->nullable()->index();
            $t->string('timezone', 64)->default('UTC');
            $t->unsignedSmallInteger('duration_minutes')->default(60);
        });
        Schema::create('meeting_bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('timezone', 64)->default('UTC');
            $t->uuid('reminder_uuid')->unique();
            $t->timestamp('reminder_due_at')->index();
            $t->timestamp('reminder_notified_at')->nullable();
            $t->timestamp('reminder_emailed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_bookings');
        Schema::table('meetings', fn (Blueprint $t) => $t->dropColumn(['scheduled_at', 'timezone', 'duration_minutes']));
    }
};
