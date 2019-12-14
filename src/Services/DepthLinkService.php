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
        $link_service = new LinkService($order->node_link);

        // 不存在则初始化
        $first = $link_service->getFirst();
        if (!$first) {
            $this->initNode($order);

            return true;
        }
        $last = $link_service->getLast();
        if (!$last) {
            throw new InvalidArgumentException(__METHOD__.' expects last node is not empty.');
        }
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
        $order = Redis::hget($order->node_link, $order->node);
        if (!$order) {
            return false;
        }
        $order = json_decode($order);

        if ($order->is_first && $order->is_last) { // 只有一个节点则全删除
            Redis::hdel($order->node_link, 'first');
            Redis::hdel($order->node_link, 'last');
            Redis::hdel($order->node_link, $order->node);
        } elseif ($order->is_first) { // 首节点
            $next = Redis::hget($order->node_link, $order->next_node);
            if (!$next) {
                throw new InvalidArgumentException(__METHOD__.' expects next node is not empty.');
            }
            Redis::hdel($order->node_link, 'first');
            Redis::hdel($order->node_link, $order->node);
            $next = json_decode($next);
            $next->is_first = true;
            $next->prev_node = null;
            Redis::hset($next->node_link, 'first', $next->node);
            Redis::hset($next->node_link, $next->node, json_encode($next));
        } elseif ($order->is_last) { // 尾结点
            $prev = Redis::hget($order->node_link, $order->prev_node);
            if (!$prev) {
                throw new InvalidArgumentException(__METHOD__.' expects prev node is not empty.');
            }
            Redis::hdel($order->node_link, 'last');
            Redis::hdel($order->node_link, $order->node);
            $prev = json_decode($prev);
            $prev->is_last = true;
            $prev->next_node = null;
            Redis::hset($prev->node_link, 'last', $prev->node);
            Redis::hset($prev->node_link, $prev->node, json_encode($prev));
        } else { // 中间结点
            $prev = Redis::hget($order->node_link, $order->prev_node);
            $next = Redis::hget($order->node_link, $order->next_node);
            if (!$prev || !$next) {
                throw new InvalidArgumentException(__METHOD__.' expects relation node is not empty.');
            }
            $prev = json_decode($prev);
            $next = json_decode($next);
            $prev->next_node = $next->node;
            $next->prev_node = $prev->node;

            Redis::hdel($order->node_link, $order->node);
            Redis::hset($next->node_link, $next->node, json_encode($next));
            Redis::hset($prev->node_link, $prev->node, json_encode($prev));
        }
    }
}
