<?php

return [
    'app_url' => env('CDN_APP_URL', env('APP_URL')),

    'disk' => env('FILESYSTEM_DISK', 'public'),

    'default_import_mode' => in_array(env('CDN_DEFAULT_IMPORT_MODE', 'queue'), ['now', 'queue'], true)
        ? env('CDN_DEFAULT_IMPORT_MODE', 'queue')
        : 'queue',

    'ingest_secret' => (string) env('CDN_INGEST_SECRET', ''),

    'portal_fetch_proxy_url' => (string) env('PORTAL_FETCH_PROXY_URL', ''),
    'portal_fetch_proxy_token' => (string) env('PORTAL_FETCH_PROXY_TOKEN', ''),
    'python_worker_enabled' => (bool) env('CDN_PYTHON_WORKER_ENABLED', false),
    'python_worker_queue_url' => (string) env('CDN_PYTHON_WORKER_QUEUE_URL', ''),
    'python_worker_auth_token' => (string) env('CDN_PYTHON_WORKER_AUTH_TOKEN', ''),
    'laravel_worker_enabled' => (bool) env('CDN_LARAVEL_WORKER_ENABLED', false),
    'laravel_worker_pull_enabled' => (bool) env('CDN_LARAVEL_WORKER_PULL_ENABLED', true),
    'laravel_worker_api_url' => rtrim((string) env('CDN_LARAVEL_WORKER_API_URL', ''), '/'),
    'laravel_worker_api_token' => (string) env('CDN_LARAVEL_WORKER_API_TOKEN', ''),
    'laravel_worker_artifact_fetch_timeout' => (int) env('CDN_WORKER_ARTIFACT_FETCH_TIMEOUT', 600),
    'laravel_worker_artifact_retry_times' => (int) env('CDN_WORKER_ARTIFACT_RETRY_TIMES', 3),
    'laravel_worker_artifact_retry_sleep_ms' => (int) env('CDN_WORKER_ARTIFACT_RETRY_SLEEP_MS', 2000),
    'worker_artifacts_temp_disk' => env('CDN_WORKER_ARTIFACTS_TEMP_DISK', 'local'),
    'worker_artifacts_temp_path' => env('CDN_WORKER_ARTIFACTS_TEMP_PATH', 'worker-artifacts'),
    'hls_artifacts_queue' => env('CDN_HLS_ARTIFACTS_QUEUE', 'optimization'),
    'ffmpeg_binary' => (string) env('CDN_FFMPEG_BINARY', base_path('ffmpeg/ffmpeg')),
    'ffprobe_binary' => (string) env('CDN_FFPROBE_BINARY', base_path('ffmpeg/ffprobe')),
    'enable_hls' => (bool) env('CDN_ENABLE_HLS', true),
    'hls_profiles' => array_values(array_filter(array_map('trim', explode(',', (string) env('CDN_HLS_PROFILES', '1080,720,480'))))),

    'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 2048),

    'allowed_video_extensions' => array_values(array_filter(array_map(
        static fn (string $item): string => strtolower(trim($item)),
        explode(',', (string) env('ALLOWED_VIDEO_EXTENSIONS', 'mp4,mkv,webm,avi,mov,m4v'))
    ))),
];

