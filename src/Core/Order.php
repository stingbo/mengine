<?php

namespace StingBo\Mengine\Core;

use InvalidArgumentException;

class Order
{
    /**
     * Unique identifier, can be user ID.
     */
    public string $uuid;

    /**
     * order id.
     */
    public string $oid;

    /**
     * abc2usdt.
     */
    public string $symbol;

    /**
     * Buy/Sell.
     */
    public string $transaction;

    public float $volume;

    public float $price;

    public int $accuracy;

    /**
     * node.
     */
    public string $node;

    public bool $is_first = false;
    public bool $is_last = false;

    /**
     * prev node.
     */
    public $prev_node;

    /**
     * next node.
     */
    public $next_node;

    /**
     * node link.
     */
    public string $node_link;

    /**
     * hash Compare Pool Identifiers.
     */
    public string $order_hash_key;
    public string $order_hash_field;

    /**
     * zset Order List.
     */
    public string $order_list_zset_key;

    /**
     * hash Order Depth.
     */
    public string $order_depth_hash_key;
    public string $order_depth_hash_field;

    public function __construct($uuid, $oid, $symbol, $transaction, $volume, $price)
    {
        $this->setSymbol($symbol);
        $this->setAccuracy();
        $this->setUuid($uuid);
        $this->setOid($oid);
        $this->setTransaction($transaction);
        $this->setVolume($volume);
        $this->setPrice($price);
        $this->setOrderHashKey();
        $this->setListZsetKey();
        $this->setDepthHashKey();
        $this->setNode();
        $this->setNodeLink();
    }

    /**
     * set uuid.
     */
    public function setUuid($uuid): Order
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
    public function setOid($oid): Order
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
    public function setSymbol(string $symbol): Order
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
    public function setTransaction($transaction): Order
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
    public function setVolume($volume): Order
    {
        if (floatval($volume) <= 0) {
            throw new InvalidArgumentException(__METHOD__.' expects argument volume greater than 0.');
        }
        if (config('mengine.mengine.strict_mode')) { // In strict mode, the decimal places cannot exceed the configured length.
            $number_string = strval($volume); // Convert the number to a string.
            // Use a regular expression to match the decimal part.
            if (preg_match('/\.\d{' . $this->accuracy . ',}/', $number_string)) {
                throw new InvalidArgumentException(__METHOD__.' decimal places exceed the configured length.');
            }
        }

        // Truncate the decimal part.
        $volume = number_format($volume, $this->accuracy, '.', '');
        $this->volume = bcmul($volume, bcpow(10, $this->accuracy));

        return $this;
    }

    /**
     * set price.
     */
    public function setPrice($price): Order
    {
        if (floatval($price) <= 0) {
            throw new InvalidArgumentException(__METHOD__.' expects argument price greater than 0.');
        }
        if (config('mengine.mengine.strict_mode')) {
            $number_string = strval($price);
            if (preg_match('/\.\d{' . $this->accuracy . ',}/', $number_string)) {
                throw new InvalidArgumentException(__METHOD__.' decimal places exceed the configured length.');
            }
        }

        $price = number_format($price, $this->accuracy, '.', '');
        $this->price = bcmul($price, bcpow(10, $this->accuracy));

        return $this;
    }

    /**
     * set accuracy.
     */
    public function setAccuracy(): Order
    {
        $accuracy = config("mengine.mengine.{$this->symbol}_accuracy") ?? config('mengine.mengine.accuracy');
        if (floor(0) !== (floatval($accuracy) - $accuracy)) {
            throw new InvalidArgumentException(__METHOD__.' expects argument config.mengine.mengine.accuracy is a positive integer.');
        }

        $this->accuracy = $accuracy;

        return $this;
    }

    /**
     * 委托标识池field.
     */
    public function setOrderHashKey(): Order
    {
        $this->order_hash_key = $this->symbol.':comparison';
        $this->order_hash_field = $this->symbol.':'.$this->uuid.':'.$this->oid;

        return $this;
    }

    /**
     * 委托列表.
     */
    public function setListZsetKey(): Order
    {
        $this->order_list_zset_key = $this->symbol.':'.$this->transaction;

        return $this;
    }

    /**
     * 深度.
     */
    public function setDepthHashKey(): Order
    {
        $this->order_depth_hash_key = $this->symbol.':depth';
        $this->order_depth_hash_field = $this->symbol.':depth:'.$this->price;

        return $this;
    }

    /**
     * hash模拟node.
     */
    public function setNode(): Order
    {
        $this->node = $this->symbol.':node:'.$this->oid;

        return $this;
    }

    /**
     * hash模拟Link.
     */
    public function setNodeLink(): Order
    {
        $this->node_link = $this->symbol.':link:'.$this->price;

        return $this;
    }
}
