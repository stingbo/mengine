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

    /** @var string|float Scaled integer. Legacy serialized orders can contain a float. */
    public $volume;

    /** @var string|float Scaled integer. Legacy serialized orders can contain a float. */
    public $price;

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

    public function hasSameIdentity(Order $order): bool
    {
        return $this->uuid === $order->uuid
            && $this->oid === $order->oid
            && $this->symbol === $order->symbol
            && $this->transaction === $order->transaction;
    }

    public function isSamePendingOrder(Order $order): bool
    {
        return $this->hasSameIdentity($order)
            && self::units($this->price) === self::units($order->price);
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
        $this->volume = $this->scaleDecimal($volume, 'volume');

        return $this;
    }

    /**
     * set price.
     */
    public function setPrice($price): Order
    {
        $this->price = $this->scaleDecimal($price, 'price');

        return $this;
    }

    /**
     * set accuracy.
     */
    public function setAccuracy(): Order
    {
        $accuracy = config("mengine.mengine.{$this->symbol}_accuracy") ?? config('mengine.mengine.accuracy');
        $accuracy = filter_var($accuracy, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
            'max_range' => 308,
        ]]);
        if ($accuracy === false) {
            throw new InvalidArgumentException('Trading precision must be an integer between 0 and 308.');
        }

        $this->accuracy = $accuracy;

        return $this;
    }

    /**
     * Format scaled values from legacy serialized orders for BCMath and Redis.
     */
    public static function units($value): string
    {
        return is_float($value) ? sprintf('%.0F', $value) : (string) $value;
    }

    private function scaleDecimal($value, string $field): string
    {
        if (is_float($value) && is_finite($value)) {
            // JSON uses scientific notation for small floats, with a
            // synthetic ".0" when the significand is an integer.
            $value = preg_replace('/\.0(?=[eE])/', '', json_encode($value));
        } elseif (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || !preg_match('/^\+?(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $value, $parts)) {
            throw new InvalidArgumentException("Order $field must be a positive decimal number.");
        }

        $integer = $parts[1];
        $fraction = $parts[2] ?? '';
        $digits = ltrim($integer.$fraction, '0');
        $exponent = max(-1000000000, min(1000000000, (int) ($parts[3] ?? 0)));
        if (config('mengine.mengine.strict_mode') && strlen($fraction) - $exponent > $this->accuracy) {
            throw new InvalidArgumentException("Order $field has more decimal places than the configured precision.");
        }

        $leadingZeros = strlen($integer) + strlen($fraction) - strlen($digits);
        $length = strlen($integer) + $exponent + $this->accuracy - $leadingZeros;
        if ($digits === '' || $length < 1 || $length > 16) {
            throw new InvalidArgumentException("Order $field is outside the supported range.");
        }

        $units = str_pad(substr($digits, 0, $length), $length, '0');
        // Redis sorted set scores must distinguish every supported price.
        if (bccomp($units, '9007199254740991', 0) > 0) {
            throw new InvalidArgumentException("Order $field is outside the supported range.");
        }

        return $units;
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
