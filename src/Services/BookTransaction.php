<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Plans writes in PHP against the existing Redis schema. A changed book
 * revision invalidates every read in the plan, including sorted-set ranges.
 */
final class BookTransaction
{
    private array $fields = [];
    private array $commands = [];

    public static function run(string $symbol, callable $plan)
    {
        $key = $symbol.':mengine:revision';
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $revision = (string) Redis::get($key);
            $transaction = new self();
            try {
                $result = $plan($transaction);
            } catch (Throwable $error) {
                if ((string) Redis::get($key) === $revision) {
                    throw $error;
                }
                continue;
            }

            if ($transaction->commit($key, $revision)) {
                return $result;
            }
        }

        throw new RuntimeException('The order book changed during the operation. Retry the job.');
    }

    public function hget($key, $field): ?string
    {
        if (!array_key_exists($field, $this->fields[$key] ?? [])) {
            $value = Redis::hget($key, $field);
            $this->fields[$key][$field] = ($value === false || $value === null) ? null : $value;
        }

        return $this->fields[$key][$field];
    }

    public function hset($key, $field, $value): void
    {
        $this->fields[$key][$field] = $value;
        $this->commands[] = ['HSET', $key, $field, $value];
    }

    public function hdel($key, $field): void
    {
        $this->fields[$key][$field] = null;
        $this->commands[] = ['HDEL', $key, $field, ''];
    }

    public function zadd($key, $score, $member): void
    {
        $this->commands[] = ['ZADD', $key, $score, $member];
    }

    public function zrem($key, $member): void
    {
        $this->commands[] = ['ZREM', $key, $member, ''];
    }

    private function commit(string $key, string $revision): bool
    {
        if (!$this->commands) {
            return (string) Redis::get($key) === $revision;
        }

        $script = <<<'LUA'
if (redis.call('GET', KEYS[1]) or '') ~= ARGV[1] then
    return 0
end

-- Redis scripts do not roll back on runtime errors. Check key types before writing.
local types = {}
for i = 3, #ARGV, 4 do
    local command = ARGV[i]
    local key = KEYS[tonumber(ARGV[i + 1])]
    local expected = string.sub(command, 1, 1) == 'H' and 'hash' or 'zset'
    local actual = types[key] or redis.call('TYPE', key).ok
    if actual ~= 'none' and actual ~= expected then
        return redis.error_reply('Unexpected Redis key type for order book')
    end
    types[key] = expected
    if command == 'ZADD' then
        local score = tonumber(ARGV[i + 2])
        if not score or score <= 0 or score > 9007199254740991 then
            return redis.error_reply('Invalid order book price score')
        end
    end
end

for i = 3, #ARGV, 4 do
    local command = ARGV[i]
    local key = KEYS[tonumber(ARGV[i + 1])]
    if command == 'HSET' or command == 'ZADD' then
        redis.call(command, key, ARGV[i + 2], ARGV[i + 3])
    else
        redis.call(command, key, ARGV[i + 2])
    end
end
redis.call('SET', KEYS[1], ARGV[2])
return 1
LUA;

        $keys = [$key => 1];
        $arguments = [$revision, bin2hex(random_bytes(16))];
        foreach ($this->commands as [$command, $redisKey, $first, $second]) {
            $keys[$redisKey] = $keys[$redisKey] ?? count($keys) + 1;
            array_push($arguments, $command, (string) $keys[$redisKey], $first, $second ?? '');
        }

        $result = Redis::eval($script, count($keys), ...array_merge(array_keys($keys), $arguments));
        if ($result !== 0 && $result !== 1) {
            throw new RuntimeException('Could not commit order book changes to Redis.');
        }

        return $result === 1;
    }
}
