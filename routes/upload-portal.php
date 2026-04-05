<?php

use hexa_package_upload_portal\Upload\Core\Http\Controllers\UploadController;
use hexa_package_upload_portal\Upload\Settings\Http\Controllers\SettingsController;

Route::middleware(['web', 'auth'])->group(function () {
    // Upload API
    Route::post('/upload-portal/upload', [UploadController::class, 'upload'])->name('upload-portal.upload');
    Route::get('/upload-portal/files', [UploadController::class, 'files'])->name('upload-portal.files');
    Route::delete('/upload-portal/delete/{id}', [UploadController::class, 'delete'])->name('upload-portal.delete');
    Route::post('/upload-portal/cleanup', [UploadController::class, 'cleanup'])->name('upload-portal.cleanup');

    // Settings
    Route::get('/upload-portal/settings', [SettingsController::class, 'index'])->name('upload-portal.settings');
    Route::post('/upload-portal/settings', [SettingsController::class, 'save'])->name('upload-portal.settings.save');

    // Raw test page
    Route::get('/raw-upload-portal', function () {
        return view('upload-portal::raw.index');
    })->name('upload-portal.raw');
});
