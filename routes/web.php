<?php

use App\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('test', fn () => 'Hello World!')->name('test');

Route::get('download/export/{export}', [DownloadController::class, 'export'])->name('download.export');
Route::get('download/attachment/{attachment}', [DownloadController::class, 'attachment'])->name('download.attachment');
