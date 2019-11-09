<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

class DepthLinkService
{
    /**
     * 第一个价位单初始化节点.
     */
    public function initNode(Order $order)
    {
        $order->is_first = true;
        $order->is_last = true;

        Redis::hset($order->node_link, 'first', $order->node);
        Redis::hset($order->node_link, 'last', $order->node);
        Redis::hset($order->node_link, $order->node, json_encode($order));
    }

    /**
     * 放入价格点对应的单据.
     */
    public function pushDepthNode(Order $order)
    {
        // 不存在则初始化
        $first_pointer = Redis::hget($order->node_link, 'first');
        if (!$first_pointer) {
            $this->initNode($order);

            return true;
        }
        $last_pointer = Redis::hget($order->node_link, 'last');
        if (!$last_pointer) {
            $this->initNode($order);

            return true;
        }
        $last = Redis::hget($order->node_link, $last_pointer);
        if (!$last) {
            throw new InvalidArgumentException(__METHOD__.' expects last node is not empty.');
        }
        $last = json_decode($last);
        $last->is_last = false;
        $last->next_node = $order->node;
        $order->prev_node = $last->node;
        Redis::hset($last->node_link, $last->node, json_encode($last));

        // 设置指针
        Redis::hset($order->node_link, 'last', $order->node);

        $order->is_last = true;
        Redis::hset($order->node_link, $order->node, json_encode($order));
    }

    /**
     * 从价格点对应的单据里删除.
     */
    public function deleteDepthNode(Order $order)
    {
    }
}
