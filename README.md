# rayswoole/memcache

基于PHP7.1+ 和 easyswoole/memcache协程连接,实现的memcache连接池：

* 直接使用TCP协议连接, 不依赖memcached扩展
* 实现了memcache的连接池, 减少频繁建立连接的开销
* 仅支持单机模式
* 支持自动保持连接
* 支持保持最小空闲连接数量, 满足突发连接
* 弹性伸缩

## 安装
~~~
composer require rayswoole/memcache-pool
~~~

## 文档

### 连接池配置
~~~
可以在onStart直接配置
每个worker进程都会生成同等配置的进程池, 请根据worker数量动态调整
~~~

```php
//初始化连接配置
$redisConfig = new \rayswoole\memcache\MemcacheConfig();
//设置最小闲置连接数
$redisConfig->withMin(20);
//设置最大连接数
$redisConfig->withMax(100);
//设置定时器执行频率(毫秒),创建最小空间连接、回收空闲连接
$redisConfig->withIntervalTime(15*1000);
//设置连接可空闲时间
$redisConfig->withIdleTime(30);
//获取连接池对象超时时间, 如果连接池占满在指定时间无法释放新的连接, 将输出Exception, 需要自行捕获
$redisConfig->withTimeout(3.0);
//数据库配置注入
$redisConfig->withExtraConf('memcache配置');
//初始化连接池
\rayswoole\memcache\facade\Memcache::init($redisConfig);
```

### redis 配置结构
```php
$config = [
    'server' => '127.0.0.1',
    'port' => 11211,
];
```

### 进程池使用示例
```php
use rayswoole\memcache\facade\Memcache;

Memcache::getInstance()->set('aa',1234);

Memcache::getInstance()->get('aa');

Memcache::getInstance()->clear();
```

### 不通过连接池直连
```php
$config = [
    'server' => '127.0.0.1',
    'port' => 11211,
];

$memcache = \rayswoole\memcache\MemcacheClient::get($config);

$memcache->set('aa',1234);
```

