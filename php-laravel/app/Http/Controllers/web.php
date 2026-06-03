<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\Admin\AdminCaseController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminStatsController;
use App\Http\Controllers\Webhooks\AfricasTalkingController;
use App\Http\Controllers\Webhooks\USSDController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

// Landing / map
Route::get('/', [CaseController::class, 'map'])->name('home');
Route::get('/cases/{id}', [CaseController::class, 'show'])->name('cases.show');

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login',    [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',   [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register',[AuthController::class, 'register']);
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

/*
|--------------------------------------------------------------------------
| Authenticated — public users
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/report',  [CaseController::class, 'create'])->name('cases.create');
    Route::post('/report', [CaseController::class, 'store'])->name('cases.store');
    Route::get('/my-reports', [CaseController::class, 'myReports'])->name('cases.mine');
});

/*
|--------------------------------------------------------------------------
| Officer / Admin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:officer,admin,superadmin'])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        Route::get('/', [AdminCaseController::class, 'index'])->name('index');
        Route::get('/cases', [AdminCaseController::class, 'index'])->name('cases.index');
        Route::get('/cases/{id}', [AdminCaseController::class, 'show'])->name('cases.show');
        Route::patch('/cases/{id}/status', [AdminCaseController::class, 'updateStatus'])->name('cases.status');
        Route::post('/cases/{id}/broadcast', [AdminCaseController::class, 'broadcast'])->name('cases.broadcast');
    });

Route::middleware(['auth', 'role:admin,superadmin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)->only(['index','create','store','edit','update']);
        Route::get('stats', [AdminStatsController::class, 'index'])->name('stats');
    });

/*
|--------------------------------------------------------------------------
| Webhooks (no CSRF, use HMAC verification middleware instead)
|--------------------------------------------------------------------------
*/
Route::prefix('webhooks')->group(function () {
    // Africa's Talking
    Route::post('/at/sms',      [AfricasTalkingController::class, 'inboundSMS']);
    Route::post('/at/delivery', [AfricasTalkingController::class, 'deliveryReceipt']);
    Route::post('/at/ussd',     [USSDController::class, 'handle']);
});