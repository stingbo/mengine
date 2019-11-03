<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractOrder;

class OrderService
{
    public function getOrder()
    {
        return $this->order;
    }

    public function push(Order $order)
    {
    }
}
