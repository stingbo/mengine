<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\PushQueueEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PushQueueEventListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(PushQueueEvent $event)
    {
        $order = $event->order;

        return true;
    }
}
