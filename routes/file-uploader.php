<?php

use Illuminate\Support\Facades\Route;
use Xakki\LaravelFileUploader\Http\Controllers\FileController;
use Xakki\LaravelFileUploader\Http\Controllers\UploadController;

Route::post('/chunks', [UploadController::class, 'store'])->name('chunks.store');
Route::get('/files', [FileController::class, 'index'])->name('files.index');
Route::delete('/files/{id}', [FileController::class, 'destroy'])->name('files.destroy');
Route::post('/files/{id}/restore', [FileController::class, 'restore'])->name('files.restore');
Route::delete('/trash/cleanup', [FileController::class, 'cleanup'])->name('trash.cleanup');
