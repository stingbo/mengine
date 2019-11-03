<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\DeleteOrderEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOrderEventListener
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
    public function handle(DeleteOrderEvent $event)
    {
        $order = $event->order;

        return true;
    }
}
