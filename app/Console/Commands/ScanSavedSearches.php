<?php

namespace App\Console\Commands;

use App\Services\SavedSearchScanner;
use Illuminate\Console\Command;

class ScanSavedSearches extends Command
{
    protected $signature = 'searches:scan {--search= : Scan a single saved search id}';

    protected $description = 'Run saved catalog searches and enqueue availability/new-match alerts';

    public function handle(SavedSearchScanner $scanner): int
    {
        $searchId = $this->option('search') !== null ? (int) $this->option('search') : null;
        $summary = $scanner->scan($searchId);

        $this->info('Saved search scan');
        $this->line("  searches : {$summary['searches']}");
        $this->line("  matched  : {$summary['matched']}");
        $this->line("  notified : {$summary['notified']}");

        if ($summary['errors'] > 0) {
            $this->warn("  errors   : {$summary['errors']}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
