<?php
use App\Http\Controllers\CaseController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CaseController::class, 'map'])->name('home');
Route::get('/report', [CaseController::class, 'create'])->name('cases.create');
Route::post('/report', [CaseController::class, 'store'])->name('cases.store');
Route::get('/cases/{id}', [CaseController::class, 'show'])->name('cases.show');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');
