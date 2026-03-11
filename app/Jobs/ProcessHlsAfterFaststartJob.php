<?php

namespace App\Jobs;

use App\Models\MediaSource;
use App\Services\MediaSourceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessHlsAfterFaststartJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('optimization');
    }

    public function handle(MediaSourceService $mediaSourceService): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->status !== 'ready') {
            return;
        }

        $workerEnabled = (bool) config('cdn.laravel_worker_enabled', false);
        $pullEnabled = (bool) config('cdn.laravel_worker_pull_enabled', true);
        $enableHls = (bool) config('cdn.enable_hls', true);

        if ($workerEnabled && $pullEnabled && $enableHls) {
            $source->update(['hls_worker_status' => 'queued']);
            if ($mediaSourceService->queueLaravelWorkerPlaybackProcessing($source)) {
                $source->update(['optimize_status' => 'processing']);
                return;
            }
            $source->update(['hls_worker_status' => null]);
        } elseif ($workerEnabled && ! $pullEnabled && $enableHls) {
            if ($mediaSourceService->queueLaravelWorkerPlaybackProcessing($source)) {
                $source->update(['optimize_status' => 'processing']);
                return;
            }
        }

        if ($enableHls) {
            GenerateHlsVariantsJob::dispatch($source->id)->onQueue('optimization');
        }
    }
}
