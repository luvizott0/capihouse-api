<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $notifications = $user
            ->appNotifications()
            ->latest()
            ->paginate(30);

        // Enhance group_invite data with current membership status
        $memberGroupStatuses = \Illuminate\Support\Facades\DB::table('group_users')
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

        if (!$notification->read_at) {
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
}
