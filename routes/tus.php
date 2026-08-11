<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Solid3d\LaravelTusS3\Http\Controllers\TusUploadController;
use Solid3d\LaravelTusS3\Http\Middleware\ValidateChecksumMiddleware;
use Solid3d\LaravelTusS3\Http\Middleware\ValidateFileSizeMiddleware;
use Solid3d\LaravelTusS3\Http\Middleware\ValidateVersionMiddleware;

Route::controller(TusUploadController::class)
    ->middleware([...(array) config('tus.middleware'), ValidateVersionMiddleware::class])
    ->prefix((string) config('tus.path'))
    ->name('tus.')
    ->group(function (): void {
        Route::match('options', '/', 'options')->name('options');

        Route::match('post', '/', 'post')->name('post')
            ->middleware([ValidateFileSizeMiddleware::class, ValidateChecksumMiddleware::class]);

        Route::match('head', '/{id}', 'head')->name('head')
            ->where('id', '[A-Za-z0-9_-]+');

        Route::match('patch', '/{id}', 'patch')->name('patch')
            ->where('id', '[A-Za-z0-9_-]+')
            ->middleware([ValidateFileSizeMiddleware::class, ValidateChecksumMiddleware::class]);

        Route::match('delete', '/{id}', 'delete')->name('delete')
            ->where('id', '[A-Za-z0-9_-]+');
    });
