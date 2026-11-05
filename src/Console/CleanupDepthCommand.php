<?php

namespace StingBo\Mengine\Console;

use Illuminate\Console\Command;
use StingBo\Mengine\Services\DepthCleanupService;

final class CleanupDepthCommand extends Command
{
    protected $signature = 'mengine:cleanup-depth
        {symbols* : Trading pairs to scan}
        {--batch=200 : Maximum candidates checked per Redis batch}
        {--execute : Delete orphaned zero fields}';

    protected $description = 'Preview or delete historical zero-volume depth fields';

    public function handle(DepthCleanupService $cleanup): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => DepthCleanupService::MAX_BATCH_SIZE],
        ]);
        if ($batchSize === false) {
            $this->error('Batch size must be between 1 and '.DepthCleanupService::MAX_BATCH_SIZE.'.');

            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $label = $execute ? 'deleted' : 'eligible for deletion (preview)';
        foreach (array_unique($this->argument('symbols')) as $symbol) {
            $count = $cleanup->clean($symbol, $batchSize, $execute);
            $this->line("$symbol — $count fields $label");
        }

        return self::SUCCESS;
    }
}
