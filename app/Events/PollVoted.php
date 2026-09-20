<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PollVoted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $postId,
        public readonly int $pollId,
        public readonly int $totalVotes,
        public readonly array $options, // Array of ['id' => option_id, 'votes_count' => votes_count]
        public readonly ?int $groupId = null,
        public readonly ?int $eventId = null,
    ) {}

    public function broadcastOn(): Channel
    {
        if ($this->groupId) {
            return new PrivateChannel('group.'.$this->groupId);
        }
        if ($this->eventId) {
            return new PrivateChannel('event.'.$this->eventId);
        }

        return new Channel('posts');
    }

    public function broadcastAs(): string
    {
        return 'PollVoted';
    }

    public function broadcastWith(): array
    {
        return [
            'post_id' => $this->postId,
            'poll_id' => $this->pollId,
            'total_votes' => $this->totalVotes,
            'options' => $this->options,
        ];
    }
}
