# Laravel Package for Matching Engine

[![CI status](https://github.com/stingbo/mengine/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/stingbo/mengine/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/sting_bo/mengine)](https://packagist.org/packages/sting_bo/mengine)
[![PHP 8.4+](https://img.shields.io/badge/PHP-%3E%3D%208.4-777BB4)](https://www.php.net/releases/8.4/en.php)

- [中文文档](README_CN.md)

## Quick Start

- Install: `composer require sting_bo/mengine`
- Copy configuration file: `php artisan vendor:publish`


### Dependencies
* PHP 8.4 or later in the PHP 8 series
* BCMath extension
* Predis 2.4+ or 3.1+

### News

* **[Golang Microservice Matching Engine is Now Available](https://github.com/stingbo/gome)**，feel free to use and raise issues.

### Usage Instructions
* For existing systems with data, if using this library, you can write an initialization script to first run the data into the queue.

* #### Placing an Order ####

* After placing an order, store it in the database and then instantiate the order object.

```php
use StingBo\Mengine\Core\Order;

$uuid = 3; // User unique identifier
$oid = 4; // Order unique identifier
$symbol = 'abc2usdt'; // Trading pair
$transaction = 'buy'; // Trading direction, buy/sell
$price = 0.4; // Trading price, will be converted to an integer based on the set precision
$volume = 15; // Trading quantity, will be converted to an integer based on the set precision

$order = new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
```

`Transaction direction`and`precision`can be flexibly set in the configuration file.
```php
return [
    'mengine' => [
        // Transaction types, not subject to change.
        'transaction' => [
            'buy',
            'sell',
        ],

        // Default precision, can be changed.
        'accuracy' => 8,
        // If the precision for the trading pair is set, use it; otherwise, take the default accuracy.
        'abc2usdt_accuracy' => 6, // Example of a trading pair
        'test2usdt_accuracy' => 7, // Example of a trading pair

        // If strict mode is set to true, it will validate that the decimal places of the transaction volume or price must not exceed the configured length, otherwise the order will fail.
        // If strict mode is set to false, the data will be truncated to the configured decimal places length.
        'strict_mode' => false,
    ],
];
```

* Push to the queue, queue tasks need to be manually started.
```php
use StingBo\Mengine\Services\MengineService;

$ms = new MengineService();
$ms->pushQueue($order);
```
Start the queue task:
`php artisan queue:work --queue=abc2usdt`
You can also use `horizon` and `supervisor` to assist, making your work more efficient!

When the queue is consumed, it will enter the matching program. The general steps are as follows: 
1. Get matching delegated orders.
2. If there are no matching orders, enter the order pool, triggering the order pool change event, see point 5.
3. If there are matching orders, the program matches and updates the order pool data.  
4. Successful transactions trigger events. Developers should handle orders with transactions in listeners, such as updating database data, WebSocket notifications, etc.
In EventServiceProvider, register listeners for successful matches:
```php
// Successful match notification, parameters are: current order, matched order, transaction quantity
event(new MatchEvent($order, $match_order, $match_volume));

// Register listener
protected $listen = [
    'StingBo\Mengine\Events\MatchEvent' => [
        'App\Listeners\YourListener', // Your own listener, should also be implemented asynchronously
    ],
];
```
5. If only partially filled, the remaining part enters the order pool, triggering the order pool change event, notifying K-line or depth list changes, etc.
Register the listener as follows:
```php
// Order pool data change event
event(new PushQueueEvent($order));

// Register listener
protected $listen = [
    'StingBo\Mengine\Events\PushQueueEvent' => [
        'App\Listeners\YourListener', // Your own listener, should also be implemented asynchronously
    ],
];
```

* #### Canceling an Order ####
The cancellation process should be to first query the database to confirm if it can be canceled, then successfully delete the data from redis, and finally update the database.
```php
$order = new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
$ms = new MengineService();
$ms->deleteOrder($order);
```

Calling `deleteOrder($order)` cancels the entire unfilled quantity when the cancellation job runs. The `$volume` passed to the `Order` constructor does not affect the cancellation amount. If the order is still pending, its original placement job skips matching and sends the cancellation event with the original quantity. Partial cancellation requires an order already on the book.

For partial cancellation, pass the requested quantity in trading units as the second argument:

```php
$ms->deleteOrder($order, 1);
```

If 3 units of a 10-unit order have already filled when the job runs, this cancels 1 unit and leaves 6 units at the same position in the order book. If the requested quantity reaches or exceeds the remaining quantity, the job cancels the entire remainder. `DeleteOrderSuccEvent` reports the quantity actually canceled in `order->volume`, scaled using the trading pair's precision.

Orders and cancellations use the same queue for each trading pair. Workers commit order book changes atomically against a Redis revision. Concurrent jobs may commit in a different order from dequeue order. Use one worker per trading pair if execution order matters.

Register the cancellation listener as follows:
```php
// Successful cancellation notification
event(new DeleteOrderSuccEvent($order));

// Register listener
protected $listen = [
    'StingBo\Mengine\Events\DeleteOrderSuccEvent' => [
        'App\Listeners\YourListener', // Your own listener, should also be implemented asynchronously
    ],
];
```

* #### Obtaining Buy/Sell Depth List for a Trading Pair ####
```php
$symbol = 'abc2cny';
$transaction = 'buy';
$ms = new MengineService();
$ms->getDepth($symbol, $transaction);
```

### Precision and event recovery

Pass prices and quantities as decimal strings to preserve the original input. The engine stores scaled integer strings. Strict mode accepts up to the configured number of decimal places. With strict mode disabled, excess places are truncated. Scaled prices and per-order quantities must be between 1 and 9,007,199,254,740,991, inclusive.

Order book changes and pending events are saved in one Redis operation. Queue retries resume event delivery without repeating the mutation. Delivery is at least once. Listeners that update a database should deduplicate by the event's `eventId` in the same database transaction as their update. After retries are exhausted, call `(new CommissionPoolService())->replayPendingEvents($symbol)` to deliver pending events for the pair. Redis keeps operation receipts to recognize retries even after delivery completes.

Deploy by stopping old workers before starting new ones so all writers use the same commit protocol. Existing exact legacy price levels can still be read. Reconcile and rebuild any legacy levels whose prices or quantities were already rounded incorrectly. Order book commits require the pair's keys to be on the same Redis server or cluster slot.

Run the regression suite against a test Redis instance with `MENGINE_REDIS_PORT=6379 php tests/regression.php`. The suite requires BCMath and uses separate Redis connections to interleave two workers. It uses PhpRedis by default. After installing Composer dependencies, set `MENGINE_REDIS_DRIVER=predis` to run it with Predis.

### Summary

Tested on a local, average matching speed for transactions is around 200 per second. Further optimizations for matching speed are planned for the future.

![Design of a Matching Engine Based on Redis](https://raw.githubusercontent.com/stingbo/image/master/CryptocurrencyExchange-SimpleMatchingEngineBasedOnRedis.png)

## Technical Support

| contact us | detail |
| :---: | :---: |
| QQ Group | 871358160 |
| Email | sting_bo@163.com |
