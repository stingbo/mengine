<?php

namespace Illuminate\Broadcasting {
    trait InteractsWithSockets {}
}

namespace Illuminate\Foundation\Events {
    trait Dispatchable {}
}

namespace Illuminate\Foundation\Bus {
    trait Dispatchable {}
}

namespace Illuminate\Bus {
    trait Queueable {
        public function allOnQueue($queue)
        {
            return $this;
        }
    }
}

namespace Illuminate\Contracts\Queue {
    interface ShouldQueue {}
}

namespace Illuminate\Queue {
    trait InteractsWithQueue {}
    trait SerializesModels {}
}

namespace Illuminate\Support\Facades {
    final class Redis
    {
        public static $client;
        public static $beforeWrite;
        public static array $commands = [];

        public static function __callStatic($method, $arguments)
        {
            self::$commands[] = [strtolower($method), $arguments[0]];

            if ($method === 'eval' && self::$beforeWrite) {
                $callback = self::$beforeWrite;
                self::$beforeWrite = null;
                $callback();
            }

            if ($method === 'eval' && self::$client instanceof \Redis) {
                [$script, $keyCount] = $arguments;
                return self::$client->eval($script, array_slice($arguments, 2), $keyCount);
            }

            $result = self::$client->$method(...$arguments);
            if ($method === 'hget' && strpos($arguments[0], ':link:') !== false
                && strpos($arguments[1], ':node:') !== false && self::$beforeWrite) {
                $callback = self::$beforeWrite;
                self::$beforeWrite = null;
                $callback();
            }

            return $result;
        }
    }
}

namespace {
    spl_autoload_register(function ($class) {
        $prefix = 'StingBo\\Mengine\\';
        if (strpos($class, $prefix) === 0) {
            require __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    }, prepend: true);

    function config($key)
    {
        return [
            'mengine.mengine.transaction' => ['buy', 'sell'],
            'mengine.mengine.accuracy' => 8,
            'mengine.mengine.strict_mode' => $GLOBALS['strict'],
        ][$key] ?? null;
    }

    function event($event)
    {
        ($GLOBALS['listener'])($event);
    }

    function dispatch($job)
    {
        $GLOBALS['jobs'][] = $job;
    }
}
