<?php

use App\Http\Controllers\Auth\AuthCheckController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LoginStoreController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\RegisterStoreController;
use App\Http\Controllers\NoteShowController;
use App\Http\Controllers\NoteStepsController;
use App\Http\Controllers\NoteWindowLeftController;
use App\Http\Controllers\NoteWindowRightController;
use App\Http\Controllers\PromptController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/check', AuthCheckController::class);

Route::middleware('guest')->group(function () {
    Route::get('/login', LoginController::class)->name('login');
    Route::post('/login', LoginStoreController::class);
    Route::get('/register', RegisterController::class)->name('register');
    Route::post('/register', RegisterStoreController::class);
});

Route::middleware('auth')->group(function () {
    Route::get('/', NoteShowController::class)->name('home');
    Route::get('/{date}', NoteShowController::class)->name('note.show')->where('date', '\d{4}-\d{2}-\d{2}');
    Route::get('/note/window/left', NoteWindowLeftController::class)->name('note.window.left');
    Route::get('/note/window/right', NoteWindowRightController::class)->name('note.window.right');
    Route::post('/note/steps', [NoteStepsController::class, 'store'])->name('note.steps.store');
    Route::get('/note/steps', [NoteStepsController::class, 'index'])->name('note.steps.index');
    Route::post('/prompt', PromptController::class);

    Route::post('/logout', LogoutController::class)->name('logout');
});
