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
            app(\hexa_core\Services\PackageRegistryService::class)->registerPackage('upload-portal', 'hexawebsystems/laravel-hexa-package-upload-portal', [
                'title' => 'Upload Portal',
                'description' => 'Reusable upload component package for multi-file uploads, temp storage, and galleries.',
                'docsSlug' => 'upload-portal',
                'instructions' => [
                    'Embed the upload component where file collection is needed.',
                    'Use the package API for cleanup and file lifecycle operations.',
                ],
            ]);
        }

        // Component available via @include('upload-portal::components.upload-portal', [...])

        // Settings card on /settings page
        view()->composer('settings.index', function ($view) {
            $view->getFactory()->startPush('settings-cards',
                view('upload-portal::partials.settings-card')->render());
        });

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
