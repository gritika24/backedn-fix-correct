<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\PersonalActivity;
use App\Services\PersonalActivityCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonalActivityTimerController extends Controller
{
    public function __construct(
        private PersonalActivityCompletionService $completionService
    ) {}

    // Pause a timer if its heartbeat has expired
    private function expireStaleSession(
        PersonalActivity $activity,
        $currentTime
    ): bool {
        $session = $activity->timerSessions()
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();

        // No running session means nothing needs to expire
        if (! $session) {
            return false;
        }

        // Use the start time as a fallback for older sessions
        $lastHeartbeat = $session->last_heartbeat_at
            ?? $session->started_at;

        // Allow a maximum of 90 seconds without a heartbeat
        if (
            ! $currentTime->greaterThan(
                $lastHeartbeat->copy()->addSeconds(90)
            )
        ) {
            return false;
        }

        // Calculate only the time confirmed by the last heartbeat
        $confirmedSeconds = (int) max(
            0,
            $session->started_at->diffInSeconds($lastHeartbeat)
        );

        $targetSeconds = (int) $activity->target_duration_minutes * 60;

        $completedSeconds = (int) $activity->completed_duration_seconds;

        $remainingSeconds = max(
            0,
            $targetSeconds - $completedSeconds
        );

        // Never save more time than the activity's remaining target
        $duration = min($confirmedSeconds, $remainingSeconds);

        // End the session at the confirmed time
        $sessionEnd = $session->started_at
            ->copy()
            ->addSeconds($duration);

        $session->update([
            'ended_at' => $sessionEnd,
            'duration_seconds' => $duration,
        ]);

        $newCompletedSeconds = $completedSeconds + $duration;

        if ($newCompletedSeconds >= $targetSeconds) {
            $activity->update([
                'completed_duration_seconds' => $targetSeconds,
            ]);

            $activity->refresh();

            $this->completionService->complete($activity);
        } else {
            $activity->update([
                'completed_duration_seconds' => $newCompletedSeconds,
                'status' => 'paused',
            ]);
        }
        $this->checkTimerProgress($activity);

        return true;
    }

    // Check whether the timer has reached its target
    private function completeIfTargetReached(
        PersonalActivity $activity,
        $currentTime
    ): bool {
        $targetSeconds = (int) $activity->target_duration_minutes * 60;
        $completedSeconds = (int) $activity->completed_duration_seconds;

        $session = $activity->timerSessions()
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();

        if ($session) {
            // Count only time confirmed by the latest heartbeat
            $lastHeartbeat = $session->last_heartbeat_at
                ?? $session->started_at;

            $elapsedSeconds = (int) max(
                0,
                $session->started_at->diffInSeconds($lastHeartbeat)
            );
            $remainingSeconds = max(
                0,
                $targetSeconds - $completedSeconds
            );

            if ($elapsedSeconds >= $remainingSeconds) {
                $sessionEnd = $session->started_at
                    ->copy()
                    ->addSeconds($remainingSeconds);

                $session->update([
                    'ended_at' => $sessionEnd,
                    'duration_seconds' => $remainingSeconds,
                ]);

                $activity->update([
                    'completed_duration_seconds' => $targetSeconds,
                ]);

                $activity->refresh();

                $this->completionService->complete($activity);

                return true;
            }
        }

        if ($completedSeconds >= $targetSeconds) {
            $this->completionService->complete($activity);

            return true;
        }

        return false;
    }

    // Start a timer
    public function start(Request $request, $activityId)
    {
        PersonalActivity::expireOverdue($request->user()->id);

        return DB::transaction(function () use ($request, $activityId) {

            $activity = PersonalActivity::where(
                'user_id',
                $request->user()->id
            )
                ->where('tracking_type', 'timer')
                ->lockForUpdate()
                ->findOrFail($activityId);

            $currentTime = now();

            // First, check whether the previous timer session has expired
            $this->expireStaleSession($activity, $currentTime);

            $activity->refresh();

            // Check whether the confirmed timer duration has reached its target
            if ($this->completeIfTargetReached($activity, $currentTime)) {
                return response()->json([
                    'message' => 'This activity has already reached its target.',
                ], 422);
            }

            $activity->refresh();

            if ($activity->isFinished()) {
                return response()->json([
                    'message' => $activity->status === 'abandoned'
                        ? 'The deadline for this activity has passed.'
                        : 'This activity is already completed.',
                ], 422);
            }

            $runningSession = $activity->timerSessions()
                ->whereNull('ended_at')
                ->first();

            if ($runningSession) {
                return response()->json([
                    'message' => 'The timer is already running.',
                ], 422);
            }

            $session = $activity->timerSessions()->create([
                'started_at' => $currentTime,
                'last_heartbeat_at' => $currentTime,
            ]);

            $activity->update([
                'status' => 'active',
            ]);

            return response()->json([
                'message' => 'Timer started successfully.',
                'session' => $session,
                'completed_duration_seconds' => $activity->completed_duration_seconds,
                'status' => $activity->status,
            ], 201);
        });
    }

    // Pause a timer
    public function pause(Request $request, $activityId)
    {
        return DB::transaction(function () use ($request, $activityId) {

            $activity = PersonalActivity::where(
                'user_id',
                $request->user()->id
            )
                ->where('tracking_type', 'timer')
                ->lockForUpdate()
                ->findOrFail($activityId);

            $currentTime = now();

            // Check whether the previous session has already expired
            if ($this->expireStaleSession($activity, $currentTime)) {
                $activity->refresh();

                return response()->json([
                    'message' => 'The timer session expired and has been paused.',
                    'status' => $activity->status,
                    'completed_duration_seconds' => $activity->completed_duration_seconds,
                    'badge' => $activity->badge,
                ]);
            }

            // Check whether the timer has reached its target
            if ($this->completeIfTargetReached($activity, $currentTime)) {
                return response()->json([
                    'message' => 'Your timer target has been reached.',
                    'status' => 'completed',
                    'completed_duration_seconds' => $activity->fresh()->completed_duration_seconds,
                    'badge' => $activity->fresh()->badge,
                ]);
            }

            $activity->refresh();

            if ($activity->status !== 'active') {
                return response()->json([
                    'message' => 'This timer is not running.',
                ], 422);
            }

            $session = $activity->timerSessions()
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return response()->json([
                    'message' => 'No running timer session was found.',
                ], 422);
            }

            // The session is not stale (checked above), so the pause request
            // itself proves the user was active until now: count up to now.
            $duration = (int) max(0, $session->started_at->diffInSeconds($currentTime));

            $remainingSeconds = max(
                0,
                ((int) $activity->target_duration_minutes * 60)
                    - (int) $activity->completed_duration_seconds
            );

            $duration = min($duration, $remainingSeconds);

            $sessionEnd = $session->started_at
                ->copy()
                ->addSeconds($duration);

            $session->update([
                'ended_at' => $sessionEnd,
                'duration_seconds' => $duration,
            ]);

            $newCompletedSeconds =
                (int) $activity->completed_duration_seconds + $duration;

            if ($newCompletedSeconds >= (int) $activity->target_duration_minutes * 60) {
                $activity->update([
                    'completed_duration_seconds' => (int) $activity->target_duration_minutes * 60,
                ]);

                $activity->refresh();

                $this->completionService->complete($activity);
            } else {
                $activity->update([
                    'completed_duration_seconds' => $newCompletedSeconds,
                    'status' => 'paused',
                ]);
            }

            // Check progress after saving the confirmed timer duration
            $this->checkTimerProgress($activity);

            $activity->refresh();

            return response()->json([
                'message' => 'Timer paused successfully.',
                'session' => $session->fresh(),
                'completed_duration_seconds' => $activity->completed_duration_seconds,
                'status' => $activity->status,
                'badge' => $activity->badge,
            ]);
        });
    }

    // Update the heartbeat while CaféVerse is active
    public function heartbeat(Request $request, $activityId)
    {
        return DB::transaction(function () use ($request, $activityId) {

            $activity = PersonalActivity::where(
                'user_id',
                $request->user()->id
            )
                ->where('tracking_type', 'timer')
                ->lockForUpdate()
                ->findOrFail($activityId);

            if ($activity->status !== 'active') {
                return response()->json([
                    'message' => 'This timer is not running.',
                    'status' => $activity->status,
                    'completed_duration_seconds' => $activity->completed_duration_seconds,
                ], 422);
            }

            $currentTime = now();

            // First, check whether the session has already expired
            if ($this->expireStaleSession($activity, $currentTime)) {
                $activity->refresh();

                return response()->json([
                    'message' => 'The timer session has expired.',
                    'status' => $activity->status,
                    'completed_duration_seconds' => $activity->completed_duration_seconds,
                    'badge' => $activity->badge,
                ], 422);
            }

            // Find the currently running session
            $session = $activity->timerSessions()
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return response()->json([
                    'message' => 'No running timer session was found.',
                ], 422);
            }

            // Confirm the user's active time up to now
            $session->update([
                'last_heartbeat_at' => $currentTime,
            ]);

            // Check progress using the newly confirmed heartbeat
            $this->checkTimerProgress($activity);

            // Now check whether the confirmed time has reached the target
            if ($this->completeIfTargetReached($activity, $currentTime)) {
                $activity->refresh();

                return response()->json([
                    'message' => 'The timer target has been reached.',
                    'status' => $activity->status,
                    'completed_duration_seconds' => $activity->completed_duration_seconds,
                    'badge' => $activity->badge,
                ]);
            }

            return response()->json([
                'message' => 'Heartbeat updated successfully.',
                'last_heartbeat_at' => $session->fresh()->last_heartbeat_at,
                'status' => $activity->status,
            ]);
        });
    }

    // Resume a paused timer
    public function resume(Request $request, $activityId)
    {
        return $this->start($request, $activityId);
    }

    // Reset a timer
    public function reset(Request $request, $activityId)
    {
        return DB::transaction(function () use ($request, $activityId) {

            $activity = PersonalActivity::where(
                'user_id',
                $request->user()->id
            )
                ->where('tracking_type', 'timer')
                ->lockForUpdate()
                ->findOrFail($activityId);

            if ($activity->isFinished()) {
                return response()->json([
                    'message' => 'This timer cannot be reset.',
                ], 422);
            }

            $activity->timerSessions()->delete();

            $activity->update([
                'completed_duration_seconds' => 0,
                'status' => 'paused',
            ]);

            return response()->json([
                'message' => 'Timer reset successfully.',
                'completed_duration_seconds' => 0,
                'status' => $activity->status,
            ]);
        });
    }

    // Get current timer status
    public function show(Request $request, $activityId)
    {
        PersonalActivity::expireOverdue($request->user()->id);

        return DB::transaction(function () use ($request, $activityId) {

            $activity = PersonalActivity::where(
                'user_id',
                $request->user()->id
            )
                ->where('tracking_type', 'timer')
                ->lockForUpdate()
                ->findOrFail($activityId);

            $currentTime = now();

            // First, check whether the running session has expired
            $this->expireStaleSession($activity, $currentTime);

            $activity->refresh();

            // Check whether the confirmed running time has reached the target
            $this->completeIfTargetReached($activity, $currentTime);

            $activity->refresh();
            $activity->load(['timerSessions', 'badge']);

            $runningSession = $activity->timerSessions
                ->firstWhere('ended_at', null);

            $elapsedSeconds = (int) $activity->completed_duration_seconds;

            // Add only the time confirmed by the latest heartbeat
            if ($runningSession) {
                $lastHeartbeat = $runningSession->last_heartbeat_at
                    ?? $runningSession->started_at;

                $elapsedSeconds += (int) max(
                    0,
                    $runningSession->started_at->diffInSeconds($lastHeartbeat)
                );
            }

            $targetSeconds = (int) $activity->target_duration_minutes * 60;

            return response()->json([
                'activity_id' => $activity->id,
                'status' => $activity->status,
                'target_duration_minutes' => $activity->target_duration_minutes,
                'completed_duration_seconds' => $activity->completed_duration_seconds,
                'elapsed_seconds' => min($elapsedSeconds, $targetSeconds),
                'remaining_seconds' => max(0, $targetSeconds - $elapsedSeconds),
                'is_running' => $runningSession !== null,
                'badge' => $activity->badge,
            ]);
        });
    }

    // Check timer progress and create milestone notifications
    private function checkTimerProgress(PersonalActivity $activity): void
    {
        $targetSeconds = (int) $activity->target_duration_minutes * 60;

        if ($targetSeconds <= 0) {
            return;
        }

        // Start with previously completed timer duration
        $elapsedSeconds = (int) $activity->completed_duration_seconds;

        // Include confirmed time from the currently running session
        $runningSession = $activity->timerSessions()
            ->whereNull('ended_at')
            ->first();

        if ($runningSession) {
            $lastHeartbeat = $runningSession->last_heartbeat_at
                ?? $runningSession->started_at;

            $sessionSeconds = (int) max(
                0,
                $runningSession->started_at->diffInSeconds($lastHeartbeat)
            );

            $elapsedSeconds += $sessionSeconds;
        }

        // Never calculate progress beyond the target
        $elapsedSeconds = min($elapsedSeconds, $targetSeconds);

        $progress = ($elapsedSeconds / $targetSeconds) * 100;

        $milestones = [
            50 => [
                'title' => 'Halfway there!',
                'message' => 'You have completed 50% of "'.$activity->title.'". Keep going!',
            ],
            75 => [
                'title' => 'Almost there!',
                'message' => 'You have completed 75% of "'.$activity->title.'". Just a little more!',
            ],
        ];

        // Do not send progress notifications at 100%
        if ($progress < 100) {

            foreach ($milestones as $milestone => $content) {

                if ($progress >= $milestone) {

                    // Check whether this milestone notification was already sent
                    $alreadySent = Notification::where('user_id', $activity->user_id)
                        ->where('type', 'activity_progress')
                        ->where('related_type', PersonalActivity::class)
                        ->where('related_id', $activity->id)
                        ->where('data->milestone', $milestone)
                        ->exists();

                    if (! $alreadySent) {
                        Notification::create([
                            'user_id' => $activity->user_id,
                            'title' => $content['title'],
                            'message' => $content['message'],
                            'type' => 'activity_progress',
                            'related_type' => PersonalActivity::class,
                            'related_id' => $activity->id,
                            'data' => [
                                'activity_id' => $activity->id,
                                'activity_type' => $activity->type,
                                'tracking_type' => 'timer',
                                'milestone' => $milestone,
                                'progress_percentage' => round($progress),
                            ],
                        ]);
                    }
                }
            }
        }
    }
}
