<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractMengine;
use StingBo\Mengine\Events\PushQueueEvent;
use StingBo\Mengine\Events\DeleteOrderEvent;
use Illuminate\Support\Facades\Redis;

class MengineService extends AbstractMengine
{
    /**
     * 入委托队列.
     */
    public function pushQueue(Order $order)
    {
        // 1. 放入标识池
        $this->pushHash($order);

        // 2. 入委托队列
        event(new PushQueueEvent($order));
    }

    /**
     * 放入hash标识池.
     */
    public function pushHash(Order $order)
    {
        Redis::hset($order->order_hash_key, $order->order_hash_field, 1);
    }

    /**
     * 从hash标识池判断委托是否已经删除.
     */
    public function isHashDeleted(Order $order)
    {
        if (Redis::hexists($order->order_hash_key, $order->order_hash_field)) {
            return false;
        }

        return true;
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

    /**
     * 从标识池删除.
     */
    public function deleteHashOrder(Order $order)
    {
        if (Redis::hexists($order->order_hash_key, $order->order_hash_field)) {
            Redis::hdel($order->order_hash_key, $order->order_hash_field);
        }
    }

    /**
     * 获取深度列表.
     */
    public function getDepth($symbol, $transaction)
    {
        $depths = [];
        if (config('mengine.mengine.transaction')[0] == $transaction) {
            $prices = Redis::zrevrange($symbol.':'.$transaction, 0, -1);
            foreach ($prices as $key => $price) {
                $volume = Redis::hget($symbol.':depth', $symbol.':depth:'.$price);
                $depths[$key] = [
                    'price' => $price,
                    'volume' => $volume,
                ];
            }
        } elseif (config('mengine.mengine.transaction')[1] == $transaction) {
            $prices = Redis::zrange($symbol.':'.$transaction, 0, -1);
            foreach ($prices as $key => $price) {
                $volume = Redis::hget($symbol.':depth', $symbol.':depth:'.$price);
                $depths[$key] = [
                    'price' => $price,
                    'volume' => $volume,
                ];
            }
        }

        return $depths;
    }

    /**
     * 获取反向深度列表.
     */
    public function getMutexDepth($symbol, $transaction, $price)
    {
        $depths = [];
        if (config('mengine.mengine.transaction')[0] == $transaction) {
            $transaction = config('mengine.mengine.transaction')[1];
            $prices = Redis::zRangeByScore($symbol.':'.$transaction, '-inf', $price);
            foreach ($prices as $key => $price) {
                $volume = Redis::hget($symbol.':depth', $symbol.':depth:'.$price);
                $depths[$key] = [
                    'price' => $price,
                    'volume' => $volume,
                ];
            }
        } elseif (config('mengine.mengine.transaction')[1] == $transaction) {
            $transaction = config('mengine.mengine.transaction')[0];
            $prices = Redis::zRevRangeByScore($symbol.':'.$transaction, '+inf', $price);
            foreach ($prices as $key => $price) {
                $volume = Redis::hget($symbol.':depth', $symbol.':depth:'.$price);
                $depths[$key] = [
                    'price' => $price,
                    'volume' => $volume,
                ];
            }
        }

        return $depths;
    }
}
