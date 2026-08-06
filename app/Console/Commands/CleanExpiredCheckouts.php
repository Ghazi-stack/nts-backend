<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CheckoutFormData;

class CleanExpiredCheckouts extends Command
{
    protected $signature = 'checkout:clean-expired';
    protected $description = 'Delete checkout form data older than 24 hours';

    public function handle()
    {
        $count = CheckoutFormData::where('updated_at', '<', now()->subHours(24))
            ->whereNotNull('session_id')
            ->delete();

        $this->info("Deleted {$count} expired checkout records.");
    }
}
