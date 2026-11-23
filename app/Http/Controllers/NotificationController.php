<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Newest first, in pages (?page=2). The app reads the "notifications" list as before. */
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()
            ->appNotifications()
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 50), 10), 100));

        return response()->json([
            'notifications' => $page->items(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->appNotifications()->where('is_read', false)->count(),
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = $request->user()->appNotifications()->findOrFail($id);

        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }

        return response()->json([
            'message' => 'Notification marked as read successfully.',
            'notification' => $notification,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()
            ->appNotifications()
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->appNotifications()->findOrFail($id)->delete();

        return response()->json(['message' => 'Notification deleted successfully.']);
    }
}
