<?php

namespace hexa_package_upload_portal\Providers;

use Illuminate\Support\ServiceProvider;

class UploadPortalServiceProvider extends ServiceProvider
{
    /**
     * Register package config.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/upload-portal.php', 'upload-portal');
    }

    /**
     * Bootstrap routes, views, migrations, settings card.
     *
     * @return void
     */
    public function boot(): void
    {
        if (!config('upload-portal.enabled', true)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../../routes/upload-portal.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'upload-portal');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if (class_exists(\hexa_core\Services\PackageRegistryService::class)) {
            $registry = app(\hexa_core\Services\PackageRegistryService::class);
            $registry->registerSidebarLink('upload-portal.settings', 'Settings', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.11 2.37-2.37.996.608 2.296.07 2.572-1.065z', 'Upload Portal', 'upload-portal', 65);
            if (method_exists($registry, 'registerPackage')) {
                $registry->registerPackage('upload-portal', 'hexawebsystems/laravel-hexa-package-upload-portal', [
                'title' => 'Upload Portal',
                'color' => 'teal',
                'icon' => 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12',
                'description' => 'Reusable upload component package for multi-file uploads, temp storage, and galleries.',
                'settingsRoute' => 'upload-portal.settings',
                'docsSlug' => 'upload-portal',
                'instructions' => [
                    'Embed the upload component where file collection is needed.',
                    'Use the package API for cleanup and file lifecycle operations.',
                ],
                ]);
            }
        }

        // Component available via @include('upload-portal::components.upload-portal', [...])

        // Legacy settings-card push removed — core renders package cards from registry

        // Docs registration
        if (class_exists(\hexa_core\Services\DocumentationService::class)) {
            try {
                app(\hexa_core\Services\DocumentationService::class)->register('upload-portal', 'Upload Portal', 'hexawebsystems/laravel-hexa-package-upload-portal', [
                    ['title' => 'Overview', 'content' => 'Multi-file upload with progress bars, temp storage, gallery viewing, and cleanup API.'],
                    ['title' => 'Component', 'content' => '<code>@include(\'upload-portal::components.upload-portal\', [\'context\' => \'article\', \'contextId\' => $id, \'multi\' => true])</code>'],
                    ['title' => 'Public API', 'content' => '<code>UploadService::upload()</code>, <code>getFiles()</code>, <code>delete()</code>, <code>cleanup()</code>, <code>getTempPath()</code>'],
                ]);
            } catch (\Throwable $e) {}
        }
    }
}
