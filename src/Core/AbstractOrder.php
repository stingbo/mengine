<?php

namespace StingBo\Mengine\Core;

abstract class AbstractOrder
{
    public $order;

    public $order_hash_key;

    public function __construct($oid, $symbol, $transaction, $volume, $price)
    {
        $this->order = new Order();
        $this->setOid($oid);
        $this->setSymbol($symbol);
        $this->setTransaction($transaction);
        $this->setVolume($volume);
        $this->setPrice($price);
        $this->setOrderHashKey();
    }

    public function setOid($oid)
    {
        return $this->order->oid = $oid;
    }

    public function setSymbol($symbol)
    {
        return $this->order->symbol = $symbol;
    }

    public function setTransaction($transaction)
    {
        return $this->order->transaction = $transaction;
    }

    public function setVolume($volume)
    {
        return $this->order->volume = $volume;
    }

    public function setPrice($price)
    {
        return $this->order->price = $price;
    }

    public function setOrderHashKey()
    {
        return $this->order_hash_key = $this->order->symbol.':'.$this->order->oid;
    }

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
}
