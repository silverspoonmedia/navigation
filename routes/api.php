<?php

use App\Domain\Navigation\Http\Controllers\API\V1\MenuController;
use App\Domain\Navigation\Http\Controllers\API\V1\MenuLookupController;
use Illuminate\Support\Facades\Route;

Route::prefix('navigation/lookups')->name('navigation.lookups.')->group(function (): void {
    Route::middleware(['auth:api', 'permission:view menu'])
        ->get('menus', [MenuLookupController::class, 'menus'])
        ->name('menus');
});

Route::prefix('navigation/menus')->name('navigation.menus.')->group(function (): void {
    Route::middleware('jwt.optional')->get('/', [MenuController::class, 'index'])->name('index');
    Route::middleware('jwt.optional')->get('layouts', [MenuController::class, 'layouts'])->name('layouts');

    Route::middleware('auth:api')->group(function (): void {
        Route::middleware('permission:view menu')->group(function (): void {
            Route::get('list', [MenuController::class, 'list'])->name('list');
            Route::get('trashed', [MenuController::class, 'trashed'])->name('trashed');
            Route::get('{menu}', [MenuController::class, 'show'])->whereUuid('menu')->name('show');
        });

        Route::middleware('permission:manage menu')->group(function (): void {
            Route::post('/', [MenuController::class, 'store'])->name('store');
            Route::match(['put', 'patch'], '{menu}', [MenuController::class, 'update'])->whereUuid('menu')->name('update');
            Route::delete('{menu}', [MenuController::class, 'destroy'])->whereUuid('menu')->name('destroy');
        });

        Route::middleware('permission:restore menu')
            ->post('{menu}/restore', [MenuController::class, 'restore'])
            ->whereUuid('menu')
            ->withTrashed()
            ->name('restore');
    });
});
