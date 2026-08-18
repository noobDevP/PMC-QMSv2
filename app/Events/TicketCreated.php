<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ;

    public function __construct( = [])
    {
        ->data = ;
    }

    public function broadcastOn()
    {
        return new Channel('qms');
    }

    public function broadcastAs()
    {
        return 'TICKET_CREATED';
    }

    public function broadcastWith()
    {
        return ->data;
    }
}
