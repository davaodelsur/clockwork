<?php

use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\SignerController;
use App\Http\Controllers\Api\TimesheetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());

Route::match(['get', 'post'], 'timesheet', TimesheetController::class)->middleware(['auth:sanctum']);

Route::match(['get', 'post'], 'holiday', HolidayController::class)->middleware(['auth:sanctum']);

Route::match(['get', 'post'], 'signer', SignerController::class)->middleware(['auth:sanctum']);
