<?php
/**
 ** RAYSWOOLE [ HIGH PERFORMANCE CMS BASED ON SWOOLE ]
 ** ----------------------------------------------------------------------
 ** Copyright © 2020 http://haoguangyun.com All rights reserved.
 ** ----------------------------------------------------------------------
 ** Author: haoguangyun <admin@haoguangyun.com>
 ** ----------------------------------------------------------------------
 ** Last-Modified: 2020-08-14 18:07
 ** ----------------------------------------------------------------------
 **/

namespace rayswoole\memcache;

use EasySwoole\Memcache\Memcache as EasyMemcache;
use Swoole\Coroutine;

class MemcacheClient
{
    static $config;
    /**
     * 连接Redis
     * @param $conf
     * @return \Redis|\RedisCluster
     * @throws \Exception
     */
    static function get($conf)
    {
        if (!isset(static::$config)){
            static::$config = new \EasySwoole\Memcache\Config([
                'host' => $conf['server'],
                'port' => $conf['port']
            ]);
        }
        if (Coroutine::getCid() > 0){
            return new EasyMemcache(static::$config);
        } else {
            return new \Memcached();
        }
    }
}