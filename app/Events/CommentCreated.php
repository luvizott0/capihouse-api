<?php

namespace App\Events;

use App\Models\PostComment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PostComment $comment,
        public readonly int $commentsCount,
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
        return 'CommentCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'post_id'        => $this->comment->post_id,
            'comment'        => $this->comment->toArray(),
            'comments_count' => $this->commentsCount,
        ];
    }
}
