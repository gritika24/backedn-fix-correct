<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cafe\CafeRequest;
use App\Http\Requests\Cafe\RejectCafeRequest;
use App\Http\Requests\Cafe\UpdateCrowdRequest;
use App\Models\Cafe;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CafeController extends Controller
{
    /** Upload field => [database column, storage folder]. */
    private const SINGLE_UPLOADS = [
        'logo' => ['logo_path', 'cafes/logos'],
        'cover_image' => ['cover_image_path', 'cafes/covers'],
    ];

    private const MULTI_UPLOADS = [
        'cafe_photos' => 'cafes/photos',
        'food_photos' => 'cafes/food',
        'menu_images' => 'cafes/menus',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    // ------------------------------------------------------------------
    // Customers
    // ------------------------------------------------------------------

    /** Approved cafés with their rating summary. */
    public function index(): JsonResponse
    {
        $completedVisit = fn ($query) => $query->whereHas('cafeVisit', fn ($visit) => $visit->whereNotNull('check_out_at'));

        $cafes = Cafe::approved()
            ->withAvg(['reviews as average_rating' => $completedVisit], 'rating')
            ->withCount(['reviews as total_reviews' => $completedVisit])
            ->latest()
            ->get();

        return response()->json(['cafes' => $cafes]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['cafe' => Cafe::approved()->findOrFail($id)]);
    }

    // ------------------------------------------------------------------
    // Café owners
    // ------------------------------------------------------------------

    public function myCafes(Request $request): JsonResponse
    {
        return response()->json([
            'cafes' => $request->user()->cafes()->latest()->get(),
        ]);
    }

    public function myCafe(Request $request, int $id): JsonResponse
    {
        return response()->json(['cafe' => $request->user()->cafes()->findOrFail($id)]);
    }

    /** QR codes for the owner's approved cafés (the only place qr_token is revealed). */
    public function myCafeQrs(Request $request): JsonResponse
    {
        $cafes = $request->user()->cafes()
            ->approved()
            ->whereNotNull('qr_token')
            ->get(['id', 'name', 'qr_token'])
            ->each->makeVisible('qr_token')
            ->each->setAppends([]);

        return response()->json(['cafes' => $cafes]);
    }

    public function store(CafeRequest $request): JsonResponse
    {
        $data = $this->withStoredUploads($request, $request->validated());

        $cafe = $request->user()->cafes()->create([...$data, 'status' => 'pending']);

        $this->notifyAdmins($cafe, 'New Café Submitted', "{$cafe->name} has been submitted and is waiting for review.");

        return response()->json([
            'message' => 'Café submitted successfully. Waiting for admin approval.',
            'cafe' => $cafe,
        ], 201);
    }

    /** Editing a café sends it back to the admin for approval. */
    public function update(CafeRequest $request, int $id): JsonResponse
    {
        $cafe = $request->user()->cafes()->findOrFail($id);
        $replacedFiles = [];

        $data = $this->withStoredUploads($request, $request->validated(), $cafe, $replacedFiles);

        $cafe->update([...$data, 'status' => 'pending', 'rejection_reason' => null]);

        // Remove old logo/cover only after the database update succeeded.
        Storage::disk('public')->delete($replacedFiles);

        $this->notifyAdmins($cafe, 'Café Updated', "{$cafe->name} was edited and is waiting for review again.");

        return response()->json([
            'message' => 'Café updated successfully. Waiting for admin approval.',
            'cafe' => $cafe->fresh(),
        ]);
    }

    public function updateCrowd(UpdateCrowdRequest $request, int $id): JsonResponse
    {
        $cafe = $request->user()->cafes()->approved()->findOrFail($id);

        $cafe->update([
            'crowd_level' => $request->validated('crowd_level'),
            'crowd_updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Crowd level updated.',
            'cafe' => $cafe->fresh(),
        ]);
    }

    /** Files are removed automatically by the Cafe model's deleted event. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->cafes()->findOrFail($id)->delete();

        return response()->json(['message' => 'Café deleted successfully.']);
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function statistics(): JsonResponse
    {
        $counts = Cafe::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
        ]);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json([
            'cafes' => Cafe::with('owner:id,name,email')->latest()->get(),
        ]);
    }

    public function pendingCafes(): JsonResponse
    {
        return response()->json([
            'cafes' => Cafe::with('owner:id,name,email')->where('status', 'pending')->latest()->get(),
        ]);
    }

    public function approveCafe(int $id): JsonResponse
    {
        $cafe = Cafe::where('status', 'pending')->findOrFail($id);

        $cafe->forceFill([
            'qr_token' => $cafe->qr_token ?: (string) Str::uuid(),
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_at' => now(),
        ])->save();

        $this->notifyOwner(
            $cafe,
            'cafe_approved',
            'Café Approved!',
            "Your café {$cafe->name} has been approved and is now visible to customers.",
        );

        return response()->json([
            'message' => 'Café approved successfully.',
            'cafe' => $cafe->fresh(),
        ]);
    }

    public function rejectCafe(RejectCafeRequest $request, int $id): JsonResponse
    {
        $cafe = Cafe::where('status', 'pending')->findOrFail($id);
        $reason = $request->validated('reason');

        $cafe->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
        ]);

        $this->notifyOwner(
            $cafe,
            'cafe_rejected',
            'Café Submission Rejected',
            "Your café {$cafe->name} was rejected. Reason: {$reason}",
            ['rejection_reason' => $reason],
        );

        return response()->json([
            'message' => 'Café rejected successfully.',
            'cafe' => $cafe->fresh(),
        ]);
    }

    public function adminDestroy(int $id): JsonResponse
    {
        $cafe = Cafe::findOrFail($id);
        $name = $cafe->name;
        $ownerId = $cafe->user_id;

        $cafe->delete();

        $this->notifications->send(
            $ownerId,
            'cafe_removed',
            'Café Removed',
            "Your café {$name} was removed by an administrator.",
            'cafe',
            $id,
            ['cafe_id' => $id, 'cafe_name' => $name],
        );

        return response()->json(['message' => 'Café deleted successfully.']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Stores uploaded images and returns the data ready to save.
     * When editing, new gallery photos are added to the existing ones and
     * replaced logo/cover paths are collected in $replacedFiles for deletion.
     */
    private function withStoredUploads(Request $request, array $data, ?Cafe $cafe = null, array &$replacedFiles = []): array
    {
        foreach (self::SINGLE_UPLOADS as $field => [$column, $folder]) {
            unset($data[$field]);

            if ($request->hasFile($field)) {
                if ($cafe?->{$column}) {
                    $replacedFiles[] = $cafe->{$column};
                }

                $data[$column] = $request->file($field)->store($folder, 'public');
            }
        }

        foreach (self::MULTI_UPLOADS as $field => $folder) {
            unset($data[$field]);

            if ($request->hasFile($field)) {
                $stored = collect($request->file($field))
                    ->map(fn ($file) => $file->store($folder, 'public'))
                    ->all();

                $data[$field] = array_merge($cafe?->{$field} ?? [], $stored);
            }
        }

        return $data;
    }

    private function notifyAdmins(Cafe $cafe, string $title, string $message): void
    {
        $this->notifications->sendToMany(
            User::where('role', 'admin')->pluck('id'),
            'new_cafe_submitted',
            $title,
            $message,
            'cafe',
            $cafe->id,
            ['cafe_id' => $cafe->id, 'cafe_name' => $cafe->name, 'owner_id' => $cafe->user_id],
        );
    }

    private function notifyOwner(Cafe $cafe, string $type, string $title, string $message, array $extra = []): void
    {
        $this->notifications->send(
            $cafe->user_id,
            $type,
            $title,
            $message,
            'cafe',
            $cafe->id,
            ['cafe_id' => $cafe->id, 'cafe_name' => $cafe->name, ...$extra],
        );
    }
}
