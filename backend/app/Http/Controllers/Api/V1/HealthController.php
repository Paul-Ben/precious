<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /api/v1/health - used by Railway health checks and uptime monitors.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                Cache::put('health-check', 'ok', 10);

                return Cache::get('health-check') === 'ok';
            }),
        ];

        $healthy = ! in_array(false, $checks, true);

        $data = [
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'version' => config('app.version', '0.1.0'),
            'time' => now()->toIso8601String(),
            'checks' => array_map(fn (bool $ok) => $ok ? 'ok' : 'failing', $checks),
        ];

        return $healthy
            ? ApiResponse::success($data, 'Service is healthy.')
            : ApiResponse::error('Service is degraded.', 503, 'SERVICE_DEGRADED', null, ['data' => $data]);
    }

    private function check(callable $probe): bool
    {
        try {
            return $probe() !== false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
