<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractCommissionPool;
use Illuminate\Support\Facades\Redis;

class DepthLinkService
{
    /**
     * 放入价格点对应的单据.
     */
    public function pushDepthNode(Order $curr)
    {
        $curr->prev_node = null;
        $curr->next_node = null;

        $first = Redis::hget($curr->node_link, 'first');
        if (!$first) {
            Redis::hset($curr->node_link, 'first', json_encode($curr));
        }

        $last = Redis::hget($curr->node_link, 'last');
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