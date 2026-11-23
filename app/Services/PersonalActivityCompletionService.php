<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PersonalActivity;
use Illuminate\Support\Facades\DB;

class PersonalActivityCompletionService
{
    public function complete(PersonalActivity $activity): void
    {
        DB::transaction(function () use ($activity) {

            $activity = PersonalActivity::where('id', $activity->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Prevent duplicate completion and notifications
            if ($activity->status === 'completed') {
                return;
            }

            $completedAt = now();

            $activity->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
            ]);

            // Award exactly one badge for this activity
            $badge = $activity->badge()->firstOrCreate(
                [
                    'personal_activity_id' => $activity->id,
                ],
                [
                    'user_id' => $activity->user_id,
                    'badge_name' => $activity->title.' Completed',
                    'badge_description' => 'Completed your personal '.$activity->type.': '.$activity->title,
                    'icon' => $activity->icon ?? 'star',
                    'earned_at' => $completedAt,
                ]
            );

            // Create a completion notification
            Notification::create([
                'user_id' => $activity->user_id,
                'title' => ucfirst($activity->type).' completed!',
                'message' => 'You completed "'.$activity->title.'" and earned the "'.$badge->badge_name.'" badge.',
                'type' => 'activity_completed',
                'related_type' => PersonalActivity::class,
                'related_id' => $activity->id,
                'data' => [
                    'activity_id' => $activity->id,
                    'activity_type' => $activity->type,
                    'tracking_type' => $activity->tracking_type,
                    'badge_id' => $badge->id,
                ],
            ]);
        });
    }
}
