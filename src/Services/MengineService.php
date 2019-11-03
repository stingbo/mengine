<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractOrder;
use StingBo\Mengine\Events\PushQueueEvent;
use StingBo\Mengine\Events\DeleteOrderEvent;
use Illuminate\Support\Facades\Redis;

class MengineService extends AbstractOrder
{
    /**
     * 获取委托单.
     */
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * 入委托队列.
     */
    public function pushCommissionQueue(Order $order)
    {
        // 1. 放入标识池
        $this->pushCommissionHash($order);

        // 2. 入委托队列
        event(new PushQueueEvent($order));
    }

    /**
     * 放入hash标识池.
     */
    public function pushCommissionHash(Order $order)
    {
        Redis::hset($order->symbol, $this->order_hash_field, 1);
    }

    /**
     * 从标识池删除.
     */
    public function deleteHashOrder(Order $order)
    {
        if (Redis::hexists($order->symbol, $this->order_hash_field)) {
            Redis::hdel($order->symbol, $this->order_hash_field);
        }
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
        // 第一步，从标识池删除，避免队列有积压时未消费问题
        $this->deleteHashOrder($order);


        // 第二步，从委托池里删除
        event(new DeleteOrderEvent($order));
    }
}
