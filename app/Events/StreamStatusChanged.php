<?php

namespace App\Events;

use App\Models\Channel;
use Illuminate\Broadcasting\Channel as BroadcastChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Channel $channel,
        public readonly string  $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [new BroadcastChannel('channels')];
    }

    public function broadcastAs(): string
    {
        return 'stream.status';
    }

    public function broadcastWith(): array
    {
        return [
            'id'            => $this->channel->id,
            'name'          => $this->channel->name,
            'stream_status' => $this->newStatus,
            'push_status'   => $this->channel->push_status,
            'dvr_status'    => $this->channel->dvr_status,
            'source_live'   => $this->channel->source_live,
            'last_live_at'  => $this->channel->last_live_at?->toISOString(),
        ];
    }
}
