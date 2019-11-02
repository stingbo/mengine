<?php

namespace StingBo\Mengine\Core;

class AbstractOrder
{
    public $order;

    public function __construct($symbol, $transaction, $volume)
    {
        $this->order = new Order();
        $this->order->symbol = $symbol;
        $this->order->transaction = $transaction;
        $this->order->volumn = $volumn;
    }

    /**
     * 下单到队列.
     */
    abstract public function setOrder(Order $order);
}
