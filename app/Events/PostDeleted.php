<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $postId,
        public readonly ?int $groupId = null,
    ) {}

    public function broadcastOn(): Channel
    {
        if ($this->groupId) {
            return new PrivateChannel('group.' . $this->groupId);
        }
        return new Channel('posts');
    }

    public function broadcastAs(): string
    {
        return 'PostDeleted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->postId];
    }
}
