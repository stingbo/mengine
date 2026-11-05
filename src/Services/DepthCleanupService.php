<?php

namespace StingBo\Mengine\Services;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;

final class DepthCleanupService
{
    public const DEFAULT_BATCH_SIZE = 200;
    public const MAX_BATCH_SIZE = 1000;

    public function clean(string $symbol, int $batchSize = self::DEFAULT_BATCH_SIZE, bool $execute = false): int
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Batch size must be between 1 and '.self::MAX_BATCH_SIZE.'.');
        }

        [$buy, $sell] = config('mengine.mengine.transaction', ['buy', 'sell']);
        $key = $symbol.':depth';
        $prefix = $key.':';
        $cursor = '0';
        $total = 0;
        do {
            $page = Redis::eval(
                "return redis.call('HSCAN', KEYS[1], ARGV[1], 'COUNT', ARGV[2])",
                1,
                $key,
                $cursor,
                $batchSize
            );
            if (!is_array($page)) {
                throw new RuntimeException('Redis did not return a depth scan page.');
            }
            [$cursor, $fields] = $page;
            $candidates = [];
            for ($i = 0; $i < count($fields); $i += 2) {
                $field = $fields[$i];
                if ($fields[$i + 1] === '0' && str_starts_with($field, $prefix) && $field !== $prefix) {
                    $candidates[] = $field;
                }
            }
            // HSCAN's COUNT is a hint, so cap each Lua batch separately.
            foreach (array_chunk($candidates, $batchSize) as $batch) {
                $total += $this->checkBatch($key, $symbol.':'.$buy, $symbol.':'.$sell, $batch, $execute);
            }
        } while ($cursor !== '0');

        return $total;
    }

    private function checkBatch(string $key, string $buyKey, string $sellKey, array $fields, bool $execute): int
    {
        $script = <<<'LUA'
local matched = 0
for i = 3, #ARGV do
    local field = ARGV[i]
    local member = string.sub(field, tonumber(ARGV[2]))
    -- Recheck the live hash and both price indexes inside the delete operation.
    if redis.call('HGET', KEYS[1], field) == '0'
        and not redis.call('ZSCORE', KEYS[2], member)
        and not redis.call('ZSCORE', KEYS[3], member) then
        if ARGV[1] == '1' then
            redis.call('HDEL', KEYS[1], field)
        end
        matched = matched + 1
    end
end
return matched
LUA;

        $result = Redis::eval($script, 3, $key, $buyKey, $sellKey, $execute ? '1' : '0', strlen($key) + 2, ...$fields);
        if (!is_int($result)) {
            throw new RuntimeException('Redis did not return a depth cleanup count.');
        }

        return $result;
    }
}
