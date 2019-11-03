<?php

namespace StingBo\Mengine\Core;

abstract class AbstractCommissionPool
{
    /**
     * in pool.
     */
    abstract public function pushPool(Order $order);

    /**
     * out pool.
     */
    abstract public function deletePoolOrder(Order $order);
}
