<?php

namespace StingBo\Mengine\Core;

class Order
{
    public $uuid;

    public $oid;

    /**
     * 符号.
     */
    public $symbol;

    /**
     * 买卖.
     */
    public $transaction;

    /**
     * 数量.
     */
    public $volume;

    public $price;
}
