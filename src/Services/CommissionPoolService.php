<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;

class CommissionPoolService
{
    /**
     * 放入委托池.
     */
    public function pushCommissionPool(Order $order)
    {
        echo 'push commission pool';
    }

    /**
     * 从委托池删除.
     */
    public function deleteCommissionPoolOrder(Order $order)
    {
        echo 'delete order from commission pool';
    }
}
