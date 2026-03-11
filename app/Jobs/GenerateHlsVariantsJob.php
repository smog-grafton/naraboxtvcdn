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

class GenerateHlsVariantsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 7200;

    public int $uniqueFor = 3600;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('optimization');
    }

    public function uniqueId(): string
    {
        return 'optimization:hls:' . $this->sourceId;
    }

    public function handle(): void
    {
        $source = MediaSource::find($this->sourceId);
        if (! $source || $source->status !== 'ready' || ! $source->storage_path) {
            return;
        }

        if (! (bool) config('cdn.enable_hls', true)) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
            ]);
            return;
        }

        $disk = $source->storage_disk ?: (string) config('cdn.disk', 'public');
        $inputPath = $source->optimized_path ?: $source->storage_path;
        if (! Storage::disk($disk)->exists($inputPath)) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                'optimize_error' => 'HLS generation skipped because input file is missing.',
            ]);
            return;
        }

        $ffmpeg = (string) config('cdn.ffmpeg_binary', base_path('ffmpeg/ffmpeg'));
        if (! is_file($ffmpeg)) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                'optimize_error' => 'HLS generation failed: FFmpeg binary not found.',
            ]);
            return;
        }

        $ffprobe = (string) config('cdn.ffprobe_binary', base_path('ffmpeg/ffprobe'));
        $profiles = $this->resolveProfiles((array) config('cdn.hls_profiles', ['1080', '720', '480']));
        if ($profiles === []) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
            ]);
            return;
        }

        $inputAbsolute = Storage::disk($disk)->path($inputPath);
        $sourceHeight = $this->probeSourceHeight($ffprobe, $inputAbsolute);
        $hasAudio = $this->probeSourceHasAudio($ffprobe, $inputAbsolute);
        if (is_int($sourceHeight) && $sourceHeight > 0) {
            $profiles = array_values(array_filter(
                $profiles,
                static fn (array $profile): bool => (int) $profile['height'] <= $sourceHeight
            ));
        }
        if ($profiles === []) {
            // Always keep at least one profile when probe fails/returns tiny source.
            $profiles = [
                ['label' => 'source', 'height' => max(240, (int) ($sourceHeight ?: 480)), 'bitrate' => 900000, 'audio_bitrate' => '96k'],
            ];
        }

        $hlsBasePath = sprintf('media/%s/%d/hls', $source->media_asset_id, $source->id);
        $hlsBaseAbsolute = Storage::disk($disk)->path($hlsBasePath);
        Storage::disk($disk)->deleteDirectory($hlsBasePath);
        Storage::disk($disk)->makeDirectory($hlsBasePath);

        $generated = [];
        foreach ($profiles as $profile) {
            $label = $profile['label'];
            $height = $profile['height'];
            $bitrate = $profile['bitrate'];
            $audioBitrate = $profile['audio_bitrate'];

            $variantPath = $hlsBasePath . '/' . $label;
            $variantAbsolute = $hlsBaseAbsolute . '/' . $label;
            Storage::disk($disk)->makeDirectory($variantPath);

            $playlistAbsolute = $variantAbsolute . '/index.m3u8';
            $segmentPattern = $variantAbsolute . '/segment_%05d.ts';

            [$exitCode, $error] = $this->runHlsTranscode(
                $ffmpeg,
                $inputAbsolute,
                $playlistAbsolute,
                $segmentPattern,
                $height,
                $audioBitrate,
                $hasAudio
            );

            if ($exitCode !== 0 || ! is_file($playlistAbsolute)) {
                Log::warning('HLS variant generation failed', [
                    'source_id' => $source->id,
                    'profile' => $label,
                    'exit_code' => $exitCode,
                    'has_audio' => $hasAudio,
                    'source_height' => $sourceHeight,
                    'error' => $error,
                ]);
                continue;
            }

            $generated[] = [
                'id' => $label,
                'label' => strtoupper($label),
                'height' => $height,
                'width' => null,
                'bandwidth' => (int) $bitrate,
                'path' => $variantPath . '/index.m3u8',
            ];
        }

        if ($generated === []) {
            $fallbackDir = $hlsBasePath . '/source';
            $fallbackAbsoluteDir = Storage::disk($disk)->path($fallbackDir);
            Storage::disk($disk)->makeDirectory($fallbackDir);
            $fallbackPlaylistAbsolute = $fallbackAbsoluteDir . '/index.m3u8';
            $fallbackSegmentPattern = $fallbackAbsoluteDir . '/segment_%05d.ts';
            [$fallbackExit, $fallbackError] = $this->runHlsTranscode(
                $ffmpeg,
                $inputAbsolute,
                $fallbackPlaylistAbsolute,
                $fallbackSegmentPattern,
                max(240, (int) ($sourceHeight ?: 480)),
                '96k',
                $hasAudio
            );

            if ($fallbackExit === 0 && is_file($fallbackPlaylistAbsolute)) {
                $generated[] = [
                    'id' => 'source',
                    'label' => 'SOURCE',
                    'height' => (int) ($sourceHeight ?: 480),
                    'width' => null,
                    'bandwidth' => 900000,
                    'path' => $fallbackDir . '/index.m3u8',
                ];
            } else {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                    'optimize_error' => 'HLS generation failed for all quality profiles. Last error: ' . $fallbackError,
            ]);
            return;
            }
        }

        usort($generated, fn (array $a, array $b): int => (int) $b['height'] <=> (int) $a['height']);

        $masterPath = $hlsBasePath . '/master.m3u8';
        $masterAbsolute = Storage::disk($disk)->path($masterPath);
        $masterLines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        foreach ($generated as $variant) {
            $masterLines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d',
                max(1, (int) $variant['bandwidth']),
                (int) round((16 / 9) * (int) $variant['height']),
                (int) $variant['height']
            );
            $masterLines[] = basename(dirname($variant['path'])) . '/index.m3u8';
        }

        @file_put_contents($masterAbsolute, implode("\n", $masterLines) . "\n");

        if (! is_file($masterAbsolute)) {
            $source->update([
                'playback_type' => 'mp4',
                'hls_master_path' => null,
                'qualities_json' => null,
                'optimize_error' => 'HLS master playlist generation failed.',
            ]);
            return;
        }

        $source->update([
            'playback_type' => 'hls',
            'hls_master_path' => $masterPath,
            'qualities_json' => $generated,
            'optimize_error' => null,
        ]);
    }

    /**
     * @param array<int, string> $rawProfiles
     * @return array<int, array{label:string,height:int,bitrate:int,audio_bitrate:string}>
     */
    private function resolveProfiles(array $rawProfiles): array
    {
        $map = [
            '1080' => ['label' => '1080p', 'height' => 1080, 'bitrate' => 5500000, 'audio_bitrate' => '192k'],
            '720' => ['label' => '720p', 'height' => 720, 'bitrate' => 2800000, 'audio_bitrate' => '128k'],
            '480' => ['label' => '480p', 'height' => 480, 'bitrate' => 1200000, 'audio_bitrate' => '96k'],
            '360' => ['label' => '360p', 'height' => 360, 'bitrate' => 800000, 'audio_bitrate' => '96k'],
            '240' => ['label' => '240p', 'height' => 240, 'bitrate' => 400000, 'audio_bitrate' => '64k'],
        ];

        $resolved = [];
        foreach ($rawProfiles as $profile) {
            $key = trim((string) $profile);
            if (isset($map[$key])) {
                $resolved[] = $map[$key];
            }
        }

        return $resolved;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runHlsTranscode(
        string $ffmpeg,
        string $inputAbsolute,
        string $playlistAbsolute,
        string $segmentPattern,
        int $height,
        string $audioBitrate,
        bool $hasAudio
    ): array {
        $parts = [
            escapeshellarg($ffmpeg),
            '-y',
            '-i',
            escapeshellarg($inputAbsolute),
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',
            '-vf',
            escapeshellarg("scale=-2:{$height}:force_original_aspect_ratio=decrease"),
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '22',
        ];

        if ($hasAudio) {
            $parts = array_merge($parts, [
                '-c:a',
                'aac',
                '-b:a',
                escapeshellarg($audioBitrate),
            ]);
        } else {
            $parts[] = '-an';
        }

        $parts = array_merge($parts, [
            '-f',
            'hls',
            '-hls_time',
            '6',
            '-hls_playlist_type',
            'vod',
            '-hls_flags',
            'independent_segments',
            '-hls_segment_filename',
            escapeshellarg($segmentPattern),
            escapeshellarg($playlistAbsolute),
            '2>&1',
        ]);

        $output = [];
        $exitCode = 0;
        @exec(implode(' ', $parts), $output, $exitCode);

        return [$exitCode, trim(implode("\n", $output))];
    }

    private function probeSourceHeight(string $ffprobe, string $inputAbsolute): ?int
    {
        if (! is_file($ffprobe)) {
            return null;
        }

        $cmd = implode(' ', [
            escapeshellarg($ffprobe),
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=height',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            escapeshellarg($inputAbsolute),
            '2>&1',
        ]);

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }

        $value = trim(implode("\n", $output));
        return is_numeric($value) ? (int) $value : null;
    }

    private function probeSourceHasAudio(string $ffprobe, string $inputAbsolute): bool
    {
        if (! is_file($ffprobe)) {
            return true;
        }

        $cmd = implode(' ', [
            escapeshellarg($ffprobe),
            '-v',
            'error',
            '-select_streams',
            'a',
            '-show_entries',
            'stream=index',
            '-of',
            'csv=p=0',
            escapeshellarg($inputAbsolute),
            '2>&1',
        ]);

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return true;
        }

        return trim(implode("\n", $output)) !== '';
    }
}

