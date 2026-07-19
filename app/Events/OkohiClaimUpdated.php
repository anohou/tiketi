<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OkohiClaimUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $claimId,
        public readonly string $status,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('okohi.claims.'.$this->claimId)];
    }

    public function broadcastAs(): string
    {
        return 'claim.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'claim_id' => $this->claimId,
            'status' => $this->status,
            'payload' => $this->payload,
        ];
    }
}
