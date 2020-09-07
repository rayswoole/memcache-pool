<?php
/**
 ** RAYSWOOLE [ HIGH PERFORMANCE CMS BASED ON SWOOLE ]
 ** ----------------------------------------------------------------------
 ** Idea From easyswoole/pool
 ** ----------------------------------------------------------------------
 ** Author: haoguangyun <admin@haoguangyun.com>
 ** ----------------------------------------------------------------------
 ** Last-Modified: 2020-08-11 16:49
 ** ----------------------------------------------------------------------
 **/


namespace rayswoole\memcache;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

class MemcachePool
{
    private static $counter = 0;
    private $createdNum = 0;
    /** @var Channel */
    private $poolChannel;
    /** @var MemcacheConfig */
    private $conf;
    private $timerId;
    private $destroy = false;
    private $context = [];
    /**
     * 并发锁定, 防止高并发导致导致的抢占
     * @var bool
     */
    private $createIng = false;

    public function __construct(MemcacheConfig $conf)
    {
        $this->conf = $conf;
    }

    /**
     * 创建\Redis对象
     * @return \EasySwoole\Memcache\Memcache
     * @throws \Exception
     */
    public function getObj()
    {
        return MemcacheClient::get($this->conf->getExtraConf());
    }

    /**
     * 生产新的连接池对象
     * @return bool
     * @throws \Throwable
     */
    private function create(int $tryTimes = 10):?object
    {
        if ($this->destroy) {
            return null;
        }
        $this->init();
        if ($this->createdNum > $this->conf->getMax()) {
            return null;
        }
        $this->createdNum++;
        $obj = $this->getObj();
        if (is_object($obj)) {
            static::$counter++;
            $hash = '_'.static::$counter.'_'.$this->createdNum;
            $obj->_hash = $hash;
            $obj->_lastTime = time();
            return $obj;
        }
        $this->createdNum--;
        return null;
    }

    /**
     * 消费连接池对象
     * @param int $tryTimes 尝试次数
     * @return \EasySwoole\Memcache\Memcache
     * @throws \Throwable
     */
    private function pop(float $timeOut = -1, int $tryTimes = 3):?object
    {
        if ($this->destroy) {
            return null;
        }
        $this->init();
        if ($tryTimes <= 0) {
            return null;
        }
        $obj = $this->poolChannel->pop($timeOut);
        if (is_object($obj) || $obj = $this->create()) {
            $obj->_free = false;
            return $obj;
        }
        return $this->pop($timeOut, --$tryTimes);
    }

    /**
     * 将消费过的连接池对象入栈到连接池
     * @param $obj \EasySwoole\Memcache\Memcache
     * @return bool
     * @throws \Exception
     */
    private function push($obj): bool
    {
        //当标记为销毁后，直接销毁连接
        if ($this->destroy) {
            $this->unset($obj);
            return false;
        }
        //如果不是正在使用的连接则直接跳过
        if (!isset($obj->_free) || $obj->_free === true){
            return true;
        }
        $obj->_lastTime = time();
        $obj->_free = true;
        if($this->poolChannel->push($obj)){
            return true;
        }else{
            $this->unset($obj);
            return false;
        }
    }

    /**
     * 删除连接对象
     * @param $obj \EasySwoole\Memcache\Memcache
     * @return bool
     */
    private function unset(&$obj): bool
    {
        $obj = null;
        $this->createdNum--;
        return true;
    }

    /**
     * 检测空间连接并回收
     * @param int $idleTime
     * @return bool 是否执行了回收
     * @throws \Exception
     */
    private function checkFree()
    {
        //进程池未初始化、进程池为空、进程池满 时均不处理
        if (!isset($this->poolChannel) || $this->poolChannel->isEmpty() || $this->poolChannel->isFull()){
            return false;
        }
        $idleTime = $this->conf->getIdleTime();
        $min = $this->conf->getMin();
        $size = $this->poolChannel->length();
        $time = time();
        if ($size > $min){
            while ($size > $min) {
                $size--;
                if(!$obj = $this->poolChannel->pop(0.01)){
                    continue;
                }
                if ($time - $obj->_lastTime > $idleTime || !$this->checkPing($obj)) {
                    $this->unset($obj);
                } else {
                    $this->push($obj);
                }
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * 心跳检查
     * @param $obj \EasySwoole\Memcache\Memcache
     * @return bool
     */
    protected function checkPing($obj):bool
    {
        if( time() - $obj->_lastTime > $this->conf->getPing() ){
            try{
                $obj->_lastTime = time();
                $ping = $obj->get('__0__');
                return true;
            }catch (\EasySwoole\Memcache\Exception\MemcacheException $memcacheException){
                return false;
            }
        }else{
            return true;
        }
    }

    /**
     * 保持进程池最少活跃连接量
     * @return int 是否执行了增加连接操作
     * @throws \Throwable
     */
    public function checkMin(): int
    {
        $this->init();
        $min = $this->conf->getMin();
        $length = $this->poolChannel->length();
        if ($length < $min && $this->createdNum < $this->conf->getMax()) {
            $left = $min - $length;
            while ($left > 0) {
                if($obj = $this->create()){
                    $obj->_free = true;
                    $this->push($obj);
                }
                $left--;
            }
            return true;
        }
        return false;
    }

    /**
     * 执行检测, 外部调用
     * @throws \Throwable
     */
    public function intervalCheck()
    {
        if (!$this->checkMin()){
            $this->checkFree();
        }
    }


    /**
     * @param float|null $timeout
     * @return \EasySwoole\Memcache\Memcache
     * @throws \Throwable
     */
    public function defer()
    {
        $cid = Coroutine::getCid();
        if (isset($this->context[$cid])) {
            return $this->context[$cid];
        }
        if ($obj = $this->pop($this->conf->getTimeout())) {
            $this->context[$cid] = $obj;
            Coroutine::defer(function () use ($cid) {
                if (isset($this->context[$cid])) {
                    $this->push($this->context[$cid]);
                    unset($this->context[$cid]);
                }
            });
            return $obj;
        } else {
            return null;
        }
    }

    /**
     * 销毁连接池
     * @throws \Exception
     */
    public function destroy()
    {
        $this->destroy = true;
        if ($this->timerId && Timer::exists($this->timerId)) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
        if(isset($this->poolChannel)){
            //将所有连接对象全部消费并断开连接
            while (!$this->poolChannel->isEmpty()) {
                $obj = $this->poolChannel->pop(0.01);
                $this->unset($obj);
            }
            $this->poolChannel->close();
            $this->poolChannel = null;
        }
    }

    /**
     * 重置连接池
     * @return static
     * @throws \Exception
     */
    public function reset(): MemcachePool
    {
        $this->destroy();
        $this->createdNum = 0;
        $this->destroy = false;
        $this->context = [];
        $this->init();
        return $this;
    }

    /**
     * 获取连接池状态
     * @return array
     * @throws \Exception
     */
    public function status()
    {
        $this->init();
        return [
            'created' => $this->createdNum,
            'used'   => $this->createdNum - (isset($this->poolChannel) ? $this->poolChannel->length() : 0),
            'max'     => $this->conf->getMax(),
            'min'     => $this->conf->getMin()
        ];
    }

    /**
     * 初始化连接池
     * @throws \Exception
     */
    public function init()
    {
        if (!isset($this->poolChannel) && (!$this->destroy)) {
            if ($this->conf->getMin() >= $this->conf->getMax()){
                throw new \Exception('min num is bigger than max');
            }
            if ($this->timerId && Timer::exists($this->timerId)) {
                Timer::clear($this->timerId);
                $this->timerId = null;
            }
            $this->createdNum = 0;
            $this->destroy = false;
            $this->context = [];
            $this->poolChannel = new Channel($this->conf->getMax() + 8);
            $this->checkMin();
            if ($this->conf->getIntervalTime() > 0) {
                $this->timerId = Timer::tick($this->conf->getIntervalTime(), [$this, 'intervalCheck']);
            }
        }
    }
}
