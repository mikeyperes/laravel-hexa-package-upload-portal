<?php

namespace HexaPackageSmokeTests\LaravelHexaPackageUploadPortal;

use hexa_package_upload_portal\Upload\Core\Authorization\UploadContextRegistry;
use hexa_package_upload_portal\Upload\Core\Exceptions\UploadPolicyViolation;
use hexa_package_upload_portal\Upload\Core\Http\Controllers\UploadController;
use hexa_package_upload_portal\Upload\Core\Services\UploadService;
use hexa_package_upload_portal\Upload\Media\Models\UploadedFile as UploadedFileRecord;
use hexa_package_upload_portal\Upload\Storage\Services\StorageService;
use hexa_package_user_roles\Http\Middleware\EnsureAdminAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UploadPortalHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('upload-portal-test');
        config([
            'upload-portal.disk' => 'upload-portal-test',
            'upload-portal.temp_dir' => 'uploads/temp',
            'upload-portal.upload_dir' => 'uploads',
            'upload-portal.allowed_types' => ['jpg', 'jpeg', 'png'],
            'upload-portal.allowed_mime_types' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
            ],
            'upload-portal.max_file_size' => 10240,
            'upload-portal.max_active_files' => 100,
            'upload-portal.max_aggregate_size' => 102400,
        ]);

        Schema::dropIfExists('uploaded_files');
        Schema::create('uploaded_files', function (Blueprint $table): void {
            $table->id();
            $table->string('filename');
            $table->string('original_name');
            $table->string('path');
            $table->string('disk');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type')->nullable();
            $table->string('context', 50)->index();
            $table->unsignedBigInteger('context_id')->index();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('status')->default('temp');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $this->app->forgetInstance(UploadContextRegistry::class);
    }

    public function test_http_endpoints_fail_closed_for_unknown_and_denied_contexts(): void
    {
        $registry = app(UploadContextRegistry::class);
        $controller = $this->controller($registry);

        $unknown = $controller->upload($this->uploadRequest('unknown-context', 9));
        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame(0, UploadedFileRecord::count());

        $registry->register('denied-context', $this->policy(authorize: false));
        $denied = $controller->upload($this->uploadRequest('denied-context', 9));
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(0, UploadedFileRecord::count());
    }

    public function test_list_delete_and_cleanup_fail_closed_for_unauthorized_context_id(): void
    {
        $registry = app(UploadContextRegistry::class);
        $registry->register('bounded-context', [
            ...$this->policy(),
            'authorize' => static fn (string $action, int $contextId): bool => $contextId === 7
                && in_array($action, ['upload', 'list', 'delete', 'cleanup'], true),
        ]);
        $controller = $this->controller($registry);
        $file = $this->record([
            'context' => 'bounded-context',
            'context_id' => 8,
            'uploaded_by' => null,
        ]);

        $list = $controller->files(Request::create('/upload-portal/files', 'GET', [
            'context' => 'bounded-context',
            'context_id' => 8,
        ]));
        $cleanup = $controller->cleanup(Request::create('/upload-portal/cleanup', 'POST', [
            'context' => 'bounded-context',
            'context_id' => 8,
        ]));
        $delete = $controller->delete(Request::create('/upload-portal/delete/'.$file->id, 'DELETE'), (int) $file->id);

        $this->assertSame(403, $list->getStatusCode());
        $this->assertSame(403, $cleanup->getStatusCode());
        $this->assertSame(403, $delete->getStatusCode());
        $this->assertSame('temp', $file->fresh()->status);
    }

    public function test_count_and_aggregate_quotas_apply_across_repeated_requests(): void
    {
        $registry = app(UploadContextRegistry::class);
        $registry->register('counted', $this->policy(maxActiveFiles: 2));
        $service = $this->service($registry);

        $service->upload($this->image('one.jpg'), 'counted', 12, 44);
        $service->upload($this->image('two.jpg'), 'counted', 12, 44);

        $this->expectException(UploadPolicyViolation::class);
        try {
            $service->upload($this->image('three.jpg'), 'counted', 12, 44);
        } finally {
            $this->assertSame(2, UploadedFileRecord::where('context', 'counted')->count());
        }
    }

    public function test_aggregate_quota_is_checked_against_existing_active_bytes(): void
    {
        $first = $this->image('one.jpg');
        $second = $this->image('two.jpg');
        $limit = (int) $first->getSize() + (int) $second->getSize() - 1;
        $registry = app(UploadContextRegistry::class);
        $registry->register('aggregate', $this->policy(maxFileBytes: $limit, maxTotalBytes: $limit));
        $service = $this->service($registry);

        $service->upload($first, 'aggregate', 13, 44);

        try {
            $service->upload($second, 'aggregate', 13, 44);
            $this->fail('The aggregate quota should reject the second request.');
        } catch (UploadPolicyViolation $exception) {
            $this->assertStringContainsString('total upload size', $exception->getMessage());
        }

        $this->assertSame(1, UploadedFileRecord::where('context', 'aggregate')->count());
    }

    public function test_batch_database_failure_rolls_back_rows_and_new_storage_objects(): void
    {
        $registry = app(UploadContextRegistry::class);
        $registry->register('atomic', $this->policy());
        $service = $this->service($registry);
        $creating = 0;

        UploadedFileRecord::creating(function () use (&$creating): void {
            if (++$creating === 2) {
                throw new RuntimeException('simulated database failure');
            }
        });

        try {
            $service->uploadBatch(
                [$this->image('one.jpg'), $this->image('two.jpg')],
                'atomic',
                21,
                55
            );
            $this->fail('The simulated database failure should abort the batch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Upload could not be completed.', $exception->getMessage());
        } finally {
            UploadedFileRecord::flushEventListeners();
        }

        $this->assertSame(0, UploadedFileRecord::count());
        $this->assertSame([], Storage::disk('upload-portal-test')->allFiles());
    }

    public function test_storage_failure_creates_no_database_row(): void
    {
        $registry = app(UploadContextRegistry::class);
        $registry->register('storage-failure', $this->policy());
        $storage = Mockery::mock(StorageService::class);
        $storage->shouldReceive('getTempDir')->once()->andReturn('uploads/temp');
        $storage->shouldReceive('getDisk')->once()->andReturn('upload-portal-test');
        $storage->shouldReceive('storeUploadedFile')->once()->andThrow(new RuntimeException('private detail'));
        $service = new UploadService($storage, $registry);

        try {
            $service->upload($this->image('one.jpg'), 'storage-failure', 22, 55);
            $this->fail('The storage failure should abort the upload.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Upload could not be completed.', $exception->getMessage());
        }

        $this->assertSame(0, UploadedFileRecord::count());
    }

    public function test_delete_failure_preserves_active_status(): void
    {
        $file = $this->record(['context' => 'delete-failure', 'context_id' => 30, 'uploaded_by' => 77]);
        $storage = Mockery::mock(StorageService::class);
        $storage->shouldReceive('deleteFile')->once()->andReturnFalse();
        $service = new UploadService($storage, app(UploadContextRegistry::class));

        try {
            $service->delete((int) $file->id, 77);
            $this->fail('A failed physical deletion must not update the record.');
        } catch (RuntimeException $exception) {
            $this->assertSame('File storage deletion failed.', $exception->getMessage());
        }

        $this->assertSame('temp', $file->fresh()->status);
    }

    public function test_listing_missing_files_performs_no_database_writes(): void
    {
        $file = $this->record(['path' => 'uploads/temp/missing.jpg', 'updated_at' => now()->subDay()]);
        $updatedAt = $file->updated_at->toJSON();

        $files = $this->service(app(UploadContextRegistry::class))->getFiles(
            (string) $file->context,
            (int) $file->context_id,
            (int) $file->uploaded_by
        );

        $this->assertCount(0, $files);
        $this->assertSame('temp', $file->fresh()->status);
        $this->assertSame($updatedAt, $file->fresh()->updated_at->toJSON());
    }

    public function test_null_owner_keeps_direct_admin_read_and_delete_compatibility(): void
    {
        $first = $this->record(['path' => 'uploads/temp/owner-seven.jpg', 'uploaded_by' => 7]);
        $second = $this->record(['path' => 'uploads/temp/owner-eight.jpg', 'uploaded_by' => 8]);
        Storage::disk('upload-portal-test')->put($first->path, 'image');
        Storage::disk('upload-portal-test')->put($second->path, 'image');
        $service = $this->service(app(UploadContextRegistry::class));

        $this->assertCount(2, $service->getFiles('test-context', 1));
        $this->assertTrue($service->delete((int) $second->id));
        $this->assertSame('deleted', $second->fresh()->status);
    }

    public function test_cleanup_batch_is_limited_and_returns_a_forward_cursor(): void
    {
        $records = collect([
            $this->record(['filename' => 'one.jpg', 'path' => 'uploads/temp/one.jpg']),
            $this->record(['filename' => 'two.jpg', 'path' => 'uploads/temp/two.jpg']),
            $this->record(['filename' => 'three.jpg', 'path' => 'uploads/temp/three.jpg']),
        ]);
        foreach ($records as $record) {
            Storage::disk('upload-portal-test')->put($record->path, 'image');
        }

        $service = $this->service(app(UploadContextRegistry::class));
        $first = $service->cleanupBatch('test-context', 1, 7, 2);

        $this->assertSame(2, $first['cleaned']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame((int) $records[1]->id, $first['next_cursor']);
        $this->assertTrue($first['has_more']);
        $this->assertSame('temp', $records[2]->fresh()->status);

        $second = $service->cleanupBatch('test-context', 1, 7, 2, $first['next_cursor']);
        $this->assertSame(1, $second['cleaned']);
        $this->assertFalse($second['has_more']);
    }

    public function test_detected_mime_must_match_the_normalized_extension(): void
    {
        $registry = app(UploadContextRegistry::class);
        $registry->register('mime-check', $this->policy());
        $temporaryPath = tempnam(sys_get_temp_dir(), 'upload-portal-mime-');
        $this->assertNotFalse($temporaryPath);
        file_put_contents($temporaryPath, 'plain text');
        $file = new UploadedFile($temporaryPath, 'not-an-image.jpg', 'image/jpeg', null, true);

        $this->expectException(UploadPolicyViolation::class);
        try {
            $this->service($registry)->upload($file, 'mime-check', 40, 88);
        } finally {
            $this->assertSame(0, UploadedFileRecord::count());
            $this->assertSame([], Storage::disk('upload-portal-test')->allFiles());
            @unlink($temporaryPath);
        }
    }

    public function test_http_payload_omits_internal_path_and_disk(): void
    {
        $registry = app(UploadContextRegistry::class);
        $registry->register('safe-response', $this->policy());

        $response = $this->controller($registry)->upload($this->uploadRequest('safe-response', 50));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('path', $payload['uploaded'][0]);
        $this->assertArrayNotHasKey('disk', $payload['uploaded'][0]);
    }

    public function test_routes_have_full_security_middleware_and_admin_surfaces_are_stricter(): void
    {
        $baseMiddleware = ['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'];

        foreach (['upload-portal.upload', 'upload-portal.files', 'upload-portal.delete', 'upload-portal.cleanup'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            foreach ($baseMiddleware as $middleware) {
                $this->assertContains($middleware, $route->gatherMiddleware(), $name);
            }
        }

        foreach (['upload-portal.settings', 'upload-portal.settings.save', 'upload-portal.raw'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            foreach ($baseMiddleware as $middleware) {
                $this->assertContains($middleware, $route->gatherMiddleware(), $name);
            }
            $this->assertContains(EnsureAdminAccess::class, $route->gatherMiddleware(), $name);
        }
    }

    public function test_registry_is_singleton_and_version_metadata_is_2_0_9(): void
    {
        $this->assertSame(app(UploadContextRegistry::class), app(UploadContextRegistry::class));
        $this->assertSame('2.0.9', config('upload-portal.version'));

        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('2.0.9', $composer['version']);
        $this->assertArrayHasKey('hexawebsystems/laravel-hexa-package-user-roles', $composer['require']);
    }

    public function test_registry_rejects_non_exact_contexts_and_unknown_actions(): void
    {
        $registry = app(UploadContextRegistry::class);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $registry->register(' padded-context ', $this->policy());
        } finally {
            $registry->register('exact-context', $this->policy());

            try {
                $registry->authorize('exact-context', 'unknown-action', 1, 1);
                $this->fail('Unknown upload actions must fail closed.');
            } catch (UploadPolicyViolation $exception) {
                $this->assertSame(403, $exception->status());
            }
        }
    }

    public function test_quota_lock_key_is_a_hash_of_context_id_and_owner(): void
    {
        $service = $this->service(app(UploadContextRegistry::class));
        $method = new \ReflectionMethod($service, 'lockKey');
        $key = $method->invoke($service, 'private-context', 123, 456);

        $this->assertMatchesRegularExpression('/\Aupload-portal:quota:[a-f0-9]{64}\z/D', $key);
        $this->assertStringNotContainsString('private-context', $key);
        $this->assertNotSame($key, $method->invoke($service, 'private-context', 123, 457));
    }

    public function test_forward_migration_adds_and_removes_the_quota_lookup_index(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_30_000002_add_upload_quota_lookup_index.php';

        $migration->up();
        $this->assertTrue(Schema::hasIndex('uploaded_files', 'uploaded_files_context_owner_status_index'));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('uploaded_files', 'uploaded_files_context_owner_status_index'));
    }

    private function controller(UploadContextRegistry $registry): UploadController
    {
        $storage = new StorageService;

        return new UploadController(new UploadService($storage, $registry), $storage, $registry);
    }

    private function service(UploadContextRegistry $registry): UploadService
    {
        return new UploadService(new StorageService, $registry);
    }

    /** @return array<string, mixed> */
    private function policy(
        bool $authorize = true,
        int $maxActiveFiles = 10,
        int $maxFileBytes = 1048576,
        int $maxTotalBytes = 10485760
    ): array {
        return [
            'authorize' => static fn (): bool => $authorize,
            'max_active_files' => $maxActiveFiles,
            'max_file_bytes' => $maxFileBytes,
            'max_total_bytes' => $maxTotalBytes,
            'allowed_types' => [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
            ],
        ];
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 12, 12);
    }

    private function uploadRequest(string $context, int $contextId): Request
    {
        return Request::create(
            '/upload-portal/upload',
            'POST',
            [
                'context' => $context,
                'context_id' => $contextId,
                'temp' => true,
            ],
            [],
            ['files' => [$this->image('request.jpg')]]
        );
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = []): UploadedFileRecord
    {
        return UploadedFileRecord::create(array_merge([
            'filename' => 'file.jpg',
            'original_name' => 'file.jpg',
            'path' => 'uploads/temp/file.jpg',
            'disk' => 'upload-portal-test',
            'size' => 100,
            'mime_type' => 'image/jpeg',
            'context' => 'test-context',
            'context_id' => 1,
            'uploaded_by' => 7,
            'status' => 'temp',
            'metadata' => [],
        ], $overrides));
    }
}
