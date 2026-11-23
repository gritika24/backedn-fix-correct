<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Timer goals used to be created as "active" even before the timer was
 * started, which blocked editing them. Any timer goal marked active that
 * has no running session is really paused.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_activities')
            ->where('tracking_type', 'timer')
            ->where('status', 'active')
            ->whereNotExists(fn ($query) => $query
                ->from('personal_activity_timer_sessions')
                ->whereColumn('personal_activity_timer_sessions.personal_activity_id', 'personal_activities.id')
                ->whereNull('ended_at'))
            ->update(['status' => 'paused']);
    }

    public function down(): void
    {
        // Data fix only; nothing to undo.
    }
};
