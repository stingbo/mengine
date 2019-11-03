<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\PushQueueEvent;
use StingBo\Mengine\Services\CommissionPoolService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PushQueueEventListener
{
    public $service;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(CommissionPoolService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(PushQueueEvent $event)
    {
        $this->service->pushCommissionPool($event->order);

        return true;
    }
}
