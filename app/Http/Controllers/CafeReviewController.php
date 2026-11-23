<?php

namespace App\Http\Controllers;

use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Requests\Review\UpdateReviewRequest;
use App\Models\Cafe;
use App\Models\CafeReview;
use App\Models\CafeReviewComment;
use App\Models\CafeVisit;
use App\Services\MediaStorage;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CafeReviewController extends Controller
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly NotificationService $notifications,
    ) {}

    /** Reviews for a café, with their discussion threads. */
    public function index(Request $request, int $cafeId): JsonResponse
    {
        $userId = $request->user()->id;

        // Customers only see approved cafés; owners can also see their own.
        $cafe = Cafe::where('id', $cafeId)
            ->where(fn (Builder $query) => $query->where('status', 'approved')->orWhere('user_id', $userId))
            ->firstOrFail();

        $reviews = CafeReview::whereHas('cafeVisit', fn ($query) => $query->where('cafe_id', $cafe->id)->whereNotNull('check_out_at'))
            ->with([
                'cafeVisit:id,user_id,cafe_id',
                'cafeVisit.user:id,name,profile_image',
                'comments' => fn ($query) => $query->oldest(),
                'comments.user:id,name,profile_image,role',
            ])
            ->latest()
            ->get();

        $payload = $reviews->map(fn (CafeReview $review) => [
            'id' => $review->id,
            'is_mine' => $review->cafeVisit?->user_id === $userId,
            'reviewer_name' => $review->cafeVisit?->user?->name ?? 'CaféVerse User',
            'reviewer_image' => $this->media->url($review->cafeVisit?->user?->profile_image),
            'rating' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at,
            'media' => collect($review->photos ?? [])->map(fn (array $file) => [
                'type' => $file['type'] ?? 'photo',
                'file_url' => $this->media->url($file['file_path'] ?? null),
            ])->values(),
            'comments' => $review->comments->map(fn (CafeReviewComment $comment) => CafeReviewCommentController::present($comment, $userId, $this->media))->values(),
        ])->values();

        return response()->json([
            'message' => 'Reviews retrieved successfully.',
            'total_reviews' => $reviews->count(),
            'average_rating' => round($reviews->avg('rating') ?? 0, 1),
            'reviews' => $payload,
        ]);
    }

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $visit = CafeVisit::with('cafe:id,user_id,name')
            ->where('id', $validated['cafe_visit_id'])
            ->where('user_id', $request->user()->id)
            ->whereNotNull('check_out_at')
            ->first();

        if (! $visit) {
            return response()->json(['message' => 'You can only review your own completed café visits.'], 404);
        }

        if ($visit->review()->exists()) {
            return response()->json(['message' => 'You have already reviewed this visit.'], 409);
        }

        $media = collect($request->file('media', []))
            ->map(fn ($file) => $this->media->store($file, 'reviews'))
            ->all();

        $review = $visit->review()->create([
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'photos' => $media,
        ]);

        if ($visit->cafe?->user_id) {
            $this->notifications->send(
                $visit->cafe->user_id,
                'new_cafe_review',
                'New Café Review',
                "A customer left a {$review->rating}-star review for {$visit->cafe->name}.",
                'cafe_review',
                $review->id,
                [
                    'review_id' => $review->id,
                    'cafe_id' => $visit->cafe->id,
                    'cafe_name' => $visit->cafe->name,
                    'customer_id' => $request->user()->id,
                    'rating' => $review->rating,
                ],
            );
        }

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review' => $review,
        ], 201);
    }

    public function update(UpdateReviewRequest $request, int $reviewId): JsonResponse
    {
        $review = $this->ownedReview($request, $reviewId);
        $validated = $request->validated();

        $existing = array_values($review->photos ?? []);
        $removeIndexes = array_map('intval', $validated['remove_media'] ?? []);

        foreach ($removeIndexes as $index) {
            if (! array_key_exists($index, $existing)) {
                return response()->json(['message' => 'Invalid media selection.'], 422);
            }
        }

        $removedPaths = [];
        $kept = [];

        foreach ($existing as $index => $file) {
            if (in_array($index, $removeIndexes, true)) {
                $removedPaths[] = $file['file_path'] ?? null;
            } else {
                $kept[] = $file;
            }
        }

        $added = collect($request->file('media', []))
            ->map(fn ($file) => $this->media->store($file, 'reviews'))
            ->all();

        $review->update([
            'rating' => $validated['rating'] ?? $review->rating,
            'comment' => array_key_exists('comment', $validated) ? $validated['comment'] : $review->comment,
            'photos' => [...$kept, ...$added],
        ]);

        // Delete removed files only after the database update succeeded.
        $this->media->delete($removedPaths);

        return response()->json([
            'message' => 'Review updated successfully.',
            'review' => $review->fresh(),
        ]);
    }

    public function destroy(Request $request, int $reviewId): JsonResponse
    {
        $review = $this->ownedReview($request, $reviewId);
        $paths = collect($review->photos ?? [])->pluck('file_path')->all();

        $review->delete();
        $this->media->delete($paths);

        return response()->json(['message' => 'Review deleted successfully.']);
    }

    private function ownedReview(Request $request, int $reviewId): CafeReview
    {
        return CafeReview::whereHas('cafeVisit', fn ($query) => $query
            ->where('user_id', $request->user()->id)
            ->whereNotNull('check_out_at'))
            ->findOrFail($reviewId);
    }
}
