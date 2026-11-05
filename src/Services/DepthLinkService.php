<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use StingBo\Mengine\Core\Order;

class DepthLinkService
{
    private ?BookTransaction $transaction;

    public function __construct(?BookTransaction $transaction = null)
    {
        $this->transaction = $transaction;
    }

    /**
     * Older versions used PHP's float rendering as the member and key suffix.
     * Continue the old FIFO when that member has the same exact Redis score.
     */
    public function useExistingPriceLevel(Order $order): void
    {
        if (!is_string($order->price)) {
            return;
        }
        $legacy = (string) (float) $order->price;
        if ($legacy === $order->price) {
            return;
        }

        $score = Redis::zscore($order->order_list_zset_key, $legacy);
        if ($score !== false && $score !== null && Order::units((float) $score) === $order->price) {
            $order->node_link = $order->symbol.':link:'.$legacy;
            $order->order_depth_hash_field = $order->symbol.':depth:'.$legacy;
        }
    }

    /**
     * 放入价格点对应的单据.
     */
    public function pushDepthNode(Order $order): bool
    {
        $link_service = new LinkService($order->node_link, $this->transaction);

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

        return true;
    }

    /**
     * 从价格点对应的单据里删除.
     */
    public function deleteDepthNode(Order $order): bool
    {
        $link_service = new LinkService($order->node_link, $this->transaction);
        $order = $link_service->getCurrent($order->node);
        if (!$order) {
            return false;
        }

        $link_service->deleteNode($order);

        return true;
    }

    /**
     * 放入委托量hash.
     */
    public function pushDepthHash(Order $order)
    {
        $this->adjustDepth($order, Order::units($order->volume));
    }

    /**
     * 从委托量hash里删除.
     */
    public function deleteDepthHash(Order $order)
    {
        $this->adjustDepth($order, bcsub('0', Order::units($order->volume), 0));
    }

    /**
     * 放入深度池.
     */
    public function pushZset(Order $order)
    {
        $member = $this->priceMember($order);
        $score = Order::units($order->price);
        if ($this->transaction) {
            $this->transaction->zadd($order->order_list_zset_key, $score, $member);
        } else {
            Redis::zadd($order->order_list_zset_key, $score, $member);
        }
    }

    /**
     * 从深度池删除.
     */
    public function deleteZset(Order $order)
    {
        $key = $order->order_depth_hash_key;
        $field = $order->order_depth_hash_field;
        $zset = $order->order_list_zset_key;
        $member = $this->priceMember($order);

        if ($this->transaction) {
            $volume = $this->transaction->hget($key, $field);
            if ($volume <= 0) {
                $this->transaction->zrem($zset, $member);
                if ($volume === '0') {
                    $this->transaction->hdel($key, $field);
                }
            }

            return;
        }

        // Check the current depth and remove the empty level in one operation.
        $script = <<<'LUA'
local volume = redis.call('HGET', KEYS[1], ARGV[1])
if not volume or tonumber(volume) <= 0 then
    redis.call('ZREM', KEYS[2], ARGV[2])
    if volume == '0' then
        redis.call('HDEL', KEYS[1], ARGV[1])
    end
end
return 1
LUA;

        Redis::eval($script, 2, $key, $zset, $field, $member);
    }

    private function priceMember(Order $order): string
    {
        return substr($order->order_depth_hash_field, strlen($order->order_depth_hash_key) + 1);
    }

    private function adjustDepth(Order $order, string $delta): void
    {
        $key = $order->order_depth_hash_key;
        $field = $order->order_depth_hash_field;
        if (!$this->transaction) {
            Redis::hincrby($key, $field, $delta);

            return;
        }

        $volume = bcadd($this->transaction->hget($key, $field) ?? '0', $delta, 0);
        if (bccomp($volume, '0', 0) < 0 || bccomp($volume, '9223372036854775807', 0) > 0) {
            throw new RuntimeException('Order book depth is outside the Redis integer range.');
        }
        $this->transaction->hset($key, $field, $volume);
    }
}
