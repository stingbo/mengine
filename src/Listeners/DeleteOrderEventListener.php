<?php

namespace StingBo\Mengine\Listeners;

use StingBo\Mengine\Events\DeleteOrderEvent;
use StingBo\Mengine\Services\CommissionPoolService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class DeleteOrderEventListener
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
    public function handle(DeleteOrderEvent $event)
    {
        $this->service->deleteCommissionPoolOrder($event->order);

        return true;
    }
}
