<?php

namespace StingBo\Mengine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Services\CommissionPoolService;

class DeleteOrderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $order;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(CommissionPoolService $service)
    {
        $service->deletePoolOrder($this->order);

        return true;
    }
}
