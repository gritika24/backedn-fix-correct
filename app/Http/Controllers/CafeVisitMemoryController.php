<?php

namespace App\Http\Controllers;

use App\Http\Requests\CafeYatra\StoreMemoryRequest;
use App\Http\Requests\CafeYatra\UpdateMemoryRequest;
use App\Models\CafeVisit;
use App\Models\CafeVisitMemory;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Photos, videos and notes a customer saves for one of their completed visits. */
class CafeVisitMemoryController extends Controller
{
    private const FOLDERS = [
        'photo' => 'visit-memories/photos',
        'video' => 'visit-memories/videos',
    ];

    public function __construct(private readonly MediaStorage $media) {}

    public function index(Request $request, int $visitId): JsonResponse
    {
        $visit = $this->ownedCompletedVisit($request, $visitId);

        $memories = $visit->memories()->latest()->get()->each(fn (CafeVisitMemory $memory) => $this->withUrl($memory));

        return response()->json([
            'message' => 'Memories retrieved successfully.',
            'memories' => $memories,
        ]);
    }

    public function store(StoreMemoryRequest $request, int $visitId): JsonResponse
    {
        $visit = $this->ownedCompletedVisit($request, $visitId);
        $validated = $request->validated();

        $path = $request->hasFile('file')
            ? $request->file('file')->store(self::FOLDERS[$validated['type']], 'public')
            : null;

        $memory = $visit->memories()->create([
            'type' => $validated['type'],
            'file_path' => $path,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Memory saved successfully.',
            'memory' => $this->withUrl($memory),
        ], 201);
    }

    public function update(UpdateMemoryRequest $request, int $memoryId): JsonResponse
    {
        $memory = $this->ownedMemory($request, $memoryId);

        if ($memory->type === 'note' && $request->exists('description') && blank($request->input('description'))) {
            return response()->json(['message' => 'Please write something for your personal note.'], 422);
        }

        $oldPath = null;

        if ($request->hasFile('file')) {
            if ($memory->type === 'note') {
                return response()->json(['message' => 'You cannot upload a file for a personal note.'], 422);
            }

            // The memory's own type decides which files are allowed.
            $request->validate(['file' => ['required', ...StoreMemoryRequest::fileRule($memory->type)]]);

            $oldPath = $memory->file_path;
            $memory->file_path = $request->file('file')->store(self::FOLDERS[$memory->type], 'public');
        }

        if ($request->exists('description')) {
            $memory->description = $request->validated('description');
        }

        $memory->save();

        // Remove the replaced file only after the new one is saved.
        $this->media->delete([$oldPath]);

        return response()->json([
            'message' => 'Memory updated successfully.',
            'memory' => $this->withUrl($memory),
        ]);
    }

    public function destroy(Request $request, int $memoryId): JsonResponse
    {
        $memory = $this->ownedMemory($request, $memoryId);
        $path = $memory->file_path;

        $memory->delete();
        $this->media->delete([$path]);

        return response()->json(['message' => 'Memory deleted successfully.']);
    }

    private function ownedCompletedVisit(Request $request, int $visitId): CafeVisit
    {
        return CafeVisit::where('user_id', $request->user()->id)
            ->whereNotNull('check_out_at')
            ->findOrFail($visitId);
    }

    private function ownedMemory(Request $request, int $memoryId): CafeVisitMemory
    {
        return CafeVisitMemory::whereHas('cafeVisit', fn ($query) => $query
            ->where('user_id', $request->user()->id)
            ->whereNotNull('check_out_at'))
            ->findOrFail($memoryId);
    }

    private function withUrl(CafeVisitMemory $memory): CafeVisitMemory
    {
        $memory->file_url = $this->media->url($memory->file_path);

        return $memory;
    }
}
