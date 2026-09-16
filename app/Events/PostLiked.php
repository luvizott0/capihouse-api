<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostLiked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $postId,
        public readonly bool $isLiked,
        public readonly int $likesCount,
        public readonly int $userId,
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
        return 'PostLiked';
    }

    public function broadcastWith(): array
    {
        return [
            'post_id'     => $this->postId,
            'is_liked'    => $this->isLiked,
            'likes_count' => $this->likesCount,
            'user_id'     => $this->userId,
        ];
    }
}
