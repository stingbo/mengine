<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;

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
