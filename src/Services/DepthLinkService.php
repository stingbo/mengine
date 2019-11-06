<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractCommissionPool;
use Illuminate\Support\Facades\Redis;

class DepthLinkService
{
    /**
     * 第一个价位单初始化节点.
     */
    public function initNode(Order $order)
    {
        $curr = json_encode($order);
        Redis::hset($order->node_link, 'first', $curr);
        Redis::hset($order->node_link, 'last', $curr);
        Redis::hset($order->node_link, $order->node, $curr);
    }

    /**
     * 放入价格点对应的单据.
     */
    public function pushDepthNode(Order $order)
    {
        $order->prev_node = null;
        $order->next_node = null;

        $first = Redis::hget($order->node_link, 'first');
        $last = Redis::hget($order->node_link, 'last');
        if (!$first || $last) {
            $this->initNode($order);
        }

        if (!$last) {
            $last = json_encode($curr);
            Redis::hset($curr->node_link, 'last', $last);
            Redis::hset($curr->node_link, $curr->node, $last);
        } else {
            $last = json_decode($last);
            $last->next_node = $curr->node;
            $curr->prev_node = $last->node;
            $order = json_encode($curr);
            Redis::hset($curr->node_link, $last->node, json_encode($last));
            Redis::hset($curr->node_link, $curr->node, $order);
            Redis::hset($curr->node_link, 'last', $order);
        }
    }

    /**
     * 从价格点对应的单据里删除.
     */
    public function deleteDepthNode(Order $order)
    {
    }
}
