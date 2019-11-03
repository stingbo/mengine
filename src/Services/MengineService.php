<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractOrder;
use StingBo\Mengine\Events\PushQueueEvent;
use Illuminate\Support\Facades\Redis;

class MengineService extends AbstractOrder
{
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * 入委托队列.
     */
    public function pushQueue(Order $order)
    {
        $this->pushHash($order);

        event(new PushQueueEvent($order));
    }

    /**
     * 放入hash标识池.
     */
    public function pushHash(Order $order)
    {
        Redis::hset($order->symbol, [$this->order_hash_field, 1]);
    }

    /**
     * 从hash标识池判断委托是否已经删除.
     */
    public function isHashDelete(Order $order)
    {
        if (Redis::hexists($order->symbol, $this->order_hash_field)) {
            return true;
        }

        return false;
    }

    /**
     * 从hash标识池删除.
     */
    public function deleteOrder(Order $order)
    {
        // 第一步，先删除标识池，避免队列有积压时队列慢问题
        if (Redis::hexists($order->symbol, $this->order_hash_field)) {
            Reds::hdel($order->symbol, $this->order_hash_field);
        }


        // 第二步，删除委托池
        if (true) {
        }
    }

    /**
     * 放入委托池.
     */
    public function pushCommissionPool(Order $order)
    {
    }
}
