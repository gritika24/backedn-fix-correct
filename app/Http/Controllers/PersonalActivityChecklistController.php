<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonalActivity\ChecklistTaskRequest;
use App\Models\Notification;
use App\Models\PersonalActivity;
use App\Services\PersonalActivityCompletionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonalActivityChecklistController extends Controller
{
    public function __construct(
        private PersonalActivityCompletionService $completionService
    ) {}

    // Get all checklist tasks and calculate progress
    public function index(Request $request, $activityId)
    {
        $activity = PersonalActivity::where('user_id', $request->user()->id)
            ->where('tracking_type', 'checklist')
            ->findOrFail($activityId);

        $tasks = $activity->checklistItems;

        $totalTasks = $tasks->count();

        $completedTasks = $tasks->where('is_completed', true)->count();

        $progress = $totalTasks > 0
            ? round(($completedTasks / $totalTasks) * 100)
            : 0;

        return response()->json([
            'tasks' => $tasks,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'progress_percentage' => $progress,
        ]);
    }

    // Add a new checklist task
    public function store(ChecklistTaskRequest $request, $activityId)
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($request, $activityId, $validated) {

            $activity = $this->lockedActiveChecklist($request, $activityId);

            $task = $activity->checklistItems()->create([
                'task' => $validated['task'],
            ]);

            return response()->json([
                'message' => 'Checklist task added successfully.',
                'task' => $task,
            ], 201);
        });
    }

    // Update a checklist task or its completion status
    public function update(ChecklistTaskRequest $request, $activityId, $taskId)
    {
        return DB::transaction(function () use ($request, $activityId, $taskId) {

            $activity = $this->lockedActiveChecklist($request, $activityId);

            $task = $activity->checklistItems()
                ->lockForUpdate()
                ->findOrFail($taskId);

            $validated = $request->validated();

            if (array_key_exists('is_completed', $validated)) {
                $validated['completed_at'] = $validated['is_completed']
                    ? now()
                    : null;
            }

            $task->update($validated);

            // Check progress only when task completion status changes
            if (array_key_exists('is_completed', $validated)) {
                $this->checkChecklistProgress($activity);
            }

            // Check whether all checklist tasks are completed
            $this->completeIfAllTasksDone($activity);

            $activity->refresh();

            return response()->json([
                'message' => 'Checklist task updated successfully.',
                'task' => $task->fresh(),
                'status' => $activity->status,
                'badge' => $activity->badge,
            ]);
        });
    }

    // Delete a checklist task
    public function destroy(Request $request, $activityId, $taskId)
    {
        return DB::transaction(function () use ($request, $activityId, $taskId) {

            $activity = $this->lockedActiveChecklist($request, $activityId);

            $task = $activity->checklistItems()
                ->lockForUpdate()
                ->findOrFail($taskId);

            if ($activity->checklistItems()->count() <= 1) {
                return response()->json([
                    'message' => 'A checklist needs at least one task. Delete the whole activity instead.',
                ], 422);
            }

            $task->delete();

            // Check whether all remaining tasks are completed
            $this->completeIfAllTasksDone($activity);

            $activity->refresh();

            return response()->json([
                'message' => 'Checklist task deleted successfully.',
                'status' => $activity->status,
                'badge' => $activity->badge,
            ]);
        });
    }

    /**
     * Finds and locks the user's checklist activity. Past-deadline activities
     * are expired first, and a finished one gives a clear 422 instead of a 404.
     */
    private function lockedActiveChecklist(Request $request, $activityId): PersonalActivity
    {
        PersonalActivity::expireOverdue($request->user()->id);

        $activity = PersonalActivity::where('user_id', $request->user()->id)
            ->where('tracking_type', 'checklist')
            ->lockForUpdate()
            ->findOrFail($activityId);

        if ($activity->isFinished()) {
            throw new HttpResponseException(response()->json([
                'message' => $activity->status === 'abandoned'
                    ? 'The deadline for this activity has passed.'
                    : 'This activity is already completed.',
            ], 422));
        }

        return $activity;
    }

    // Complete the activity when all checklist tasks are done
    private function completeIfAllTasksDone(PersonalActivity $activity): void
    {
        $totalTasks = $activity->checklistItems()->count();

        $incompleteTasks = $activity->checklistItems()
            ->where('is_completed', false)
            ->count();

        if ($totalTasks > 0 && $incompleteTasks === 0) {
            $this->completionService->complete($activity);
        }
    }

    // Create notifications when checklist progress reaches milestones
    private function checkChecklistProgress(PersonalActivity $activity): void
    {
        $totalTasks = $activity->checklistItems()->count();

        if ($totalTasks === 0) {
            return;
        }

        $completedTasks = $activity->checklistItems()
            ->where('is_completed', true)
            ->count();

        $progress = ($completedTasks / $totalTasks) * 100;

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

        // Do not send progress notifications when the activity is completed
        if ($progress < 100) {

            foreach ($milestones as $milestone => $content) {

                if ($progress >= $milestone) {

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
                                'tracking_type' => 'checklist',
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
