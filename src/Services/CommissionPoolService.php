<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractCommissionPool;
use Illuminate\Support\Facades\Redis;

class CommissionPoolService extends AbstractCommissionPool
{
    /**
     * 放入委托池.
     */
    public function pushPool(Order $order)
    {
        $ms_service = new MengineService($order);

        if ($ms_service->isHashDeleted($order)) {
            return false;
        }

        $this->pushZset($order);

        $this->pushDepthHash($order);

        $ms_service->deleteHashOrder($order);

        // 放入节点
        $depth_link = new DepthLinkService();
        $depth_link->pushDepthNode($order);
    }

    /**
     * 从委托池删除.
     */
    public function deletePoolOrder(Order $order)
    {
        $node = Redis::hget($order->node_link, $order->node);
        if (!$node) {
            return false;
        }
        $this->deleteDepthHash($order);

        $volume = Redis::hget($order->order_depth_hash_key, $order->order_depth_hash_field);
        if ($volume <= 0) {
            $this->deleteZset($order);
        }

        // 从节点删除
        $depth_link = new DepthLinkService();
        $depth_link->deleteDepthNode($order);
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
        Redis::zrem($order->order_list_zset_key, $order->price);
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
}
