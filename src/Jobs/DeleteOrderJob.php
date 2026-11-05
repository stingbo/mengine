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

    public $cancellationId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order, bool $partial = false)
    {
        $this->order = $order;
        if ($partial) {
            $this->cancellationId = bin2hex(random_bytes(16));
        }
    }

    /**
     * Execute the job.
     */
    public function handle(CommissionPoolService $service)
    {
        if ($this->cancellationId !== null) {
            return $service->reducePoolOrder($this->order, $this->cancellationId);
        }

        $service->deletePoolOrder($this->order);

        return true;
    }
}
