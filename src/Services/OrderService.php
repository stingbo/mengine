<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;

class OrderService
{
    public function setOrder($uuid, $oid, $symbol, $transaction, $volume, $price): Order
    {
        return new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
    }
}
