<?php

namespace StingBo\Mengine\Services;

use StingBo\Mengine\Core\Order;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

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
}
