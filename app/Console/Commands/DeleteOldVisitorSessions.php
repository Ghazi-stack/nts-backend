<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VisitorSession;
use Carbon\Carbon;

class DeleteOldVisitorSessions extends Command
{
    protected $signature = 'visitor-sessions:cleanup';

    protected $description = 'Delete visitor sessions older than 24 hours';

    public function handle()
    {
        $deleted = VisitorSession::where(
            'created_at',
            '<',
            Carbon::now()->subHours(24)
        )->delete();

        $this->info("Deleted {$deleted} records.");
    }
}