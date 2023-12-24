# Laravel Package for Matching Engine

- [中文文档](README_CN.md)

## Quick Start

- Install: `composer require sting_bo/mengine`
- Copy configuration file: `php artisan vendor:publish`


### Dependencies
* predis

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

        // If strict mode is set to true, it will validate that the decimal places of the transaction volume or price must be less than the configured length, otherwise the order will fail.
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
This matching engine does not implement locking mechanisms like databases. To prevent a situation where an order is being matched and a cancellation command is issued, both placing and canceling orders use the same queue to ensure order, and each trading pair has an isolated queue. This ensures efficiency, but developers need to implement asynchronous notification functionality. Register the listener as follows:
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

### Summary

Tested on a local, average matching speed for transactions is around 200 per second. Further optimizations for matching speed are planned for the future.

![Design of a Matching Engine Based on Redis](https://raw.githubusercontent.com/stingbo/image/master/CryptocurrencyExchange-SimpleMatchingEngineBasedOnRedis.png)

## Technical Support

| contact us | detail |
| :---: | :---: |
| QQ Group | 871358160 |
| Email | sting_bo@163.com |
