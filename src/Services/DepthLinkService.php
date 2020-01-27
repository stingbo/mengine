<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use StingBo\Mengine\Core\Order;

class DepthLinkService
{
    /**
     * 放入价格点对应的单据.
     */
    public function pushDepthNode(Order $order)
    {
        $link_service = new LinkService($order->node_link);

        // 不存在则初始化
        $first = $link_service->getFirst();
        if (!$first) {
            $link_service->init($order);

            return true;
        }
        $last = $link_service->getLast();
        if (!$last) {
            throw new InvalidArgumentException(__METHOD__.' expects last node is not empty.');
        }

        $link_service->setLast($order);
    }

    /**
     * 从价格点对应的单据里删除.
     */
    public function deleteDepthNode(Order $order)
    {
        $link_service = new LinkService($order->node_link);
        $order = $link_service->getCurrent($order->node);
        if (!$order) {
            return false;
        }

        $link_service->deleteNode($order);

        return true;
    }

    /**
     * 放入委托量hash.
     */
    public function pushDepthHash(Order $order)
    {
        Redis::hincrby($order->order_depth_hash_key, $order->order_depth_hash_field, $order->volume);
    }

    /**
     * 从委托量hash里删除.
     */
    public function deleteDepthHash(Order $order)
    {
        Redis::hincrby($order->order_depth_hash_key, $order->order_depth_hash_field, bcmul(-1, $order->volume));
    }

    /**
     * 放入深度池.
     */
    public function pushZset(Order $order)
    {
        Redis::zadd($order->order_list_zset_key, $order->price, $order->price);
    }

    /**
     * 从深度池删除.
     */
    public function deleteZset(Order $order)
    {
        // 判断对应的委托量，如果没有了则从深度列表里删除
        $volume = Redis::hget($order->order_depth_hash_key, $order->order_depth_hash_field);
        var_dump($volume);
        if ($volume <= 0) {
            Redis::zrem($order->order_list_zset_key, $order->price);
        }
    }
}
