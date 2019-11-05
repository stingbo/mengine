<?php

namespace StingBo\Mengine\Core;

use InvalidArgumentException;

class Order
{
    /**
     * 唯一标识，可以是用户id.
     */
    public $uuid;

    /**
     * 单据id.
     */
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

    /**
     * 价格.
     */
    public $price;

    /**
     * 精度.
     */
    public $accuracy;

    /**
     * 前一个.
     */
    public $prev;

    /**
     * 后一个.
     */
    public $next;

    /**
     * hash对比池标识.
     */
    public $order_hash_key;
    public $order_hash_field;

    /**
     * zset委托列表.
     */
    public $order_list_zset_key;

    /**
     * hash委托深度.
     */
    public $order_depth_hash_key;
    public $order_depth_hash_field;

    public function __construct($uuid, $oid, $symbol, $transaction, $volume, $price)
    {
        $this->setAccuracy();
        $this->setUuid($uuid);
        $this->setOid($oid);
        $this->setSymbol($symbol);
        $this->setTransaction($transaction);
        $this->setVolume($volume);
        $this->setPrice($price);
        $this->setOrderHashKey();
        $this->setListZsetKey();
        $this->setDepthHashKey();
    }

    /**
     * set uuid.
     */
    public function setUuid($uuid)
    {
        if (!$uuid) {
            throw new InvalidArgumentException(__METHOD__.' expects argument uuid is not empty.');
        }

        $this->uuid = $uuid;

        return $this;
    }

    /**
     * set oid.
     */
    public function setOid($oid)
    {
        if (!$oid) {
            throw new InvalidArgumentException(__METHOD__.' expects argument oid is not empty.');
        }

        $this->oid = $oid;

        return $this;
    }

    /**
     * set symbol.
     */
    public function setSymbol($symbol)
    {
        if (!$symbol) {
            throw new InvalidArgumentException(__METHOD__.' expects argument symbol is not empty.');
        }

        $this->symbol = $symbol;

        return $this;
    }

    /**
     * set transaction.
     */
    public function setTransaction($transaction)
    {
        if (!in_array($transaction, config('mengine.mengine.transaction'))) {
            throw new InvalidArgumentException(__METHOD__.' expects argument transaction to be a valid type of [config.mengine.transaction].');
        }

        $this->transaction = $transaction;

        return $this;
    }

    /**
     * set volume.
     */
    public function setVolume($volume)
    {
        if (floatval($volume) <= 0) {
            throw new InvalidArgumentException(__METHOD__.' expects argument volume greater than 0.');
        }

        $this->volume = bcmul($volume, bcpow(10, $this->accuracy));

        return $this;
    }

    /**
     * set price.
     */
    public function setPrice($price)
    {
        if (floatval($price) <= 0) {
            throw new InvalidArgumentException(__METHOD__.' expects argument price greater than 0.');
        }

        $this->price = bcmul($price, bcpow(10, $this->accuracy));

        return $this;
    }

    public function setAccuracy()
    {
        $accuracy = config('mengine.mengine.accuracy');
        if (floor(0) !== (floatval($accuracy) - $accuracy)) {
            throw new InvalidArgumentException(__METHOD__.' expects argument config.mengine.mengine.accuracy is a positive integer.');
        }

        $this->accuracy = $accuracy;

        return $this;
    }

    /**
     * 委托标识池field.
     */
    public function setOrderHashKey()
    {
        $this->order_hash_key = $this->symbol.':comparison';
        $this->order_hash_field = $this->symbol.':'.$this->uuid.':'.$this->oid;

        return $this;
    }

    /**
     * 委托列表.
     */
    public function setListZsetKey()
    {
        $this->order_list_zset_key = $this->symbol.':'.$this->transaction;

        return $this;
    }

    /**
     * 深度.
     */
    public function setDepthHashKey()
    {
        $this->order_depth_hash_key = $this->symbol.':depth';
        $this->order_depth_hash_field = $this->symbol.':depth:'.$this->price;

        return $this;
    }
}
