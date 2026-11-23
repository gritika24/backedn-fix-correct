<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Collection;

/**
 * Creates in-app notifications. Sending to many users is done with
 * chunked bulk inserts (one query per 500 users) instead of one query
 * per user, so announcements stay fast as the user base grows.
 */
class NotificationService
{
    public function send(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $relatedType = null,
        ?int $relatedId = null,
        array $data = [],
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'data' => $data,
        ]);
    }

    /** @param iterable<int>|Collection<int, int> $userIds */
    public function sendToMany(
        iterable $userIds,
        string $type,
        string $title,
        string $message,
        ?string $relatedType = null,
        ?int $relatedId = null,
        array $data = [],
    ): int {
        $now = now();
        $sent = 0;

        collect($userIds)->unique()->chunk(500)->each(function (Collection $chunk) use (
            $type, $title, $message, $relatedType, $relatedId, $data, $now, &$sent
        ) {
            // insert() skips model casts, so encode the JSON column by hand.
            Notification::insert($chunk->map(fn (int $userId) => [
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'data' => json_encode($data),
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());

            $sent += $chunk->count();
        });

        return $sent;
    }
}
