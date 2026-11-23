<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonalActivity\StoreActivityRequest;
use App\Http\Requests\PersonalActivity\UpdateActivityRequest;
use App\Models\PersonalActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonalActivityController extends Controller
{
    // Get all goals and challenges belonging to the logged-in user
    public function index(Request $request)
    {
        PersonalActivity::expireOverdue($request->user()->id);

        $activities = PersonalActivity::where('user_id', $request->user()->id)
            ->with(['checklistItems', 'badge'])
            ->latest()
            ->get();

        return response()->json($activities);
    }

    // Create a new goal or challenge

    public function store(StoreActivityRequest $request)
    {
        $validated = $request->validated();

        $activity = DB::transaction(function () use ($request, $validated) {

            $activity = PersonalActivity::create([
                'user_id' => $request->user()->id,
                'type' => $validated['type'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'tracking_type' => $validated['tracking_type'],
                // A timer is "paused" until the user presses Start; a checklist is active straight away.
                'status' => $validated['tracking_type'] === 'timer' ? 'paused' : 'active',
                'icon' => $validated['icon'] ?? 'star',
                'target_duration_minutes' => $validated['target_duration_minutes'] ?? null,
                'deadline' => $validated['deadline'] ?? null,
            ]);

            if ($activity->tracking_type === 'checklist') {
                $activity->checklistItems()->createMany(
                    collect($validated['tasks'])->map(function ($task) {
                        return ['task' => $task];
                    })->toArray()
                );
            }

            return $activity;
        });

        return response()->json(
            $activity->load('checklistItems'),
            201
        );
    }

    // View one goal or challenge
    public function show(Request $request, $id)
    {
        PersonalActivity::expireOverdue($request->user()->id);

        $activity = PersonalActivity::where('user_id', $request->user()->id)
            ->with(['checklistItems', 'timerSessions', 'badge'])
            ->findOrFail($id);

        return response()->json($activity);
    }

    // Update basic activity information

    public function update(UpdateActivityRequest $request, $id)
    {
        $activity = PersonalActivity::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Completed or abandoned activities cannot be edited
        if ($activity->isFinished()) {
            return response()->json([
                'message' => 'This activity can no longer be edited.',
            ], 422);
        }

        // A running timer activity cannot be edited
        if (
            $activity->tracking_type === 'timer' &&
            $activity->status === 'active'
        ) {
            return response()->json([
                'message' => 'Pause the timer before editing this activity.',
            ], 422);
        }

        $validated = $request->validated();

        $activity->update($validated);

        return response()->json($activity);
    }

    // Delete a goal or challenge
    public function destroy(Request $request, $id)
    {
        $activity = PersonalActivity::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Completed activities cannot be deleted because they have earned a badge
        if ($activity->status === 'completed') {
            return response()->json([
                'message' => 'Completed activities cannot be deleted.',
            ], 422);
        }

        $activity->delete();

        return response()->json([
            'message' => 'Activity deleted successfully.',
        ]);
    }
}
