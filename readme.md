# Laravel Package for Matching Engine

## 快速开始

- 安装: `composer require sting_bo/mengine`
- 复制配置文件: `php artisan vendor:publish`


### 依赖
* predis

### 使用说明
* 已有数据的系统如果使用此库，可以自己写一个初始化脚本，先把数据跑入队列

* #### 用户下单 ####

* 下单后，先存入数据库，然后才开始下面步骤，实例化单据对象

```php
use StingBo\Mengine\Core\Order;

$uuid = 3; // 用户唯一标识
$oid = 4; // 订单唯一标识
$symbol = 'abc2usdt'; // 交易对
$transaction = 'buy'; // 交易方向，buy/sale
$price = 0.4; // 交易价格，会根据设置精度转化为整数
$volume = 15; // 交易数量，会根据设置精度转化为整数

$order = new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
```

`交易方向`与`交易精度`可在配置文件灵活设置
```php
return [
    'mengine' => [
        // 交易类型，不可更改
        'transaction' => [
            'buy',
            'sale',
        ],
        // 精度，可更改
        'accuracy' => 8, //default        
        'test2usdt_accuracy' => 4, //设置交易对精度则使用，没有则取accuracy
    ],
];

```

* push到队列，队列任务需要手动开启
```php
$ms = new MengineService();
$ms->pushQueue($order);
```
开启队列任务：
`php artisan queue:work --queue=abc2usdt`
也可以使用`horizon`与`supervisor`来辅助，事半功倍！

队列消费时会进入撮合程序，大概的步骤如下:    
1. 获取匹配委托订单
2. 如果没有匹配的订单，则进入委托池，触发委托池变更事件，详见第5点
3. 如果有匹配的委托，程序撮合，更新委托池数据  
4. 交易成功会触发事件，开发者要在监听器里处理有交易的委托单，比如更新数据库数据，WebSocket通知等
在EventServiceProvider里为撮合成功的事件注册监听器:
```php
// 撮合成功通知，参数分别是：当前订单，被匹配的单据，交易数量
event(new MatchEvent($order, $match_order, $match_volume));

// 注册监听器
protected $listen = [
    'StingBo\Mengine\Events\MatchEvent' => [
        'App\Listeners\YourListener', // 你自己的监听器，应该也使用异步来实现
    ],
];
```
5. 如果只是部分成交，则剩余部分进入委托池，触发委托池变更事件，K线或者深度列表变更通知等，
注册监听器如下：
```php
// 委托池数据变更事件
event(new PushQueueEvent($order));

// 注册监听器
protected $listen = [
    'StingBo\Mengine\Events\PushQueueEvent' => [
        'App\Listeners\YourListener', // 你自己的监听器，应该也使用异步来实现
    ],
];
```

* #### 用户撤单 ####
撤单流程应该是先查询数据库确认是否可撤销，再从redis里删除数据成功，最后更新回数据库    
```php
$order = new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
$ms = new MengineService();
$ms->deleteOrder($order);
```
此撮合引擎没有实现像数据库那样的锁机制，为了防止有单子在被撮合时又有撤销的命令出现，所以下单与撤单都走的同一个队列，保证了顺序性，每个交易对是隔离的队列，效率也有一定的保证，但开发需要实现异步通知用户功能，注册监听器如下：
```php
// 撤单成功通知
event(new DeleteOrderSuccEvent($order));

// 注册监听器
protected $listen = [
    'StingBo\Mengine\Events\DeleteOrderSuccEvent' => [
        'App\Listeners\YourListener', // 你自己的监听器，应该也使用异步来实现
    ],
];
```

* #### 获取某个交易对买/卖深度列表 ####
```php
$symbol = 'abc2cny';
$transaction = 'buy';
$ms = new MengineService();
$ms->getDepth($symbol, $transaction);
```

### 总结

本地垃圾笔记本上测试，交易对撮合速度平均在200笔/s，后续将继续优化撮合速度

![基于redis的撮合引擎设计](https://raw.githubusercontent.com/stingbo/image/master/%E6%95%B0%E5%AD%97%E8%B4%A7%E5%B8%81%E4%BA%A4%E6%98%93%E6%89%80-%E5%9F%BA%E4%BA%8Eredis%E7%9A%84%E7%AE%80%E5%8D%95%E6%92%AE%E5%8D%95%E5%BC%95%E6%93%8E.png)
