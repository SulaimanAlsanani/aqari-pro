<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneOwnerLoginEvents extends Command
{
    protected $signature = 'aqari:prune-login-events
        {--days=90 : Retention period, 30-365 days}
        {--execute : Actually delete expired login audit events}';

    protected $description = 'Safely review or prune expired owner login audit events.';

    public function handle(): int
    {
        $input = (string) $this->option('days');
        if (! ctype_digit($input) || (int) $input < 30 || (int) $input > 365) {
            $this->error('Retention days must be a whole number between 30 and 365.');

            return self::FAILURE;
        }

        $threshold = now()->subDays((int) $input);
        $query = DB::table('owner_login_events')->where('created_at', '<', $threshold);
        $eligible = (clone $query)->count();

        if (! $this->option('execute')) {
            $this->info('Dry run: '.$eligible.' old security event(s) eligible for pruning.');
            $this->line('No records changed. Add --execute to apply this retention policy.');

            return self::SUCCESS;
        }

        $removed = $query->delete();
        $this->info('Removed '.$removed.' expired login event(s).');

        return self::SUCCESS;
    }
}
