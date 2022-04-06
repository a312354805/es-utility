<?php

namespace WonderGame\EsUtility\Common\Classes;


use EasySwoole\Component\CoroutineSingleTon;
use EasySwoole\Http\Request;
use EasySwoole\Socket\Bean\Caller;
use Swoole\Coroutine;

/**
 * 通用协程单例对象
 * Class MyCoroutine
 * @package App\Common\Classes
 */
class CtxRequest
{
    use CoroutineSingleTon;

    /**
     * Request对象
     * @var Request|null
     */
    protected $request = null;

    /**
     * @var null | Caller
     */
    protected $caller = null;

    public function getOperinfo(): array
    {
        // 暂不挂载websocket数据
        return $this->request instanceof Request ? $this->request->getAttribute('operinfo', []) : [];
    }

    public function withOperinfo(array $operinfo = []): void
    {
        if ($this->request instanceof Request)
        {
            $this->request->withAttribute('operinfo', $operinfo);
        }
    }

    public function __set($name, $value)
    {
        $name = strtolower($name);
        $this->{$name} = $value;
    }

    public function __get($name)
    {
        $name = strtolower($name);
        if (property_exists($this, $name)) {
            return $this->{$name};
        } else {
            $cid = Coroutine::getCid();
            throw new \Exception("[cid:{$cid}]CtxRequest Not Exists Protected: $name");
        }
    }
}
