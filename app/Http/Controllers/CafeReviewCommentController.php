<?php

namespace App\Http\Controllers;

use App\Http\Requests\Review\ReviewCommentRequest;
use App\Models\CafeReview;
use App\Models\CafeReviewComment;
use App\Services\MediaStorage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A private conversation under a review between the customer who wrote it
 * and the owner of the café. Nobody else can post.
 */
class CafeReviewCommentController extends Controller
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly NotificationService $notifications,
    ) {}

    public function store(ReviewCommentRequest $request, int $reviewId): JsonResponse
    {
        $review = CafeReview::with('cafeVisit.cafe:id,user_id,name')->findOrFail($reviewId);
        $user = $request->user();

        $visit = $review->cafeVisit;
        $isCustomer = $visit?->user_id === $user->id;
        $isCafeOwner = $visit?->cafe?->user_id === $user->id;

        if (! $isCustomer && ! $isCafeOwner) {
            return response()->json(['message' => 'Only the review author and café owner can comment.'], 403);
        }

        $comment = $review->comments()->create([
            'user_id' => $user->id,
            'comment' => $request->validated('comment'),
        ]);
        $comment->setRelation('user', $user);

        $recipientId = $isCustomer ? $visit->cafe->user_id : $visit->user_id;

        if ($recipientId && $recipientId !== $user->id) {
            $this->notifications->send(
                $recipientId,
                'new_review_comment',
                'New Review Comment',
                "{$user->name} replied to a review of {$visit->cafe->name}.",
                'cafe_review_comment',
                $comment->id,
                [
                    'comment_id' => $comment->id,
                    'review_id' => $review->id,
                    'cafe_id' => $visit->cafe->id,
                    'cafe_name' => $visit->cafe->name,
                    'commenter_id' => $user->id,
                    'commenter_name' => $user->name,
                    'reviewer_id' => $visit->user_id,
                ],
            );
        }

        return response()->json([
            'message' => 'Comment added successfully.',
            'comment' => self::present($comment, $user->id, $this->media),
        ], 201);
    }

    public function update(ReviewCommentRequest $request, int $commentId): JsonResponse
    {
        $comment = CafeReviewComment::with('user:id,name,profile_image,role')
            ->where('user_id', $request->user()->id)
            ->findOrFail($commentId);

        $comment->update(['comment' => $request->validated('comment')]);

        return response()->json([
            'message' => 'Comment updated successfully.',
            'comment' => self::present($comment, $request->user()->id, $this->media),
        ]);
    }

    public function destroy(Request $request, int $commentId): JsonResponse
    {
        CafeReviewComment::where('user_id', $request->user()->id)->findOrFail($commentId)->delete();

        return response()->json(['message' => 'Comment deleted successfully.']);
    }

    /** One JSON shape for a comment, used by every endpoint that returns comments. */
    public static function present(CafeReviewComment $comment, int $viewerId, MediaStorage $media): array
    {
        return [
            'id' => $comment->id,
            'comment' => $comment->comment,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'is_mine' => $comment->user_id === $viewerId,
            'author_name' => $comment->user?->name ?? 'CaféVerse User',
            'author_image' => $media->url($comment->user?->profile_image),
            'author_role' => $comment->user?->role,
        ];
    }
}
