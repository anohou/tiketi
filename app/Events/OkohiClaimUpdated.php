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
        public readonly ?string $tenantId = null,
        public readonly ?string $tripId = null,
        public readonly ?int $seatNumber = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new Channel('okohi.claims.'.$this->claimId)];

        $tId = $this->tenantId ?? (tenancy()->initialized ? tenant('id') : null);
        if ($tId) {
            $channels[] = new Channel('tenant.'.$tId.'.okohi');
        }

        if ($this->tripId) {
            $channels[] = new Channel('trips.'.$this->tripId);
        }

        return $channels;
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
            'tenant_id' => $this->tenantId ?? (tenancy()->initialized ? tenant('id') : null),
            'trip_id' => $this->tripId,
            'seat_number' => $this->seatNumber,
        ];
    }
}
