<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Health Check Controller
 *
 * Provides a /api/health endpoint for deployment monitoring and health checks.
 * Used by deployment scripts, load balancers, and monitoring tools.
 */
class HealthController extends Controller
{
    /**
     * Perform health check
     *
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        $status = 'healthy';
        $checks = [];

        // Check database connectivity
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'connected';
        } catch (\Exception $e) {
            $checks['database'] = 'disconnected';
            $status = 'unhealthy';
        }

        // Check cache accessibility
        try {
            Cache::put('health-check', true, 10);
            $checks['cache'] = Cache::has('health-check') ? 'accessible' : 'inaccessible';
        } catch (\Exception $e) {
            $checks['cache'] = 'inaccessible';
            $status = 'unhealthy';
        }

        // Optional: Check storage writability
        // try {
        //     $testFile = storage_path('framework/cache/health-check.tmp');
        //     file_put_contents($testFile, 'test');
        //     $checks['storage'] = file_exists($testFile) ? 'writable' : 'readonly';
        //     @unlink($testFile);
        // } catch (\Exception $e) {
        //     $checks['storage'] = 'readonly';
        //     $status = 'unhealthy';
        // }

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'debug' => config('app.debug'),
            ],
        ] + $checks, $status === 'healthy' ? 200 : 503);
    }
}
