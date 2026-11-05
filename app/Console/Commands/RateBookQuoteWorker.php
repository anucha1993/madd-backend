<?php

namespace App\Console\Commands;

use App\Models\IntegrationSetting;
use App\Models\RateBookRun;
use App\Services\RateBookService;
use App\Support\RateBookSettings;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One of the processes a Rate Book sync starts (RateBookService::runInWorkers): quotes its share
 * of the run's jobs (every $shards-th, from $shard) and prints each row as one marked JSON line.
 * It writes nothing — the sync process stores the rows. It stops by itself once the run is no
 * longer running (finished, cancelled, or closed as stale), so it can't outlive its sync.
 */
class RateBookQuoteWorker extends Command
{
    protected $signature = 'rate-book:quote-worker {run} {shard} {shards}';

    protected $description = 'Internal: quote a share of a Rate Book run (started by rate-book:sync)';

    protected $hidden = true;

    public function handle(RateBookService $rateBookService): int
    {
        $run = RateBookRun::find((int) $this->argument('run'));
        $shard = (int) $this->argument('shard');
        $shards = max(1, (int) $this->argument('shards'));
        if (! $run || $run->status !== 'running') {
            return self::SUCCESS;
        }

        $accounts = RateBookService::accounts();
        $quoted = 0;
        foreach (RateBookService::workItems($run->settings, $accounts) as $i => $item) {
            if ($i % $shards !== $shard || $item === null) {
                continue;
            }
            if (++$quoted % 10 === 0 && ! $this->stillRunning($run)) {
                return self::SUCCESS;
            }
            $row = $rateBookService->quoteItem($item, $accounts);
            // RAW: an error message's "<…>" must not be read as console formatting.
            $this->getOutput()->writeln(RateBookService::WORKER_PREFIX.json_encode(['i' => $i, 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), OutputInterface::OUTPUT_RAW);
            RateBookService::pause();
        }

        return self::SUCCESS;
    }

    private function stillRunning(RateBookRun $run): bool
    {
        return $run->fresh()?->status === 'running'
            && (string) IntegrationSetting::get(RateBookSettings::CANCEL_KEY) !== (string) $run->id;
    }
}
