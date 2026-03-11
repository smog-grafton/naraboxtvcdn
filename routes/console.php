<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use App\Models\MediaApiToken;
use App\Models\MediaAsset;
use App\Models\MediaSource;
use App\Services\MediaSourceService;
use Illuminate\Support\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cdn:token {name} {--abilities=*} {--expires-days=}', function (string $name) {
    $abilitiesOption = $this->option('abilities');
    $abilities = is_array($abilitiesOption)
        ? array_values(array_filter(array_map('trim', $abilitiesOption)))
        : array_values(array_filter(array_map('trim', explode(',', (string) $abilitiesOption))));
    if ($abilities === [] || $abilities === ['*']) {
        $abilities = ['*'];
    }

    $expiresAt = null;
    if ($this->option('expires-days') !== null) {
        $days = (int) $this->option('expires-days');
        $expiresAt = Carbon::now()->addDays(max(1, $days));
    }

    [$tokenModel, $plainToken] = MediaApiToken::issue($name, $abilities, $expiresAt);

    $this->info('Token created successfully.');
    $this->line('Token ID: ' . $tokenModel->id);
    $this->line('Use this bearer token (shown once):');
    $this->line($plainToken);
})->purpose('Issue a server-to-server CDN API token');

Artisan::command('cdn:reconcile {--minutes=30}', function (MediaSourceService $mediaSourceService) {
    $minutes = max(1, (int) $this->option('minutes'));

    /** @var \Illuminate\Support\Collection<int, MediaSource> $staleSources */
    $staleSources = MediaSource::with('asset')
        ->whereIn('status', ['downloading', 'processing', 'proxying', 'uploading'])
        ->where('updated_at', '<', now()->subMinutes($minutes))
        ->get();

    $requeued = 0;
    $markedFailed = 0;
    $restoredReady = 0;

    foreach ($staleSources as $source) {
        /** @var MediaSource $source */
        if ($source->storage_path && $source->storage_disk) {
            $exists = \Illuminate\Support\Facades\Storage::disk($source->storage_disk)->exists($source->storage_path);
            if ($exists) {
                $source->update([
                    'status' => 'ready',
                    'failure_reason' => null,
                ]);
                $mediaSourceService->refreshAssetStatus($source->asset);
                $restoredReady++;
                continue;
            }
        }

        if ($source->source_type === 'remote_fetch' && $source->source_url) {
            $mediaSourceService->queueRemoteImport($source);
            $requeued++;
        } else {
            $source->update([
                'status' => 'failed',
                'failure_reason' => 'Reconciler marked source as failed after stale processing state.',
            ]);
            $mediaSourceService->refreshAssetStatus($source->asset);
            $markedFailed++;
        }
    }

    $this->info("Requeued: {$requeued}, restored ready: {$restoredReady}, marked failed: {$markedFailed}");
})->purpose('Reconcile stale CDN media source imports');

Artisan::command('media:retry-failed-optimizations {--limit=5} {--stale-minutes=30}', function (MediaSourceService $mediaSourceService) {
    $limit = max(1, (int) $this->option('limit'));
    $staleMinutes = max(5, (int) $this->option('stale-minutes'));

    // Only re-queue actually failed sources. Do NOT re-queue pending/processing (they are
    // already in the queue or running); that caused hundreds of duplicate jobs.
    $sources = MediaSource::with('asset')
        ->where('status', 'ready')
        ->where('optimize_status', 'failed')
        ->limit($limit)
        ->get();

    $requeued = 0;
    foreach ($sources as $source) {
        /** @var MediaSource $source */
        $mediaSourceService->queuePlaybackProcessing($source);
        $requeued++;
    }

    $this->info("Re-queued {$requeued} media source(s) for optimization.");
})->purpose('Re-queue failed optimization sources only (no duplicate re-queue of pending)');

Artisan::command('media:queue-pending-for-worker {--limit=0 : Max sources to queue (0 = no limit)}', function (MediaSourceService $mediaSourceService) {
    $limit = (int) $this->option('limit');
    $query = MediaSource::with('asset')
        ->where('status', 'ready')
        ->whereIn('optimize_status', ['pending', 'failed']);
    if ($limit > 0) {
        $query->limit($limit);
    }
    $sources = $query->get();

    $queued = 0;
    foreach ($sources as $source) {
        /** @var MediaSource $source */
        $mediaSourceService->queuePlaybackProcessing($source);
        $queued++;
    }

    $workerEnabled = (bool) config('cdn.laravel_worker_enabled', false);
    $this->info("Queued {$queued} media source(s) for playback processing." . ($workerEnabled ? ' (Laravel worker is enabled – jobs sent to worker.)' : ' (Laravel worker disabled – jobs on local optimization queue.)'));
})->purpose('Queue all pending/failed optimization sources; when CDN_LARAVEL_WORKER_ENABLED=true they are sent to the worker');

Artisan::command('media:refresh-asset-statuses {--importing-only : Only refresh assets currently marked as importing}', function (MediaSourceService $mediaSourceService) {
    $importingOnly = $this->option('importing-only');

    $query = MediaAsset::with('sources');
    if ($importingOnly) {
        $query->where('status', 'importing');
    }
    /** @var \Illuminate\Support\Collection<int, MediaAsset> $assets */
    $assets = $query->get();

    $updated = 0;
    foreach ($assets as $asset) {
        /** @var MediaAsset $asset */
        $mediaSourceService->refreshAssetStatus($asset);
        $updated++;
    }

    $this->info("Refreshed status for {$updated} media asset(s).");
})->purpose('Recompute and fix media asset status from source states (fix stuck importing)');

Artisan::command('media:process-optimization-queue {--max-jobs=10 : Max optimization jobs to run}', function () {
    $maxJobs = max(1, (int) $this->option('max-jobs'));

    $this->info("Processing up to {$maxJobs} optimization job(s)...");
    Artisan::call('queue:work', [
        '--queue' => 'optimization',
        '--max-jobs' => $maxJobs,
        '--stop-when-empty' => true,
        '--tries' => 3,
        '--timeout' => 7200,
    ]);

    $this->info('Done.');
})->purpose('Run optimization queue worker to process pending/failed optimization jobs');

Artisan::command('media:clear-optimization-queue', function () {
    $connection = config('queue.default');
    $driver = config("queue.connections.{$connection}.driver");

    if ($driver !== 'database') {
        $this->warn("Queue driver is '{$driver}'. Clear optimization jobs manually (e.g. Redis: flush the optimization list).");
        return;
    }

    $table = config("queue.connections.{$connection}.table", 'jobs');
    $dbConnection = config("queue.connections.{$connection}.connection");
    $deleted = DB::connection($dbConnection)->table($table)->where('queue', 'optimization')->delete();

    $this->info("Cleared {$deleted} job(s) from the optimization queue. Pending sources will need to be re-queued (retry or Run re-optimise).");
})->purpose('Clear all pending optimization jobs to stop server load; re-queue later via retry or Filament');

Schedule::call(function (): void {
    Artisan::call('cdn:reconcile', ['--minutes' => 30]);
})->everyTenMinutes();

Schedule::call(function (): void {
    Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 1,
        '--tries' => 1,
        '--timeout' => 7200,
        '--max-time' => 55,
    ]);
})->everyMinute();

Schedule::call(function (): void {
    Artisan::call('queue:work', [
        '--queue' => 'optimization',
        '--max-jobs' => 1,
        '--stop-when-empty' => true,
        '--tries' => 3,
        '--timeout' => 7200,
        '--max-time' => 58,
    ]);
})->everyTwoMinutes();

Schedule::call(function (): void {
    Artisan::call('media:retry-failed-optimizations', ['--limit' => 5, '--stale-minutes' => 30]);
})->everyFiveMinutes();
