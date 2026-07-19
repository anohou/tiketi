<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\TicketSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CancelOrReverseOkohiClaimJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = [10, 30, 60, 120, 240];

    protected $transactionId;

    protected $action; // 'cancel' or 'reverse'

    protected $tenantId;

    protected $ticketId; // optional to mark refunded

    /**
     * Create a new job instance.
     */
    public function __construct(string $transactionId, string $action, ?string $tenantId = null, ?string $ticketId = null)
    {
        $this->transactionId = $transactionId;
        $this->action = $action;
        $this->tenantId = $tenantId;
        $this->ticketId = $ticketId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->tenantId) {
            tenancy()->initialize($this->tenantId);
        }

        $settings = TicketSetting::getSettings();
        if (! $settings->hasOkohiIntegration()) {
            throw new \Exception("Cannot process Okohi {$this->action}: integration not enabled.");
        }

        $baseUrl = rtrim(config('services.okohi.base_url'), '/');
        if (! $baseUrl) {
            throw new \Exception('services.okohi.base_url is not configured.');
        }

        if ($this->action === 'reverse') {
            $response = Http::timeout(15)
                ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
                ->post("{$baseUrl}/api/v1/partner/reward-claims/{$this->transactionId}/reverse");
        } else {
            $response = Http::timeout(15)
                ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
                ->delete("{$baseUrl}/api/v1/partner/reward-claims/{$this->transactionId}");
        }

        // If the claim is already cancelled/reversed, or not found on cancel/delete (e.g. 404), treat as success (idempotent)
        if ($response->status() === 404 && $this->action === 'cancel') {
            Log::info("Okohi claim {$this->transactionId} already deleted/not found.");
        } elseif (! $response->successful()) {
            throw new \Exception("Failed to {$this->action} Okohi claim: ".$response->status().' '.$response->body());
        }

        if ($this->ticketId && $this->action === 'reverse') {
            $ticket = Ticket::find($this->ticketId);
            if ($ticket) {
                $ticketSettings = $ticket->settings ?? [];
                $ticketSettings['okohi_refund_status'] = 'refunded';
                $ticket->update(['settings' => $ticketSettings]);
            }
        }
    }

    /**
     * Handle job failure definitively.
     */
    public function failed(\Throwable $exception): void
    {
        if ($this->tenantId) {
            tenancy()->initialize($this->tenantId);
        }

        if ($this->ticketId) {
            $ticket = Ticket::find($this->ticketId);
            if ($ticket) {
                $ticketSettings = $ticket->settings ?? [];
                $ticketSettings['okohi_refund_status'] = 'refund_failed';
                $ticketSettings['okohi_refund_error'] = $exception->getMessage();
                $ticket->update(['settings' => $ticketSettings]);
            }
        }
    }
}
