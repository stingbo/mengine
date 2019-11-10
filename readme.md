# Laravel Package for Matching Engine

## Quick Start Guide

- Add dependency: `composer require sting_bo/mengine`
- Copy configuration: `php artisan vendor:publish`


### 使用说明

1. 使用前需要初始化单据
```php
$uuid = 3; // 用户唯一标识
$oid = 4; // 订单唯一标识
$symbol = 'abc2cny'; // 交易对
$transaction = 'buy'; // 交易方向，buy/sale
$price = 0.4; // 交易价格
$volume = 15; // 交易数量

$order = new Order($uuid, $oid, $symbol, $transaction, $volume, $price);
```
目前，`交易方向`与`交易精度`可在配置文件灵活设置

2. 下单时
```php
$ms = new MengineService();
$ms->pushQueue($order);
```

3. 撤单时，订单数据输入，此订单的数量应该是未成交量，否则会导致深度统计不准确.
```php
$ms = new MengineService();
$ms->deleteOrder($order);
```
