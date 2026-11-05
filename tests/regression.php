<?php

$usePredis = getenv('MENGINE_REDIS_DRIVER') === 'predis';
if ($usePredis) {
    require getenv('MENGINE_COMPOSER_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
}
require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\Redis as RedisFacade;
use StingBo\Mengine\Core\Order;
use StingBo\Mengine\Events\DeleteOrderSuccEvent;
use StingBo\Mengine\Events\MatchEvent;
use StingBo\Mengine\Exceptions\DeleteOrderException;
use StingBo\Mengine\Jobs\DeleteOrderJob;
use StingBo\Mengine\Services\CommissionPoolService;
use StingBo\Mengine\Services\DepthLinkService;
use StingBo\Mengine\Services\LinkService;
use StingBo\Mengine\Services\MengineService;

function check($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function invalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    throw new RuntimeException('Expected invalid input to be rejected');
}

function order($id, $side, $volume, $price = '1'): Order
{
    return new Order('user', $id, $GLOBALS['symbol'], $side, $volume, $price);
}

function runJob($job)
{
    return unserialize(serialize($job))->handle(new CommissionPoolService());
}

function rejectedCancellation(callable $request, int $code): void
{
    try {
        $request();
    } catch (DeleteOrderException $exception) {
        check($code, $exception->getCode());
        return;
    }
    throw new RuntimeException('Expected cancellation to be rejected');
}

$port = (int) (getenv('MENGINE_REDIS_PORT') ?: 6379);
if ($usePredis) {
    $connection = ['host' => '127.0.0.1', 'port' => $port];
    $primary = new Predis\Client($connection);
    $secondary = new Predis\Client($connection);
} else {
    $primary = new Redis();
    $primary->connect('127.0.0.1', $port);
    $secondary = new Redis();
    $secondary->connect('127.0.0.1', $port);
}
RedisFacade::$client = $primary;
$engine = new MengineService();

$tests = [
    'decimal units are exact and truncated' => function () {
        check('112345678', order(1, 'buy', '1.123456789')->volume);
        check('9007199254740991', order(1, 'buy', '1', '90071992.54740991')->price);
        invalid(function () { order(1, 'buy', '0.000000009'); });
        invalid(function () { order(1, 'buy', '1', '90071992.54740992'); });
        invalid(function () { order(1, 'buy', '1junk'); });
        invalid(function () { order(1, 'buy', INF); });
        $GLOBALS['strict'] = true;
        check('12345678', order(1, 'buy', '0.12345678')->volume);
        check('1', order(1, 'buy', 1e-8)->volume);
        invalid(function () { order(1, 'buy', '1.123456789'); });
    },
    'two workers cannot match the same maker' => function () use ($engine, $primary, $secondary) {
        $engine->pushQueue(order(1, 'sell', '1'));
        runJob(array_shift($GLOBALS['jobs']));
        $engine->pushQueue(order(2, 'buy', '1'));
        $engine->pushQueue(order(3, 'buy', '1'));
        [$first, $second] = $GLOBALS['jobs'];
        RedisFacade::$beforeWrite = function () use ($first, $primary, $secondary) {
            RedisFacade::$client = $secondary;
            try {
                runJob($first);
            } finally {
                RedisFacade::$client = $primary;
            }
        };
        runJob($second);
        $matches = array_values(array_filter($GLOBALS['events'], function ($event) {
            return $event instanceof MatchEvent;
        }));
        check(1, count($matches));
        check('1', $matches[0]->match_order->oid);
        check('100000000', $engine->getDepth($GLOBALS['symbol'], 'buy')[0]['volume']);
        check([], $engine->getDepth($GLOBALS['symbol'], 'sell'));
    },
    'a rejected atomic write leaves the queued order retryable' => function () use ($engine, $primary) {
        $order = order(1, 'sell', '1');
        $engine->pushQueue($order);
        $job = array_shift($GLOBALS['jobs']);
        $primary->set($order->order_list_zset_key, 'wrong type');
        $failed = false;
        try {
            runJob($job);
        } catch (Exception $exception) {
            $failed = true;
        }
        check(true, $failed);
        check(false, $engine->isHashDeleted($order));
        check(false, $primary->exists($order->node_link) > 0);
        check([], $GLOBALS['events']);
        $primary->del($order->order_list_zset_key);

        runJob($job);
        check(true, $engine->isHashDeleted($order));
        check('100000000', $engine->getDepth($GLOBALS['symbol'], 'sell')[0]['volume']);
        check(1, count($GLOBALS['events']));
    },
    'depth queries batch quantities and preserve side order and missing values' => function () use ($engine, $primary) {
        $symbol = $GLOBALS['symbol'];
        RedisFacade::$commands = [];
        check([], $engine->getDepth($symbol, 'invalid'));
        check([], RedisFacade::$commands);
        check([], $engine->getDepth($symbol, 'buy'));
        check(['zrevrange'], array_column(RedisFacade::$commands, 0));

        $missing = $primary instanceof Redis ? false : null;
        foreach (['buy' => ['300', '200', '100'], 'sell' => ['100', '200', '300']] as $side => $prices) {
            foreach ($prices as $price) {
                $primary->zadd($symbol.':'.$side, $price, $price);
            }
            $primary->hset($symbol.':depth', $symbol.':depth:100', '15');
            $primary->hset($symbol.':depth', $symbol.':depth:300', '25');

            RedisFacade::$commands = [];
            $depth = $engine->getDepth($symbol, $side);
            check($prices, array_column($depth, 'price'));
            check($side === 'buy' ? ['25', $missing, '15'] : ['15', $missing, '25'], array_column($depth, 'volume'));
            check(['price', 'volume'], array_keys($depth[1]));
            check([$side === 'buy' ? 'zrevrange' : 'zrange', 'hmget'], array_column(RedisFacade::$commands, 0));
        }

        RedisFacade::$commands = [];
        check([['price' => '100', 'volume' => '15'], ['price' => '200', 'volume' => $missing]], $engine->getMutexDepth($symbol, 'buy', '200'));
        check(['zrangebyscore', 'hmget'], array_column(RedisFacade::$commands, 0));
        RedisFacade::$commands = [];
        check([['price' => '300', 'volume' => '25'], ['price' => '200', 'volume' => $missing]], $engine->getMutexDepth($symbol, 'sell', '200'));
        check(['zrevrangebyscore', 'hmget'], array_column(RedisFacade::$commands, 0));
    },
    'matching reads quantities only at filled prices and keeps price and FIFO priority' => function () use ($engine) {
        $symbol = $GLOBALS['symbol'];
        try {
            foreach (['sell', 'buy'] as $makerSide) {
                $GLOBALS['symbol'] = $symbol.':'.$makerSide;
                $takerSide = $makerSide === 'sell' ? 'buy' : 'sell';
                $prices = $makerSide === 'sell' ? ['3', '2', '3', '4', '1'] : ['2', '3', '2', '1', '4'];
                foreach ($prices as $id => $price) {
                    $engine->pushQueue(order($id + 1, $makerSide, '1', $price));
                    runJob(array_shift($GLOBALS['jobs']));
                }

                $GLOBALS['events'] = [];
                $engine->pushQueue(order(6, $takerSide, '3', $prices[0]));
                RedisFacade::$commands = [];
                runJob(array_shift($GLOBALS['jobs']));

                check(['5', '2', '1'], array_map(fn ($event) => $event->match_order->oid, $GLOBALS['events']));
                $priceAndDepthReads = array_values(array_filter(RedisFacade::$commands, function ($command) {
                    [$name, $key] = $command;
                    return in_array($name, ['zrangebyscore', 'zrevrangebyscore'], true)
                        || (in_array($name, ['hget', 'hmget'], true) && $key === $GLOBALS['symbol'].':depth');
                }));
                check([$takerSide === 'buy' ? 'zrangebyscore' : 'zrevrangebyscore', 'hget', 'hget', 'hget'], array_column($priceAndDepthReads, 0));
            }
        } finally {
            $GLOBALS['symbol'] = $symbol;
        }
    },
    'fills and cancellations remove empty price levels and allow relisting' => function () use ($engine, $primary) {
        $symbol = $GLOBALS['symbol'];
        $otherLevel = order(1, 'sell', '7', '2');
        $maker = order(2, 'sell', '3');
        foreach ([$otherLevel, $maker] as $order) {
            $engine->pushQueue($order);
            runJob(array_shift($GLOBALS['jobs']));
        }

        $engine->pushQueue(order(3, 'buy', '1'));
        runJob(array_shift($GLOBALS['jobs']));
        check('200000000', $primary->hget($maker->order_depth_hash_key, $maker->order_depth_hash_field));

        foreach (['fill', 'full cancel', 'partial cancel'] as $index => $operation) {
            if ($operation === 'fill') {
                $engine->pushQueue(order(4, 'buy', '2'));
            } else {
                $engine->deleteOrder($maker, $operation === 'partial cancel' ? '4' : null);
            }
            runJob(array_shift($GLOBALS['jobs']));

            check(false, (bool) $primary->hexists($maker->order_depth_hash_key, $maker->order_depth_hash_field));
            check([['price' => '200000000', 'volume' => '700000000']], $engine->getDepth($symbol, 'sell'));

            $maker = order(5 + $index, 'sell', '4');
            $engine->pushQueue($maker);
            runJob(array_shift($GLOBALS['jobs']));
            check([
                ['price' => '100000000', 'volume' => '400000000'],
                ['price' => '200000000', 'volume' => '700000000'],
            ], $engine->getDepth($symbol, 'sell'));
        }
    },
    'adjacent large prices remain distinct in Redis' => function () use ($engine) {
        $engine->pushQueue(order(1, 'sell', '1', '90071992.54740991'));
        $engine->pushQueue(order(2, 'sell', '1', '90071992.54740990'));
        runJob(array_shift($GLOBALS['jobs']));
        runJob(array_shift($GLOBALS['jobs']));
        $engine->pushQueue(order(3, 'buy', '1', '90071992.54740990'));
        runJob(array_shift($GLOBALS['jobs']));
        check([['price' => '9007199254740991', 'volume' => '100000000']], $engine->getDepth($GLOBALS['symbol'], 'sell'));
        $match = end($GLOBALS['events']);
        check('2', $match->match_order->oid);
    },
    'partial cancellation preserves FIFO for legacy float orders' => function () use ($engine) {
        $price = '1000000';
        $maker = order(1, 'sell', '3', $price);
        $legacy = clone $maker;
        $legacy->price = (float) $legacy->price;
        $legacy->volume = (float) $legacy->volume;
        $legacy->setDepthHashKey();
        $legacy->setNodeLink();
        check(true, $legacy->node_link !== $maker->node_link);
        (new LinkService($legacy->node_link))->init($legacy);
        $depth = new DepthLinkService();
        $depth->pushZset($legacy);
        $depth->pushDepthHash($legacy);
        $engine->pushQueue(order(2, 'sell', '2', $price));
        runJob(array_shift($GLOBALS['jobs']));

        $engine->deleteOrder($maker, '2');
        runJob(array_shift($GLOBALS['jobs']));
        $engine->pushQueue(order(3, 'buy', '1', $price));
        runJob(array_shift($GLOBALS['jobs']));
        $match = end($GLOBALS['events']);
        check('1', $match->match_order->oid);
        check('100000000', $match->volume);
        check('200000000', $engine->getDepth($GLOBALS['symbol'], 'sell')[0]['volume']);
    },
    'replay recovers a committed batch after the first event was delivered' => function () use ($engine) {
        $engine->pushQueue(order(1, 'sell', '1'));
        $engine->pushQueue(order(2, 'sell', '1'));
        runJob(array_shift($GLOBALS['jobs']));
        runJob(array_shift($GLOBALS['jobs']));
        $engine->pushQueue(order(3, 'buy', '2'));
        $attempted = [];
        $GLOBALS['listener'] = function ($event) use (&$attempted) {
            $attempted[] = $event->eventId;
            if (count($attempted) === 2) {
                throw new LogicException('second event failed');
            }
        };
        try {
            runJob(array_shift($GLOBALS['jobs']));
            throw new RuntimeException('Expected delivery failure');
        } catch (LogicException $exception) {
            check('second event failed', $exception->getMessage());
        }

        $replayed = [];
        $GLOBALS['listener'] = function ($event) use (&$replayed) {
            $replayed[] = $event->eventId;
        };
        $pool = new CommissionPoolService();
        $pool->replayPendingEvents($GLOBALS['symbol']);
        $pool->replayPendingEvents($GLOBALS['symbol']);
        check($attempted, $replayed);
        check(2, count(array_unique($replayed)));
        check([], $engine->getDepth($GLOBALS['symbol'], 'sell'));
    },
    'match retries publish the same event without matching again' => function () use ($engine) {
        $engine->pushQueue(order(1, 'sell', '1'));
        $engine->pushQueue(order(2, 'sell', '1'));
        runJob(array_shift($GLOBALS['jobs']));
        runJob(array_shift($GLOBALS['jobs']));
        $engine->pushQueue(order(3, 'buy', '2'));
        retryAfterDeliveryFailure(array_shift($GLOBALS['jobs']), MatchEvent::class, 2);
        check([], $engine->getDepth($GLOBALS['symbol'], 'sell'));
    },
    'full cancellation of a pending order uses its original quantity and retries delivery' => function () use ($engine) {
        $pending = order(1, 'sell', '3');
        $engine->pushQueue($pending);
        $engine->deleteOrder(order(1, 'sell', '1'));
        $events = retryAfterDeliveryFailure(array_shift($GLOBALS['jobs']), DeleteOrderSuccEvent::class, 1);
        check('300000000', $events[0]->order->volume);
        check([], $engine->getDepth($GLOBALS['symbol'], 'sell'));
    },
    'pending cancellation validates identity before changing the marker' => function () use ($engine) {
        $pending = new Order('01', '1', $GLOBALS['symbol'], 'sell', '3', '1');
        $engine->pushQueue($pending);
        $wrongSide = new Order('01', '1', $GLOBALS['symbol'], 'buy', '3', '1');
        rejectedCancellation(function () use ($engine, $wrongSide) {
            $engine->deleteOrder($wrongSide);
        }, DeleteOrderException::NOT_MATCH);
        $otherOwner = new Order('1', '1', $GLOBALS['symbol'], 'sell', '3', '1');
        rejectedCancellation(function () use ($engine, $otherOwner) {
            $engine->deleteOrder($otherOwner);
        }, DeleteOrderException::NOT_EXIST);
        rejectedCancellation(function () use ($engine, $pending) {
            $engine->deleteOrder($pending, '1');
        }, DeleteOrderException::NOT_EXIST);
        runJob(array_shift($GLOBALS['jobs']));
        check('300000000', $engine->getDepth($GLOBALS['symbol'], 'sell')[0]['volume']);
    },
    'full cancellation falls back to the book when the push wins the race' => function () use ($engine) {
        $maker = order(1, 'sell', '3');
        $engine->pushQueue($maker);
        $push = array_shift($GLOBALS['jobs']);
        RedisFacade::$beforeWrite = function () use ($push) {
            runJob($push);
        };
        $engine->deleteOrder($maker);
        runJob(array_shift($GLOBALS['jobs']));
        $event = end($GLOBALS['events']);
        check(DeleteOrderSuccEvent::class, get_class($event));
        check('300000000', $event->order->volume);
        check([], $engine->getDepth($GLOBALS['symbol'], 'sell'));
    },
    'legacy pending cancellation is validated against the original push job' => function () use ($engine, $primary) {
        foreach ([1, 2] as $id) {
            $maker = order($id, 'sell', '3');
            $engine->pushQueue($maker);
            $primary->hSet($maker->order_hash_key, $maker->order_hash_field, '1');
            if ($id === 2) {
                $engine->deleteOrder($maker);
            }
            $engine->deleteOrder(order($id, 'buy', '1'));
            runJob(array_shift($GLOBALS['jobs']));
        }
        check('300000000', $engine->getDepth($GLOBALS['symbol'], 'sell')[0]['volume']);
        $event = end($GLOBALS['events']);
        check(DeleteOrderSuccEvent::class, get_class($event));
        check('2', $event->order->oid);
        check('300000000', $event->order->volume);
    },
    'both cancellation paths reject another owner at submission and execution' => function () use ($engine) {
        $maker = new Order('01', '1', $GLOBALS['symbol'], 'sell', '3', '1');
        $otherOwner = new Order('1', '1', $GLOBALS['symbol'], 'sell', '1', '1');
        $engine->pushQueue($maker);
        runJob(array_shift($GLOBALS['jobs']));
        foreach ([null, '1'] as $volume) {
            rejectedCancellation(function () use ($engine, $otherOwner, $volume) {
                $engine->deleteOrder($otherOwner, $volume);
            }, DeleteOrderException::NOT_MATCH);
            runJob(new DeleteOrderJob($otherOwner, $volume !== null));
        }
        check([], $GLOBALS['jobs']);
        check(1, count($GLOBALS['events']));
        check('300000000', $engine->getDepth($GLOBALS['symbol'], 'sell')[0]['volume']);
    },
    'partial and full cancellation retries preserve the committed quantity' => function () use ($engine) {
        $order = order(1, 'sell', '3');
        $engine->pushQueue($order);
        runJob(array_shift($GLOBALS['jobs']));
        $engine->deleteOrder($order, '1.000000009');
        $events = retryAfterDeliveryFailure(array_shift($GLOBALS['jobs']), DeleteOrderSuccEvent::class, 1);
        check('100000000', $events[0]->order->volume);
        check('200000000', $engine->getDepth($GLOBALS['symbol'], 'sell')[0]['volume']);
        $engine->deleteOrder($order);
        $events = retryAfterDeliveryFailure(array_shift($GLOBALS['jobs']), DeleteOrderSuccEvent::class, 1);
        check('200000000', $events[0]->order->volume);
        check([], $engine->getDepth($GLOBALS['symbol'], 'sell'));
    },
];

function retryAfterDeliveryFailure($job, string $type, int $count): array
{
    $failed = [];
    $GLOBALS['listener'] = function ($event) use ($type, &$failed) {
        if ($event instanceof $type) {
            $failed[] = $event;
            throw new LogicException('injected delivery failure');
        }
    };
    try {
        runJob($job);
        throw new RuntimeException('Expected delivery failure');
    } catch (LogicException $exception) {
        check('injected delivery failure', $exception->getMessage());
    }
    $delivered = [];
    $GLOBALS['listener'] = function ($event) use ($type, &$delivered) {
        if ($event instanceof $type) {
            $delivered[] = $event;
        }
    };
    runJob($job);
    runJob($job);
    check($count, count($delivered));
    check(true, !empty($failed[0]->eventId));
    check($failed[0]->eventId, $delivered[0]->eventId);

    return $delivered;
}

$failures = 0;
foreach ($tests as $name => $test) {
    $GLOBALS['symbol'] = 'mengine-test-'.bin2hex(random_bytes(8));
    $GLOBALS['strict'] = false;
    $GLOBALS['jobs'] = $GLOBALS['events'] = [];
    $GLOBALS['listener'] = function ($event) { $GLOBALS['events'][] = $event; };
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failures;
        fwrite(STDERR, "FAIL $name: ".$error->getMessage()."\n".$error->getTraceAsString()."\n");
    } finally {
        RedisFacade::$beforeWrite = null;
        $keys = $primary->keys($GLOBALS['symbol'].':*');
        if ($keys) {
            $primary->del($keys);
        }
    }
}
exit($failures ? 1 : 0);
