<?php

use App\Services\RuntimeConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment('CosplayNesia siap membantu komunitas cosplay Indonesia.');
})->purpose('Display an inspiring message');

Artisan::command('app:validate-runtime', function (RuntimeConfiguration $runtime): void {
    $runtime->assertSafe();
    $this->info('Runtime configuration is safe.');
})->purpose('Validate production runtime invariants before deployment');

Schedule::command('queue:prune-failed --hours=168')->daily()->withoutOverlapping();
Schedule::command('queue:prune-batches --hours=168 --unfinished=336 --cancelled=336')->daily()->withoutOverlapping();
