<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class GenericWebPushNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public array $data = []
    ) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $payloadData = array_merge($this->data, [
            'url' => $this->url ?? '/',
        ]);

        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/pwa-192x192.png')
            ->badge('/favicon-96x96.png')
            ->data($payloadData)
            ->options([
                'TTL' => 86400,
                'urgency' => 'normal',
            ]);
    }
}
