<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LockProgressLogs extends Command
{
    protected $signature   = 'progress-logs:lock';
    protected $description = 'Lock phase_progress_logs rows whose log_date is older than the previous working day. After lock, employees cannot edit/delete; PMs can manually unlock.';

    public function handle(): int
    {
        $previousWorkingDay = Carbon::today()->subDay();
        while ($previousWorkingDay->isWeekend()) {
            $previousWorkingDay->subDay();
        }

        $locked = DB::table('phase_progress_logs')
            ->whereNull('locked_at')
            ->whereDate('log_date', '<', $previousWorkingDay->toDateString())
            ->update(['locked_at' => now(), 'updated_at' => now()]);

        $this->info("Locked {$locked} progress log(s) older than {$previousWorkingDay->toDateString()}.");

        return self::SUCCESS;
    }
}
