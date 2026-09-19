<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class RuntimeConfiguration
{
    public function assertSafe(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $requirements = [
            'APP_DEBUG must be false' => ! config('app.debug'),
            'APP_URL must use HTTPS' => str_starts_with((string) config('app.url'), 'https://'),
            'PostgreSQL must be the default database' => DB::getDefaultConnection() === 'pgsql',
            'The queue must be asynchronous' => config('queue.default') !== 'sync',
            'Sessions must use the database' => config('session.driver') === 'database',
            'The cache must use the database' => config('cache.default') === 'database',
            'Object storage must use S3' => config('filesystems.default') === 's3',
        ];

        $invalid = array_keys(array_filter($requirements, fn (bool $valid): bool => ! $valid));
        if ($invalid !== []) {
            throw new RuntimeException('Production configuration is unsafe: '.implode('; ', $invalid).'.');
        }
    }
}
