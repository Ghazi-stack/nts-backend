<?php

namespace App\Events;

use App\Http\Controllers\api\Admin\RealtimeController;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RealtimeStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $storeId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('store.' . $this->storeId)];
    }

    public function broadcastAs(): string
    {
        return 'realtime.updated';
    }

    public function broadcastWith(): array
    {
        return app(RealtimeController::class)->build($this->storeId);
    }
}
