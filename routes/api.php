<?php

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('customer', [Controller::class, 'activateUser'])->name('radius.customer.activate');
Route::put('customer/{username}', [Controller::class, 'updateUser'])->name('radius.customer.update');
Route::post('nas/sync', [Controller::class, 'syncNas'])->name('radius.nas.sync');
Route::post('radius/reload', [Controller::class, 'reloadRadius'])->name('radius.reload');
Route::post('radius/block', [Controller::class, 'blockUser'])->name('radius.block');
Route::post('radius/unblock', [Controller::class, 'unblockUser'])->name('radius.unblock');
Route::post('radius/disconnect', [Controller::class, 'disconnectUserSessions'])->name('radius.disconnect');
