<?php

namespace App\Events;

use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Post $post,
    ) {}

    public function broadcastOn(): Channel
    {
        if ($this->post->group_id) {
            return new PrivateChannel('group.' . $this->post->group_id);
        }
        return new Channel('posts');
    }

    public function broadcastAs(): string
    {
        return 'PostUpdated';
    }

    public function broadcastWith(): array
    {
        return ['post' => $this->post->toArray()];
    }
}
