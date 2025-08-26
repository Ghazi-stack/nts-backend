<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;

use App\Events\ShipmentCreated;
use App\Listeners\SendShipmentReadyEmail;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        ShipmentCreated::class => [
            SendShipmentReadyEmail::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
