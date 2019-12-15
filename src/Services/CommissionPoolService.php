<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Core\AbstractCommissionPool;
use StingBo\Mengine\Events\MatchEvent;
use Illuminate\Support\Facades\Redis;
use StingBo\Mengine\Exceptions\MatchException;

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
        //die;

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

        // 从节点链上删除
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
        // 需要判断部分成交的情况 TODO
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
        //print_r($order);
        //print_r($list);
        // 撮合
        foreach ($list as $match_info) {
            $link_name = $order->symbol.':link:'.$match_info['price'];
            $link_service = new LinkService($link_name);

            $order = $this->matchOrder($order, $link_service);

            if ($order->volume <= 0) {
                break;
            }
        }

        if ($order->volume > 0) {
            return $order;
        }

        return false;
    }

    public function matchOrder($order, $link_service)
    {
        $match_order = $link_service->getFirst();
        if ($match_order) {
            if ($order->volume > $match_order->volume) {
                $match_volume = $match_order->volume;
                $order->volume = bcsub($order->volume, $match_order->volume);
                $link_service->deleteNode($match_order);
                $this->matchOrder($order, $link_service);
            } elseif ($order->volume == $match_order->volume) {
                $mtch_volume = $match_order->volume;
                $order->volume = bcsub($order->volume, $match_order->volume);
                $link_service->deleteNode($match_order);

            } else {
                $match_order->volume = bcsub($match_order->volume, $order->volume);
                $order->volume = 0;
                $link_service->setNode($match_order);
            }
            event(new MatchEvent($order, $match_order, $match_volume));

            return $order;
        }

        return $order;
    }
}
