<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractOrder;

class OrderService extends AbstractOrder
{
    public function setOrder(Order $order)
    {
        return $order;
    }
}
