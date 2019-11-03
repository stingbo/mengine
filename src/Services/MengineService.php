<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractOrder;
use Illuminate\Support\Facades\Redis;

class MengineService extends AbstractOrder
{
    public function getOrder()
    {
        return $this->order;
    }

    public function pushQueue(Order $order)
    {
        $key = 'string:test';
        $value = 'Hello-World';
        $info = Redis::set($key, $value);
    }

    public function pushHash(Order $order)
    {
        $info = Redis::hset($order->symbol, [$this->order_hash_key, 1]);
    }

    public function isDelete(Order $order)
    {
    }

    public function deleteOrder(Order $order)
    {
        return 'bb';
    }
}
