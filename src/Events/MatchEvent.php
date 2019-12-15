<?php

namespace StingBo\Mengine\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MatchEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    public $match_order;

    public $volume;

    /**
     * Create a new event instance.
     */
    public function __construct($order, $match_order, $volume)
    {
        $this->order = $order;
        $this->match_order = $match_order;
        $this->volume = $volume;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
