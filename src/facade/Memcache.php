<?php
/**
 ** RAYSWOOLE [ HIGH PERFORMANCE CMS BASED ON SWOOLE ]
 ** ----------------------------------------------------------------------
 ** Copyright © 2020 http://haoguangyun.com All rights reserved.
 ** ----------------------------------------------------------------------
 ** Author: haoguangyun <admin@haoguangyun.com>
 ** ----------------------------------------------------------------------
 ** Last-Modified: 2020-08-13 09:38
 ** ----------------------------------------------------------------------
 **/

namespace rayswoole\memcache\facade;


use Swoole\Coroutine;
use rayswoole\memcache\MemcachePool;

/**
 * class Redis
 * @package rayswoole
 * @mixin \EasySwoole\Memcache\Memcache
 */
class Memcache
{
    /**
     * @var MemcachePool
     */
    private static $init;
    /**
     * @var \Redis
     */
    private static $instance;

    /**
     * 初始化连接池
     * @param array $config
     * @return MemcachePool 连接池对象
     */
    static function init(\rayswoole\memcache\MemcacheConfig $config = null)
    {
        if (!isset(self::$init) && is_object($config)){
            self::$init = new MemcachePool($config);
        }
        return self::$init;
    }

    /**
     * 获取一个连接对象
     * @return static
     * @throws \Throwable
     */
    static function getInstance()
    {
        if (!isset(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

    public function getMultiple(array $keys)
    {
        $length = count($keys);
        $chan = new Coroutine\Channel($length);
        for ($i=0; $i<$length; $i++){
            go(function () use ($chan, $i, $keys){
                $chan->push([$keys[$i]=>Memcache::getInstance()->get($keys[$i])]);
            });
        }
        $result = [];
        for ($i=0; $i<$length; $i++){
            $result += $chan->pop();
        }
        $chan->close();
        $chan = null;
        return $result;
    }

    public function setMultiple(array $values, $ttl = 0)
    {
        if (!is_array($values)){
            return false;
        }
        $ret = self::$init->defer();
        foreach ($values as $key=>$value){
            $ret->set($key, $value, $ttl);
        }
    }

    public function deleteMultiple($keys)
    {
        if (!is_array($keys)){
            return false;
        }
        $ret = self::$init->defer();
        foreach ($keys as $key){
            $ret->delete($key);
        }
    }

    public function has($key)
    {
        return self::$init->defer()->get($key) !== null;
    }

    public function set($key, $value, $ttl = null)
    {
        $time = time();
        if ($ttl > 2592000 && $ttl < $time){//根据memcache特性大于30天需要采用时间戳
            $ttl = $time + $ttl;
        }
        return self::$init->defer()->set($key, $value, $ttl);
    }

    public function inc($key, $offset)
    {
        return self::$init->defer()->increment($key, $offset);
    }

    public function dec($key, $offset = 1)
    {
        return self::$init->defer()->decrement($key, $offset);
    }

    public function clear()
    {
        return self::$init->defer()->flush();
    }

    public function isnull($value)
    {
        return $value === null;
    }

    public function __call($method, $params)
    {
        if (isset(self::$init)){
            return call_user_func_array([self::$init->defer(), $method], $params);
        } else {
            throw new \Exception('Memcached instance is not configured');
        }
    }

    public static function __callStatic($method, $params)
    {
        if (isset(self::$init)){
            return call_user_func_array([self::$init->defer(), $method], $params);
        } else {
            throw new \Exception('Memcached instance is not configured');
        }
    }
}