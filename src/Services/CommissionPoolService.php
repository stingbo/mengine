<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use StingBo\Mengine\Core\AbstractCommissionPool;
use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Events\DeleteOrderSuccEvent;
use StingBo\Mengine\Events\MatchEvent;
use StingBo\Mengine\Events\PushQueueEvent;

class CommissionPoolService extends AbstractCommissionPool
{
    private static array $delivering = [];

    public function pushPool(Order $order): bool
    {
        return $this->apply($order, 'push', function (BookTransaction $redis, Order $order, array &$events) {
            $marker = $redis->hget($order->order_hash_key, $order->order_hash_field);
            if ($marker === null) {
                return false;
            }

            [$pending, $cancellations] = $marker === '1' ? [null, []] : unserialize($marker);
            if ($pending && !$pending->isSamePendingOrder($order)) {
                return false;
            }

            $redis->hdel($order->order_hash_key, $order->order_hash_field);
            foreach ($cancellations as $cancellation) {
                if ($cancellation->isSamePendingOrder($order)) {
                    $events[] = new DeleteOrderSuccEvent($order);

                    return false;
                }
            }

            $prices = (new MengineService())->getMatchingPrices($order->symbol, $order->transaction, Order::units($order->price));
            $depth = new DepthLinkService($redis);
            $this->matchPrices($order, $prices, $redis, $depth, $events);
            if ($order->volume === '0') {
                return false;
            }

            $depth->useExistingPriceLevel($order);
            $depth->pushZset($order);
            $depth->pushDepthHash($order);
            $depth->pushDepthNode($order);
            $events[] = new PushQueueEvent(clone $order);

            return true;
        });
    }

    public function deletePoolOrder(Order $order): bool
    {
        return $this->cancel($order, null);
    }

    public function reducePoolOrder(Order $order, string $cancellationId): bool
    {
        return $this->cancel($order, $cancellationId);
    }

    private function cancel(Order $order, ?string $cancellationId): bool
    {
        $operation = $cancellationId === null ? 'cancel' : 'reduce:'.$cancellationId;

        return $this->apply($order, $operation, function (BookTransaction $redis, Order $order, array &$events) use ($cancellationId) {
            // Respect receipts written by workers running the previous release.
            if ($cancellationId !== null && Redis::exists($order->node_link.':cancel:'.$cancellationId)) {
                return true;
            }

            $link = new LinkService($order->node_link, $redis);
            $node = $link->getCurrent($order->node);
            if (!$node || !$node->hasSameIdentity($order)) {
                return false;
            }

            $remaining = Order::units($node->volume);
            if ($cancellationId === null || bccomp($order->volume, $remaining, 0) >= 0) {
                $order->volume = $remaining;
                $link->deleteNode($node);
            } else {
                $node->volume = bcsub($remaining, $order->volume, 0);
                $link->setNode($node->node, $node);
            }

            $depth = new DepthLinkService($redis);
            $depth->deleteDepthHash($order);
            $depth->deleteZset($order);
            $events[] = new DeleteOrderSuccEvent($order);

            return true;
        });
    }

    public function matchUp(Order $order, array $list): ?Order
    {
        $order->volume = $this->apply($order, 'match:'.Order::units($order->volume), function (BookTransaction $redis, Order $order, array &$events) use ($list) {
            $this->matchPrices($order, array_column($list, 'price'), $redis, new DepthLinkService($redis), $events);

            return $order->volume;
        });

        return $order->volume === '0' ? null : $order;
    }

    public function matchOrder($order, $link_service)
    {
        $order->volume = $this->apply($order, 'match-level:'.$link_service->link.':'.Order::units($order->volume), function (BookTransaction $redis, Order $order, array &$events) use ($link_service) {
            $this->matchLevel($order, new LinkService($link_service->link, $redis), new DepthLinkService($redis), $events);

            return $order->volume;
        });

        return $order;
    }

    public function deletePoolMatchOrder($order)
    {
        BookTransaction::run($order->symbol, function (BookTransaction $redis) use ($order) {
            $depth = new DepthLinkService($redis);
            $depth->deleteDepthHash($order);
            $depth->deleteZset($order);
        });
    }

    private function matchPrices(Order $order, array $prices, BookTransaction $redis, DepthLinkService $depth, array &$events): void
    {
        foreach ($prices as $price) {
            $link = new LinkService($order->symbol.':link:'.$price, $redis);
            $this->matchLevel($order, $link, $depth, $events);
            if ($order->volume === '0') {
                return;
            }
        }
    }

    private function matchLevel(Order $order, LinkService $link, DepthLinkService $depth, array &$events): void
    {
        while ($order->volume !== '0' && ($maker = $link->getFirst())) {
            if ($maker->transaction === $order->transaction) {
                return;
            }

            $available = Order::units($maker->volume);
            $comparison = bccomp($order->volume, $available, 0);
            $traded = $comparison < 0 ? $order->volume : $available;
            $order->volume = bcsub($order->volume, $traded, 0);

            if ($comparison < 0) {
                $maker->volume = bcsub($available, $traded, 0);
                $link->setNode($maker->node, $maker);
            } else {
                $link->deleteNode($maker);
            }

            $maker->volume = $traded;
            $depth->deleteDepthHash($maker);
            $depth->deleteZset($maker);
            $events[] = new MatchEvent(clone $order, $maker, $traded);
        }
    }

    private function apply(Order $order, string $operation, callable $plan)
    {
        $id = hash('sha256', serialize([$order->symbol, $order->transaction, $order->uuid, $order->oid, $operation]));
        $receipts = $order->symbol.':mengine:receipts';
        $outbox = $order->symbol.':mengine:outbox';

        $result = BookTransaction::run($order->symbol, function (BookTransaction $redis) use ($order, $plan, $id, $receipts, $outbox) {
            $receipt = $redis->hget($receipts, $id);
            if ($receipt !== null) {
                return unserialize($receipt);
            }

            $order = clone $order;
            $order->volume = Order::units($order->volume);
            $events = [];
            $result = $plan($redis, $order, $events);
            if ($events) {
                foreach ($events as $index => $event) {
                    $event->eventId = $id.':'.$index;
                }
                $redis->hset($outbox, $id, serialize($events));
                $redis->hset($receipts, $id, serialize($result));
            }

            return $result;
        });

        $this->deliver($outbox, $id);

        return $result;
    }

    /**
     * Recover events after the queue has exhausted its retries.
     */
    public function replayPendingEvents(string $symbol): void
    {
        $outbox = $symbol.':mengine:outbox';
        foreach (Redis::hkeys($outbox) as $id) {
            $this->deliver($outbox, $id);
        }
    }

    private function deliver(string $outbox, string $id): void
    {
        if (isset(self::$delivering[$id])) {
            return;
        }
        $pending = Redis::hget($outbox, $id);
        if (!$pending) {
            return;
        }

        // A crash after dispatch and before acknowledgement can replay the
        // batch. Consumers can use eventId to deduplicate these deliveries.
        self::$delivering[$id] = true;
        try {
            foreach (unserialize($pending) as $event) {
                event($event);
            }
            Redis::hdel($outbox, $id);
        } finally {
            unset(self::$delivering[$id]);
        }
    }
}
