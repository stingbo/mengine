<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use StingBo\Mengine\Core\AbstractMengine;
use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Exceptions\DeleteOrderException;
use StingBo\Mengine\Jobs\DeleteOrderJob;
use StingBo\Mengine\Jobs\PushQueueJob;

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
        dispatch((new PushQueueJob($order))->allOnQueue($order->symbol));
    }

    /**
     * 放入hash标识池.
     */
    public function pushHash(Order $order)
    {
        BookTransaction::run($order->symbol, function (BookTransaction $redis) use ($order) {
            if ($redis->hget($order->order_hash_key, $order->order_hash_field) === null) {
                $redis->hset($order->order_hash_key, $order->order_hash_field, serialize([$order, []]));
            }
        });
    }

    /**
     * 从hash标识池判断委托是否已经删除.
     */
    public function isHashDeleted(Order $order): bool
    {
        if (Redis::hexists($order->order_hash_key, $order->order_hash_field)) {
            return false;
        }

        return true;
    }

    /**
     * 从hash标识池删除.
     */
    public function deleteOrder(Order $order, $volume = null)
    {
        $order = clone $order;
        if ($volume === null && $this->cancelPendingOrder($order)) {
            return;
        }
        if ($volume !== null) {
            $order->setVolume($volume);
        }

        (new DepthLinkService())->useExistingPriceLevel($order);
        $link_service = new LinkService($order->node_link);
        $node = $link_service->getNode($order->node);
        if (!$node) {
            throw new DeleteOrderException('order not exist', DeleteOrderException::NOT_EXIST);
        }
        if (!$node->hasSameIdentity($order)) {
            throw new DeleteOrderException('order message not match', DeleteOrderException::NOT_MATCH);
        }

        dispatch((new DeleteOrderJob($order, $volume !== null))->allOnQueue($order->symbol));
    }

    private function cancelPendingOrder(Order $order): bool
    {
        return BookTransaction::run($order->symbol, function (BookTransaction $redis) use ($order) {
            $marker = $redis->hget($order->order_hash_key, $order->order_hash_field);
            if ($marker === null) {
                return false;
            }

            // Old markers lack order details. The original push job will
            // validate this request against its own payload before canceling.
            [$pending, $cancellations] = $marker === '1' ? [null, []] : unserialize($marker);
            if ($pending && !$pending->isSamePendingOrder($order)) {
                throw new DeleteOrderException('order message not match', DeleteOrderException::NOT_MATCH);
            }
            foreach ($cancellations as $cancellation) {
                if ($cancellation->isSamePendingOrder($order)) {
                    return true;
                }
            }
            $cancellations[] = $order;
            $redis->hset($order->order_hash_key, $order->order_hash_field, serialize([$pending, $cancellations]));

            return true;
        });
    }

    /**
     * 从标识池删除.
     */
    public function deleteHashOrder(Order $order)
    {
        BookTransaction::run($order->symbol, function (BookTransaction $redis) use ($order) {
            if ($redis->hget($order->order_hash_key, $order->order_hash_field) !== null) {
                $redis->hdel($order->order_hash_key, $order->order_hash_field);
            }
        });
    }

    /**
     * 获取深度列表.
     */
    public function getDepth($symbol, $transaction): array
    {
        [$buy, $sell] = config('mengine.mengine.transaction');
        if ($buy == $transaction) {
            $prices = Redis::zrevrange($symbol.':'.$transaction, 0, -1);
        } elseif ($sell == $transaction) {
            $prices = Redis::zrange($symbol.':'.$transaction, 0, -1);
        } else {
            return [];
        }

        return $this->depthForPrices($symbol, $prices);
    }

    /**
     * 获取反向深度列表.
     */
    public function getMutexDepth($symbol, $transaction, $price): array
    {
        return $this->depthForPrices($symbol, $this->getMatchingPrices($symbol, $transaction, $price));
    }

    public function getMatchingPrices($symbol, $transaction, $price): array
    {
        [$buy, $sell] = config('mengine.mengine.transaction');
        if ($buy == $transaction) {
            return Redis::zRangeByScore($symbol.':'.$sell, '-inf', $price);
        }
        if ($sell == $transaction) {
            return Redis::zRevRangeByScore($symbol.':'.$buy, '+inf', $price);
        }

        return [];
    }

    private function depthForPrices($symbol, array $prices): array
    {
        if (!$prices) {
            return [];
        }

        $key = $symbol.':depth';
        $fields = array_map(fn ($price) => $key.':'.$price, $prices);
        $volumes = array_values(Redis::hmget($key, $fields));
        $depths = [];
        foreach ($prices as $index => $price) {
            $depths[$index] = ['price' => $price, 'volume' => $volumes[$index]];
        }

        return $depths;
    }
}
