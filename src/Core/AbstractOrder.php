<?php

namespace StingBo\Mengine\Core;

abstract class AbstractOrder
{
    public $order;

    public $order_hash_field;

    public function __construct($uuid, $oid, $symbol, $transaction, $volume, $price)
    {
        $this->order = new Order();
        $this->setUuid($uuid);
        $this->setOid($oid);
        $this->setSymbol($symbol);
        $this->setTransaction($transaction);
        $this->setVolume($volume);
        $this->setPrice($price);
        $this->setOrderHashField();
    }

    public function setUuid($uuid)
    {
        return $this->order->uuid = $uuid;
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

    /**
     * 委托标识池field.
     */
    public function setOrderHashField()
    {
        return $this->order_hash_field = $this->order->symbol.':'.$this->order->uuid.':'.$this->order->oid;
    }

    /**
     * in queue.
     */
    abstract public function pushCommissionQueue(Order $order);

    /**
     * in hash.
     */
    abstract public function pushCommissionHash(Order $order);

    /**
     * delete order.
     */
    abstract public function deleteOrder(Order $order);
}
