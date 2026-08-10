<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\Admin\AdminCaseController;
use Illuminate\Support\Facades\Route;

// ── Public routes — no login required ────────────────────────────────────────

// Map (homepage)
Route::get('/', [CaseController::class, 'map'])->name('home');

// Public case detail
Route::get('/cases/{id}', [CaseController::class, 'show'])->name('cases.show');

// Report form — PUBLIC, no auth required
// Cases go to "review" status until an officer approves them
Route::get('/report',  [CaseController::class, 'create'])->name('cases.create');
Route::post('/report', [CaseController::class, 'store'])->name('cases.store');

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login',    [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',   [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register',[AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->name('logout')
    ->middleware('auth');

// ── Officer / Admin dashboard ─────────────────────────────────────────────────
Route::middleware(['auth'])->prefix('dashboard')->name('dashboard.')->group(function () {
    Route::get('/',                            [AdminCaseController::class, 'index'])->name('index');
    Route::get('/cases',                       [AdminCaseController::class, 'index'])->name('cases.index');
    Route::get('/cases/{id}',                  [AdminCaseController::class, 'show'])->name('cases.show');
    Route::patch('/cases/{id}/status',         [AdminCaseController::class, 'updateStatus'])->name('cases.status');
    Route::post('/cases/{id}/broadcast',       [AdminCaseController::class, 'broadcast'])->name('cases.broadcast');
});

// ── Webhooks — Africa's Talking ───────────────────────────────────────────────
Route::prefix('webhooks')->group(function () {
    Route::post('/at/sms',      [\App\Http\Controllers\Webhooks\AfricasTalkingController::class, 'inboundSMS']);
    Route::post('/at/delivery', [\App\Http\Controllers\Webhooks\AfricasTalkingController::class, 'deliveryReceipt']);
    Route::post('/at/ussd',     [\App\Http\Controllers\Webhooks\USSDController::class, 'handle']);
});