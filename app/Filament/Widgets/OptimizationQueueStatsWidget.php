<?php

namespace App\Filament\Widgets;

use App\Models\MediaSource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class OptimizationQueueStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $connection = config('queue.connections.database.connection');
        $table = config('queue.connections.database.table', 'jobs');
        $optimizationPending = 0;
        try {
            $optimizationPending = DB::connection($connection)->table($table)
                ->where('queue', 'optimization')
                ->count();
        } catch (\Throwable $e) {
            // table may not exist or queue driver may not be database
        }

        $sourcesPending = MediaSource::where('status', 'ready')
            ->whereIn('optimize_status', ['pending', 'processing'])
            ->count();

        $sourcesFailed = MediaSource::where('status', 'ready')
            ->where('optimize_status', 'failed')
            ->count();

        return [
            Stat::make('Optimization queue (jobs)', $optimizationPending)
                ->description('Jobs waiting in the optimization queue. Worker runs every 2 min, 1 job at a time.')
                ->color($optimizationPending > 0 ? 'warning' : 'success'),
            Stat::make('Sources pending optimization', $sourcesPending)
                ->description('Media sources with optimize_status pending or processing.')
                ->color($sourcesPending > 0 ? 'warning' : 'success'),
            Stat::make('Sources with failed optimization', $sourcesFailed)
                ->description('Use "Run re-optimise" on a source or wait for the retry command (every 5 min).')
                ->color($sourcesFailed > 0 ? 'danger' : 'success'),
        ];
    }
}
