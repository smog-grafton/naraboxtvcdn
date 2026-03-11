<?php

use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('cdn.token')
    ->group(function (): void {
        Route::post('/media/import', [MediaController::class, 'import'])
            ->middleware('throttle:20,1');
        Route::post('/media/upload', [MediaController::class, 'upload'])
            ->middleware('throttle:10,1');
        Route::post('/media/telegram-intake', [MediaController::class, 'telegramIntake'])
            ->middleware('throttle:10,1');
        Route::get('/media/{assetId}', [MediaController::class, 'showAsset'])
            ->whereUuid('assetId');
        Route::get('/media/{assetId}/playback', [MediaController::class, 'playback'])
            ->whereUuid('assetId');
        Route::get('/media/sources/lookup', [MediaController::class, 'lookupSource']);
        Route::get('/media/sources/{sourceId}', [MediaController::class, 'showSource'])
            ->whereNumber('sourceId');
        Route::delete('/media/sources/{sourceId}', [MediaController::class, 'destroySource'])
            ->whereNumber('sourceId');
        Route::post('/media/worker/callback', [MediaController::class, 'workerCallback']);
        Route::post('/media/worker/upload', [MediaController::class, 'workerUpload']);
    });

Route::post('/ingest/asset-source-upload', [IngestController::class, 'assetSourceUpload'])
    ->middleware('throttle:20,1');

