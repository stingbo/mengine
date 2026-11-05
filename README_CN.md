# Laravel Package for Matching Engine

[![CI 状态](https://github.com/stingbo/mengine/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/stingbo/mengine/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/sting_bo/mengine)](https://packagist.org/packages/sting_bo/mengine)
[![PHP 8.4+](https://img.shields.io/badge/PHP-%3E%3D%208.4-777BB4)](https://www.php.net/releases/8.4/en.php)

## 快速开始

- 安装: `composer require sting_bo/mengine`
- 复制配置文件: `php artisan vendor:publish`


### 依赖
* PHP 8.4 及以上的 PHP 8 版本
* BCMath 扩展
* Predis 2.4+ 或 3.1+

### 号外

* **[已经出Golang微服务撮合啦](https://github.com/stingbo/gome)**，欢迎使用并提issue

### 使用说明
* 已有数据的系统如果使用此库，可以自己写一个初始化脚本，先把数据跑入队列

* #### 用户下单 ####

* 下单后，先存入数据库，然后才开始下面步骤，实例化单据对象

```php
use StingBo\Mengine\Core\Order;

$uuid = 3; // 用户唯一标识
$oid = 4; // 订单唯一标识
$symbol = 'abc2usdt'; // 交易对
$transaction = 'buy'; // 交易方向，buy/sell
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
            'sell',
        ],
        
        // 默认精度，可更改
        'accuracy' => 8,
        // 设置了交易对精度则使用，没有则取accuracy
        'abc2usdt_accuracy' => 6, // 交易对示例
        'test2usdt_accuracy' => 7, // 交易对示例
        
        // 如果严格模式为true，会校验交易量或价格的小数位长度不能超过设置的长度，否则会下单失败
        // 如果严格模式为false，则会截断数据到设定的小数位长度
        'strict_mode' => false,
    ],
];

```

* push到队列，队列任务需要手动开启
```php
use StingBo\Mengine\Services\MengineService;

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

调用 `deleteOrder($order)` 会在撤单任务执行时撤销订单的全部剩余未成交量。传给 `Order` 构造函数的 `$volume` 不影响撤单数量。 如果订单尚未入簿，原下单任务会跳过撮合与入簿，并按原下单量发出撤单成功事件。部分撤单要求订单已经入簿。

部分撤单时，将希望撤销的数量按原始交易单位传入第二个参数：

```php
$ms->deleteOrder($order, 1);
```

例如，订单原有 10 股，任务执行时已成交 3 股，这次会撤掉 1 股，剩下的 6 股保留原有排队位置。如果请求数量达到或超过当时的剩余量，就撤掉全部剩余量。`DeleteOrderSuccEvent` 的 `order->volume` 是实际撤销量，已按交易对精度换算为整数单位。

同一交易对的下单和撤单使用同一个队列。worker 根据 Redis 订单簿版本原子提交变更。并发任务的提交顺序可能与出队顺序不同。如果业务要求按出队顺序执行，每个交易对应只运行一个 worker。

注册撤单成功监听器如下：
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

### 精度和事件恢复

价格和数量建议使用十进制字符串传入，以保留输入精度。引擎以整数单位字符串存储数值。严格模式允许的小数位数最多为配置精度，非严格模式会截断多余小数位。换算后的价格和单笔数量必须在 1 至 9,007,199,254,740,991 之间，含边界。

订单簿变更和待投递事件通过同一次 Redis 操作保存。队列任务重试时会继续投递事件，订单簿变更只应用一次。事件采用至少一次投递。更新数据库的监听器应在同一数据库事务中按事件的 `eventId` 去重并落库。队列耗尽重试次数后，可以调用 `(new CommissionPoolService())->replayPendingEvents($symbol)` 补发指定交易对的事件。事件投递完成后，Redis 仍保留操作凭据用于识别重试。

升级时先停止旧 worker，再启动新 worker，确保所有写入方使用同一提交协议。旧订单簿中仍精确的价位可以继续读取。若旧价位的价格或数量已经错误舍入，升级时需要核对并重建这些价位。一个交易对的 Redis 键必须位于同一实例或同一集群槽位。

使用 `MENGINE_REDIS_PORT=6379 php tests/regression.php` 对测试 Redis 实例运行回归测试。测试依赖 BCMath，并使用两个 Redis 连接模拟 worker 交错执行。默认使用 PhpRedis。安装 Composer 依赖后，可设置 `MENGINE_REDIS_DRIVER=predis` 改用 Predis。

### 清理历史零值深度字段

在宿主 Laravel 项目中运行 Artisan 命令，显式传入要扫描的交易对。第一条命令按交易对预览可清理字段数。加上 `--execute` 后执行删除。

```bash
php artisan mengine:cleanup-depth btc2usdt eth2usdt --batch=200
php artisan mengine:cleanup-depth btc2usdt eth2usdt --batch=200 --execute
```

Redis 会在删除时重新检查字段仍为零，且该价位未出现在买卖任一索引中。预览反映扫描时的订单簿状态，实际删除数量可能随挂单变化。批大小默认为 200，可设为 1 至 1000。

### 总结

本地垃圾笔记本上测试，交易对撮合速度平均在200笔/s，后续将继续优化撮合速度

![基于redis的撮合引擎设计](https://raw.githubusercontent.com/stingbo/image/master/%E6%95%B0%E5%AD%97%E8%B4%A7%E5%B8%81%E4%BA%A4%E6%98%93%E6%89%80-%E5%9F%BA%E4%BA%8Eredis%E7%9A%84%E7%AE%80%E5%8D%95%E6%92%AE%E5%8D%95%E5%BC%95%E6%93%8E.png)

## 技术支持

| 联系方式 | 联系我 |
| :---: | :---: |
| QQ群 | 871358160 |
| 邮箱 | sting_bo@163.com |
