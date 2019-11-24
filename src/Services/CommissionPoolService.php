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

        $ms_service->deleteHashOrder($order);
        $list = $ms_service->getMutexDepth($order->symbol, $order->transaction, $order->price);
        if ($list) {
            // 撮合
            $order = $this->matchUp($order, $list);
            if (!$order) {
                return false;
            }
        }

        // 深度列表与数量更新
        $this->pushZset($order);
        $this->pushDepthHash($order);

        // 节点更新
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

    /**
     * 撮合.
     *
     * @param object $order 下单
     * @param array  $list  价格匹配部分
     *
     * @return mix
     */
    public function matchUp(Order $order, $list)
    {
        // 1. 撮合#TODO

        // 2. 删除成交的单据/或部分单据
        //$this->deletePoolOrder();

        // 3. 返回order未撮合部分(如果有)
        return false;
    }
}
