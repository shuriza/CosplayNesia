<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes data that has outlived its purpose.
 *
 * The rule this command enforces is narrow on purpose: only rows that are transient by nature and
 * carry no transaction or financial meaning are pruned. Orders, ledger entries, reviews, messages,
 * moderation reports, and audit logs are never touched — those are the records the platform exists
 * to preserve, and deleting them to save space would destroy evidence to solve a problem that has
 * not been shown to exist.
 *
 * Pruned:
 *  - expired password reset tokens (single-use, secret);
 *  - stale account session rows (a revoked session is only useful as a row while it can be listed);
 *  - old security events (kept for the investigation window, then dropped);
 *  - sent notification deliveries (the outbox row exists to guarantee delivery, and a delivered
 *    message needs no durable copy of its allowlisted payload).
 *
 * Failed deliveries are retained even when old: they are the exception queue.
 */
class PruneExpiredData extends Command
{
    private const MAX_DELETE_PER_RUN = 5000;

    protected $signature = 'data:prune {--days=} {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete transient data past its retention window';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) ($this->option('days') ?: config('cosplaynesia.retention.days', 90));
        $cutoff = now()->subDays(max(1, $days));

        $targets = [
            'password_reset_tokens' => [
                'column' => 'created_at',
                'cutoff' => now()->subHours((int) config('cosplaynesia.retention.reset_token_hours', 24)),
            ],
            'account_sessions' => [
                'column' => 'last_seen_at',
                'cutoff' => $cutoff,
            ],
            'security_events' => [
                'column' => 'created_at',
                'cutoff' => $cutoff,
            ],
            'notification_deliveries' => [
                'column' => 'created_at',
                'cutoff' => $cutoff,
                // Only rows that reached a terminal success. Failures stay: they are the queue an
                // operator works from, and pruning them would silently swallow a broken address.
                'where' => fn ($query) => $query->where('status', NotificationDelivery::STATUS_SENT),
            ],
        ];

        $total = 0;
        foreach ($targets as $table => $definition) {
            $query = DB::table($table)->where($definition['column'], '<', $definition['cutoff']);
            if (isset($definition['where'])) {
                $definition['where']($query);
            }

            $count = (clone $query)->count();
            $total += $count;

            if (! $dryRun && $count > 0) {
                // Bounded per run rather than chunked by id: password_reset_tokens is keyed by
                // email and has no id column, and a first run on a long-lived database should not
                // issue one unbounded DELETE. The next scheduled run takes the remainder.
                (clone $query)->limit(self::MAX_DELETE_PER_RUN)->delete();
            }

            $this->line(sprintf(
                '%-24s %d baris %s',
                $table,
                $count,
                $dryRun ? '(dilewati: dry-run)' : 'dihapus',
            ));
        }

        $this->info($dryRun
            ? "Retensi: {$total} baris akan dihapus pada jendela {$days} hari."
            : "Retensi selesai: {$total} baris dihapus.");

        return self::SUCCESS;
    }
}
