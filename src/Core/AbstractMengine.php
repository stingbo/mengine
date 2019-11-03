<?php

namespace StingBo\Mengine\Core;

abstract class AbstractMengine
{
    /**
     * in queue.
     */
    abstract public function pushQueue(Order $order);

    /**
     * in hash.
     */
    abstract public function pushHash(Order $order);

    /**
     * delete order.
     */
    abstract public function deleteOrder(Order $order);

    abstract public function getDepth($symbol);
}
