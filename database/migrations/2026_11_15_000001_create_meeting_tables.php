<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('firebase_uid', 128)->nullable()->unique();
            $t->string('avatar', 2048)->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->string('password')->nullable()->change();
        });
        Schema::create('meetings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('host_id')->constrained('users');
            $t->string('title', 120);
            $t->string('meeting_code', 48)->unique();
            $t->string('status', 20)->default('created')->index();
            $t->unsignedSmallInteger('max_participants')->default(12);
            $t->boolean('locked')->default(false);
            $t->string('livekit_sid')->nullable()->index();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->index(['host_id', 'created_at']);
        });
        Schema::create('meeting_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->uuid('guest_identifier')->nullable();
            $t->string('identity', 100);
            $t->string('display_name', 80);
            $t->string('role', 20)->default('participant');
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->timestamp('lease_until')->nullable()->index();
            $t->timestamp('removed_at')->nullable();
            $t->string('connection_sid')->nullable();
            $t->timestamps();
            $t->unique(['meeting_id', 'identity']);
        });
        Schema::create('meeting_admissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->string('participant_identifier', 100);
            $t->string('display_name', 80);
            $t->string('status', 20)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['meeting_id', 'participant_identifier']);
            $t->index(['meeting_id', 'status']);
        });
        Schema::create('meeting_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('event_id', 100)->nullable()->unique();
            $t->string('event_type', 80);
            $t->json('payload')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['meeting_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['meeting_events', 'meeting_admissions', 'meeting_participants', 'meetings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['firebase_uid', 'avatar', 'status']));
    }
};
