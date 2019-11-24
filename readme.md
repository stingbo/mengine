# Laravel Package for Matching Engine

## Quick Start Guide

- Add dependency: `composer require sting_bo/mengine`
- Copy configuration: `php artisan vendor:publish`


### 使用说明

1. 依赖predis

2. 使用前需要初始化单据，`交易方向`与`交易精度`可在配置文件灵活设置
```php
$uuid = 3; // 用户唯一标识
$oid = 4; // 订单唯一标识
$symbol = 'abc2cny'; // 交易对
$transaction = 'buy'; // 交易方向，buy/sale
$price = 0.4; // 交易价格
$volume = 15; // 交易数量

$order = new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
```

3. 下单，应该是先落到事物型数据库后再调用此方法
```php
$ms = new MengineService();
$ms->pushQueue($order);
```
> 下单后会启动撮合程序
> 1. 如果没有匹配的委托，则进入委托池
> 2. 如果有匹配的委托，撮合交易后，则更新委托池
> 2.1. 交易事件会触发监听器，开发者要在监听器里处理有交易的委托单，比如更新数据库等

4. 撤单，应该先从redis里删除，然后再更新数据库;订单的数量(volume)必须是未成交量或者是成交剩余量，否则会导致深度统计不准确.
```php
$ms = new MengineService();
$ms->deleteOrder($order);
```

5. 获取深度列表
```php
$symbol = 'abc2cny';
$transaction = 'buy';
$ms = new MengineService();
$ms->getDepth($symbol, $transaction);
```
