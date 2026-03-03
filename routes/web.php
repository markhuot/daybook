<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LoginStoreController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\RegisterStoreController;
use App\Http\Controllers\NoteShowController;
use App\Http\Controllers\NoteUpdateController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', LoginController::class)->name('login');
    Route::post('/login', LoginStoreController::class);
    Route::get('/register', RegisterController::class)->name('register');
    Route::post('/register', RegisterStoreController::class);
});

Route::middleware('auth')->group(function () {
    Route::get('/', NoteShowController::class)->name('home');
    Route::get('/{date}', NoteShowController::class)->name('note.show')->where('date', '\d{4}-\d{2}-\d{2}');
    Route::put('/note', NoteUpdateController::class)->name('note.update');

    Route::post('/logout', LogoutController::class)->name('logout');
});
