<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\PushQueueEvent;
use StingBo\Mengine\Services\CommissionPoolService;

class PushQueueEventListener
{
    public CommissionPoolService $service;

    /**
     * Create the event listener.
     */
    public function __construct(CommissionPoolService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle the event.
     */
    public function handle(PushQueueEvent $event): bool
    {
        $this->service->pushPool($event->order);

        return true;
    }
}
