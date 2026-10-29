<?php

use App\Services\LedgerReconciliation;
use App\Services\RuntimeConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment('CosplayNesia siap membantu komunitas cosplay Indonesia.');
})->purpose('Display an inspiring message');

Artisan::command('app:validate-runtime', function (RuntimeConfiguration $runtime): void {
    $runtime->assertSafe();
    $this->info('Runtime configuration is safe.');
})->purpose('Validate production runtime invariants before deployment');

Artisan::command('ledger:reconcile {--from=} {--to=} {--json}', function (LedgerReconciliation $reconciliation): int {
    $from = $this->option('from') ? Carbon::parse($this->option('from')) : null;
    $to = $this->option('to') ? Carbon::parse($this->option('to')) : null;

    $report = $reconciliation->report($from, $to);

    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info('Ledger reconciliation');
        $this->line('  transactions: '.$report['transactions']);
        $this->line('  total debit : '.$report['total_debit']);
        $this->line('  total credit: '.$report['total_credit']);
        $this->line('  balanced    : '.($report['balanced'] ? 'yes' : 'NO'));
        $this->line('  pending settlement: '.$report['pending_settlement_count'].' fulfillment(s) / '.$report['pending_settlement_amount'].' IDR');
        $this->line('  provider reconciliation: '.$report['provider_reconciliation']);

        if ($report['exceptions'] === []) {
            $this->info('  exceptions  : none');
        } else {
            foreach ($report['exceptions'] as $exception) {
                $this->warn('  exception   : '.$exception['type'].' '.json_encode($exception['detail']));
            }
        }
    }

    return $report['clean'] ? self::SUCCESS : self::FAILURE;
})->purpose('Report ledger integrity and settlement exceptions without mutating anything');

Schedule::command('queue:prune-failed --hours=168')->daily()->withoutOverlapping();
Schedule::command('queue:prune-batches --hours=168 --unfinished=336 --cancelled=336')->daily()->withoutOverlapping();
Schedule::command('data:prune')->daily()->withoutOverlapping();
// Saved-search alerts do not need to be real-time; twice a day keeps the "match" cursor moving
// without hammering the catalog or burying the shopper in notifications.
Schedule::command('searches:scan')->twiceDaily(8, 18)->withoutOverlapping();
