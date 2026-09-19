<?php

namespace App\Http\Controllers;

use App\Services\RuntimeConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(RuntimeConfiguration $runtime): JsonResponse
    {
        try {
            $runtime->assertSafe();
            DB::select('select 1');
            $key = 'readiness:'.Str::uuid();
            Cache::put($key, 'ready', 10);
            $cacheReady = Cache::pull($key) === 'ready';

            if (! $cacheReady) {
                throw new \RuntimeException('Cache round-trip failed.');
            }

            return response()->json(['status' => 'ready']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'not_ready'], 503);
        }
    }
}
