<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\PushQueueEvent;
use StingBo\Mengine\Services\CommissionPoolService;

class PushQueueEventListener
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
    public function handle(PushQueueEvent $event)
    {
        $this->service->pushPool($event->order);

        return true;
    }
}
