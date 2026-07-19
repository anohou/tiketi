<?php

namespace App\Console\Commands;

use App\Jobs\CancelOrReverseOkohiClaimJob;
use App\Models\OkohiRewardRequest;
use App\Models\Tenant;
use App\Models\TripSeatOccupancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CleanupExpiredOkohiRequests extends Command
{
    protected $signature = 'okohi:cleanup-expired';

    protected $description = 'Clean up expired Okohi reward requests and seat holds.';

    public function handle(): int
    {
        $cleaned = 0;

        if (class_exists(Tenant::class) && Schema::hasTable('tenants') && Tenant::query()->exists()) {
            foreach (Tenant::query()->cursor() as $tenant) {
                try {
                    tenancy()->initialize($tenant);
                    $cleaned += $this->cleanupCurrentDatabase($tenant->id);
                } catch (Throwable $exception) {
                    $this->error("Unable to clean up expired requests for tenant {$tenant->id}: {$exception->getMessage()}");
                } finally {
                    tenancy()->end();
                }
            }
        } else {
            $cleaned = $this->cleanupCurrentDatabase(null);
        }

        $this->info("Cleaned up {$cleaned} expired Okohi request(s).");

        return self::SUCCESS;
    }

    private function cleanupCurrentDatabase(?string $tenantId): int
    {
        if (! Schema::hasTable('okohi_reward_requests')) {
            return 0;
        }

        $expiredRequestIds = OkohiRewardRequest::whereIn('status', ['pending', 'approved_pending_cash'])
            ->where('expires_at', '<', now())
            ->pluck('id');

        if ($expiredRequestIds->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($expiredRequestIds as $requestId) {
            $oldStatus = null;
            $shouldCancelOrReverse = DB::transaction(function () use ($requestId, &$oldStatus) {
                $req = OkohiRewardRequest::where('id', $requestId)
                    ->lockForUpdate()
                    ->first();

                if ($req && in_array($req->status, ['pending', 'approved_pending_cash']) && $req->expires_at->isPast()) {
                    $oldStatus = $req->status;
                    TripSeatOccupancy::where('okohi_reward_request_id', $req->id)->delete();
                    $req->update(['status' => 'expired']);

                    return true;
                }

                return false;
            });

            if ($shouldCancelOrReverse) {
                $req = OkohiRewardRequest::find($requestId);
                if ($req && $req->okohi_transaction_id) {
                    $action = $oldStatus === 'approved_pending_cash' ? 'reverse' : 'cancel';
                    CancelOrReverseOkohiClaimJob::dispatch($req->okohi_transaction_id, $action, $tenantId);
                }
                $count++;
            }
        }

        return $count;
    }
}
