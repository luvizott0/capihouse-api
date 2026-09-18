<?php

namespace App\Events;

use App\Models\GroupMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GroupMessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly GroupMessage $message,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('group.'.$this->message->group_id);
    }

    public function broadcastAs(): string
    {
        return 'GroupMessageUpdated';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message->toArray()];
    }
}
