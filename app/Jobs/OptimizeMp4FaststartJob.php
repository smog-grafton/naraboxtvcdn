<?php

namespace App\Jobs;

use App\Models\MediaSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OptimizeMp4FaststartJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200;

    public int $uniqueFor = 3600;

    public function __construct(public int $sourceId)
    {
    }

    public function uniqueId(): string
    {
        return 'optimization:faststart:' . $this->sourceId;
    }

    public function handle(): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->status !== 'ready' || ! $source->storage_path) {
            return;
        }

        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        if (! Storage::disk($disk)->exists($source->storage_path)) {
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => 'Original media file was not found for faststart optimization.',
                'is_faststart' => false,
            ]);
            return;
        }

        $extension = strtolower((string) pathinfo($source->storage_path, PATHINFO_EXTENSION));
        $mime = strtolower((string) ($source->mime_type ?? ''));
        if ($extension !== 'mp4' && $mime !== 'video/mp4') {
            $source->update([
                'optimize_status' => 'ready',
                'optimized_path' => $source->storage_path,
                'optimize_error' => null,
                'is_faststart' => false,
                'optimized_at' => now(),
                'playback_type' => 'mp4',
            ]);
            return;
        }

        $ffmpeg = (string) config('cdn.ffmpeg_binary', base_path('ffmpeg/ffmpeg'));
        if (! is_file($ffmpeg)) {
            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => 'FFmpeg binary was not found on CDN server.',
                'is_faststart' => false,
            ]);
            return;
        }

        $source->update([
            'optimize_status' => 'processing',
            'optimize_error' => null,
        ]);

        $optimizedPath = sprintf(
            'media/%s/%d/%s',
            $source->media_asset_id,
            $source->id,
            preg_replace('/\.[A-Za-z0-9]+$/', '', basename($source->storage_path)) . '_play.mp4'
        );
        $absoluteInput = Storage::disk($disk)->path($source->storage_path);
        $absoluteOutput = Storage::disk($disk)->path($optimizedPath);
        Storage::disk($disk)->makeDirectory(dirname($optimizedPath));

        $cmd = implode(' ', [
            escapeshellarg($ffmpeg),
            '-y',
            '-i',
            escapeshellarg($absoluteInput),
            '-c',
            'copy',
            '-movflags',
            '+faststart',
            escapeshellarg($absoluteOutput),
            '2>&1',
        ]);

        $outputLines = [];
        $exitCode = 0;
        @exec($cmd, $outputLines, $exitCode);

        if ($exitCode !== 0 || ! is_file($absoluteOutput) || filesize($absoluteOutput) <= 0) {
            $error = trim(implode("\n", $outputLines));
            Log::warning('Faststart optimization failed', [
                'source_id' => $source->id,
                'asset_id' => $source->media_asset_id,
                'exit_code' => $exitCode,
                'error' => $error,
            ]);

            $source->update([
                'optimize_status' => 'failed',
                'optimize_error' => $error !== '' ? $error : 'FFmpeg faststart optimization failed.',
                'is_faststart' => false,
                'optimized_path' => $source->storage_path,
                'playback_type' => 'mp4',
            ]);

            return;
        }

        $source->update([
            'optimize_status' => 'ready',
            'optimized_path' => $optimizedPath,
            'optimize_error' => null,
            'is_faststart' => true,
            'optimized_at' => now(),
            'playback_type' => 'mp4',
        ]);
    }
}

