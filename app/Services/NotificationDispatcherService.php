<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\AppNotification;
use App\Models\User;
use App\Notifications\GenericWebPushNotification;
use Illuminate\Support\Facades\Log;

class NotificationDispatcherService
{
    /**
     * Create an in-app notification, broadcast via Reverb, and dispatch Web Push if user allows.
     */
    public static function send(
        User|int $recipient,
        string $type,
        string $title,
        string $content,
        array $data = [],
        ?string $url = null
    ): AppNotification {
        $userId = $recipient instanceof User ? $recipient->id : $recipient;
        $user = $recipient instanceof User ? $recipient : User::find($userId);

        $notification = AppNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'data' => $data,
        ]);

        $unreadCount = AppNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        // 1. Broadcast over WebSocket (Reverb)
        try {
            broadcast(new NotificationSent($notification, $unreadCount));
        } catch (\Throwable $e) {
            report($e);
        }

        // 2. Dispatch Web Push Notification if user preferences allow
        if ($user && $user->wantsNotificationFor($type)) {
            try {
                // If url not provided, try to infer from data
                $targetUrl = $url ?? self::resolveUrl($type, $data);

                // Attach notification ID query param for automatic read-tracking when opened
                if ($targetUrl && $notification->id) {
                    $separator = str_contains($targetUrl, '?') ? '&' : '?';
                    if (! str_contains($targetUrl, 'notif_id=')) {
                        $targetUrl .= "{$separator}notif_id={$notification->id}";
                    }
                }

                $user->notify(new GenericWebPushNotification(
                    title: $title,
                    body: $content,
                    url: $targetUrl,
                    data: array_merge($data, [
                        'notification_id' => $notification->id,
                        'type' => $type,
                    ])
                ));
            } catch (\Throwable $e) {
                Log::warning('Falha ao despachar Web Push notification: '.$e->getMessage(), [
                    'user_id' => $userId,
                    'type' => $type,
                ]);
            }
        }

        return $notification;
    }

    /**
     * Resolve target frontend route based on notification type and data
     */
    protected static function resolveUrl(string $type, array $data): string
    {
        if (isset($data['post_id'])) {
            return '/posts/'.$data['post_id'];
        }

        if (isset($data['group_id'])) {
            return '/groups/'.$data['group_id'];
        }

        if (isset($data['event_id'])) {
            return '/events/'.$data['event_id'];
        }

        return '/';
    }
}
