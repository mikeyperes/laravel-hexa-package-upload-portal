<?php

use hexa_package_upload_portal\Upload\Core\Http\Controllers\UploadController;
use hexa_package_upload_portal\Upload\Settings\Http\Controllers\SettingsController;
use hexa_package_user_roles\Http\Middleware\EnsureAdminAccess;

Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])->group(function () {
    // Upload API — scoped by auth user
    Route::post('/upload-portal/upload', [UploadController::class, 'upload'])->name('upload-portal.upload');
    Route::get('/upload-portal/files', [UploadController::class, 'files'])->name('upload-portal.files');
    Route::delete('/upload-portal/delete/{id}', [UploadController::class, 'delete'])->name('upload-portal.delete');
    Route::post('/upload-portal/cleanup', [UploadController::class, 'cleanup'])->name('upload-portal.cleanup');

    Route::middleware(EnsureAdminAccess::class)->group(function () {
        // Settings — admin only
        Route::get('/upload-portal/settings', [SettingsController::class, 'index'])->name('upload-portal.settings');
        Route::post('/upload-portal/settings', [SettingsController::class, 'save'])->name('upload-portal.settings.save');

        // Raw test page — the generic API still requires a host-registered context.
        Route::get('/raw-upload-portal', [UploadController::class, 'raw'])->name('upload-portal.raw');
    });
});
