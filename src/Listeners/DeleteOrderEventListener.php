<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\DeleteOrderEvent;
use StingBo\Mengine\Services\CommissionPoolService;

class DeleteOrderEventListener
{
    public $service;

    /**
     * Create the event listener.
     */
    public function __construct(CommissionPoolService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle the event.
     *
     * @param object $event
     */
    public function handle(DeleteOrderEvent $event)
    {
        $this->service->deletePoolOrder($event->order);

        return true;
    }
}
