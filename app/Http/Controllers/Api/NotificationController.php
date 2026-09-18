<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = $user
            ->appNotifications()
            ->latest();

        if ($request->filled('category') && $request->category !== 'all') {
            $category = $request->query('category');
            match ($category) {
                'likes' => $query->whereIn('type', ['post_like', 'comment_like']),
                'comments' => $query->whereIn('type', ['post_comment', 'comment_reply']),
                'mentions' => $query->whereIn('type', ['post_mention', 'comment_mention']),
                'groups', 'invites' => $query->where('type', 'group_invite'),
                'events' => $query->where('type', 'event_rsvp'),
                default => $query->where('type', $category),
            };
        }

        $notifications = $query->paginate(30);

        // Enhance group_invite data with current membership status
        $memberGroupStatuses = DB::table('group_users')
            ->where('user_id', $user->id)
            ->pluck('status', 'group_id')
            ->toArray();

        $notifications->getCollection()->transform(function ($notification) use ($memberGroupStatuses) {
            if ($notification->type === 'group_invite' && isset($notification->data['group_id'])) {
                $groupId = $notification->data['group_id'];
                $data = $notification->data;
                if (isset($memberGroupStatuses[$groupId])) {
                    $data['status'] = $memberGroupStatuses[$groupId]; // 'accepted' or 'pending'
                } else {
                    $data['status'] = 'declined';
                }
                $notification->data = $data;
            }

            return $notification;
        });

        return response()->json($notifications);
    }

    public function unreadCount()
    {
        $count = auth()->user()
            ->appNotifications()
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markAsRead(AppNotification $notification)
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($notification);
    }

    public function markAllAsRead()
    {
        auth()->user()
            ->appNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Todas as notificações foram marcadas como lidas.']);
    }

    public function categoryCounts()
    {
        $user = auth()->user();
        $counts = $user->appNotifications()
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return response()->json([
            'all' => (int) array_sum($counts),
            'likes' => (int) (($counts['post_like'] ?? 0) + ($counts['comment_like'] ?? 0)),
            'comments' => (int) (($counts['post_comment'] ?? 0) + ($counts['comment_reply'] ?? 0)),
            'mentions' => (int) (($counts['post_mention'] ?? 0) + ($counts['comment_mention'] ?? 0)),
            'groups' => (int) ($counts['group_invite'] ?? 0),
            'events' => (int) ($counts['event_rsvp'] ?? 0),
        ]);
    }
}
