<?php

namespace hexa_package_upload_portal\Upload\Core\Services;

use hexa_package_upload_portal\Upload\Core\Authorization\UploadContextRegistry;
use hexa_package_upload_portal\Upload\Core\Exceptions\UploadPolicyViolation;
use hexa_package_upload_portal\Upload\Core\Policies\UploadContextPolicy;
use hexa_package_upload_portal\Upload\Media\Models\UploadedFile;
use hexa_package_upload_portal\Upload\Storage\Services\StorageService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UploadService
{
    private const ACTIVE_STATUSES = ['temp', 'permanent'];

    private const CLEANUP_BATCH_LIMIT = 100;

    private const MAX_CLEANUP_BATCH_LIMIT = 500;

    public function __construct(
        private readonly StorageService $storage,
        private readonly UploadContextRegistry $contexts
    ) {}

    /**
     * Backward-compatible single-file API. Direct callers may continue using
     * unregistered contexts; HTTP callers are authorized by the controller.
     */
    public function upload(
        LaravelUploadedFile $file,
        string $context,
        int $contextId,
        ?int $userId = null,
        bool $temp = true
    ): UploadedFile {
        return $this->uploadBatch([$file], $context, $contextId, $userId, $temp)->firstOrFail();
    }

    /**
     * Atomically store a complete upload batch and its database records.
     *
     * @param  list<LaravelUploadedFile>  $files
     * @return Collection<int, UploadedFile>
     */
    public function uploadBatch(
        array $files,
        string $context,
        int $contextId,
        ?int $userId = null,
        bool $temp = true,
        ?UploadContextPolicy $policy = null
    ): Collection {
        if ($files === []) {
            throw new UploadPolicyViolation('At least one upload file is required.');
        }

        foreach ($files as $file) {
            if (! $file instanceof LaravelUploadedFile || ! $file->isValid()) {
                throw new UploadPolicyViolation('One or more upload files are invalid.');
            }
        }

        if ($contextId < 0) {
            throw new UploadPolicyViolation('Upload context access is denied.', 403);
        }

        $ownerId = $userId ?? auth()->id();
        $policy ??= $this->servicePolicy($context);
        $lock = Cache::lock(
            $this->lockKey($context, $contextId, $ownerId),
            max(30, (int) config('upload-portal.lock_seconds', 300))
        );

        try {
            return $lock->block(max(1, (int) config('upload-portal.lock_wait_seconds', 10)), fn (): Collection => $this->storeBatchWithinLock(
                $files,
                $context,
                $contextId,
                $ownerId,
                $temp,
                $policy
            ));
        } catch (LockTimeoutException) {
            throw new UploadPolicyViolation('Another upload is already being processed. Please retry.', 409);
        }
    }

    /** @return Collection<int, UploadedFile> */
    public function getFiles(string $context, int $contextId, ?int $userId = null): Collection
    {
        $query = UploadedFile::query()
            ->where('context', $context)
            ->where('context_id', $contextId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->where('path', '!=', '0');

        $this->scopeOwner($query, $userId);

        return $query->orderBy('created_at')->get()
            ->filter(fn (UploadedFile $file): bool => $this->storage->fileExists(
                (string) $file->path,
                $file->disk ?: null
            ))
            ->values();
    }

    public function findFile(int $fileId, ?int $userId = null): ?UploadedFile
    {
        $query = UploadedFile::query()
            ->whereKey($fileId)
            ->whereIn('status', self::ACTIVE_STATUSES);

        $this->scopeOwner($query, $userId);

        return $query->first();
    }

    /**
     * Delete a file only when its physical object is successfully removed.
     */
    public function delete(int $fileId, ?int $userId = null): bool
    {
        $candidate = $this->findFile($fileId, $userId);
        if (! $candidate) {
            return false;
        }

        $lock = Cache::lock(
            $this->lockKey(
                (string) $candidate->context,
                (int) $candidate->context_id,
                $candidate->uploaded_by === null ? null : (int) $candidate->uploaded_by
            ),
            max(30, (int) config('upload-portal.lock_seconds', 300))
        );

        try {
            return $lock->block(max(1, (int) config('upload-portal.lock_wait_seconds', 10)), function () use ($fileId, $userId): bool {
                $file = $this->findFile($fileId, $userId);
                if (! $file) {
                    return false;
                }

                if (! $this->storage->deleteFile((string) $file->path, $file->disk ?: null)) {
                    throw new RuntimeException('File storage deletion failed.');
                }

                try {
                    $file->update(['status' => 'deleted']);
                } catch (Throwable $exception) {
                    throw new RuntimeException('File record could not be updated.', 0, $exception);
                }

                $this->log('file_deleted', "Deleted {$file->filename}", [
                    'file_id' => $file->id,
                    'context' => $file->context,
                    'context_id' => $file->context_id,
                ]);

                return true;
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('File deletion is temporarily unavailable.');
        }
    }

    /**
     * Process at most one bounded page of temporary files.
     *
     * @return array{cleaned: int, failed: int, next_cursor: int, has_more: bool}
     */
    public function cleanupBatch(
        string $context,
        int $contextId,
        ?int $userId = null,
        int $limit = self::CLEANUP_BATCH_LIMIT,
        int $afterId = 0
    ): array {
        $limit = max(1, min($limit, self::MAX_CLEANUP_BATCH_LIMIT));
        $query = UploadedFile::query()
            ->where('context', $context)
            ->where('context_id', $contextId)
            ->where('status', 'temp')
            ->where('id', '>', max(0, $afterId));

        $this->scopeOwner($query, $userId);

        $files = $query->orderBy('id')->limit($limit)->get();
        $cleaned = 0;
        $failed = 0;
        $cursor = $afterId;

        foreach ($files as $file) {
            $cursor = (int) $file->id;

            try {
                $cleaned += $this->delete((int) $file->id, $userId) ? 1 : 0;
            } catch (RuntimeException) {
                $failed++;
            }
        }

        $remaining = UploadedFile::query()
            ->where('context', $context)
            ->where('context_id', $contextId)
            ->where('status', 'temp')
            ->where('id', '>', max(0, $cursor));
        $this->scopeOwner($remaining, $userId);

        if ($cleaned > 0) {
            $this->log('cleanup', "Cleaned up {$cleaned} temp file(s) for {$context}#{$contextId}");
        }

        return [
            'cleaned' => $cleaned,
            'failed' => $failed,
            'next_cursor' => max(0, $cursor),
            'has_more' => $remaining->exists(),
        ];
    }

    /**
     * Backward-compatible cleanup API with bounded database materialization.
     */
    public function cleanup(string $context, int $contextId, ?int $userId = null): int
    {
        $cleaned = 0;
        $cursor = 0;

        do {
            $batch = $this->cleanupBatch($context, $contextId, $userId, self::CLEANUP_BATCH_LIMIT, $cursor);
            $cleaned += $batch['cleaned'];

            if ($batch['next_cursor'] <= $cursor) {
                break;
            }

            $cursor = $batch['next_cursor'];
        } while ($batch['has_more']);

        return $cleaned;
    }

    public function getTempPath(): string
    {
        $disk = $this->storage->getDisk();
        $prefix = $disk === 'public' ? 'app/public/' : 'app/';

        return storage_path($prefix.$this->storage->getTempDir());
    }

    /**
     * @param  list<LaravelUploadedFile>  $files
     * @return Collection<int, UploadedFile>
     */
    private function storeBatchWithinLock(
        array $files,
        string $context,
        int $contextId,
        ?int $ownerId,
        bool $temp,
        UploadContextPolicy $policy
    ): Collection {
        $batchBytes = 0;

        foreach ($files as $file) {
            $bytes = $file->getSize();
            $extension = strtolower(ltrim((string) $file->getClientOriginalExtension(), '.'));
            $mimeType = strtolower((string) $file->getMimeType());

            if (! is_int($bytes) || $bytes < 0 || $bytes > $policy->maxFileBytes()) {
                throw new UploadPolicyViolation('One or more files exceed the allowed size.');
            }

            if (! $policy->allows($extension, $mimeType)) {
                throw new UploadPolicyViolation('One or more files have an extension or detected MIME type that is not allowed.');
            }

            $batchBytes += $bytes;
        }

        $active = UploadedFile::query()
            ->where('context', $context)
            ->where('context_id', $contextId)
            ->whereIn('status', self::ACTIVE_STATUSES);
        $this->scopeOwner($active, $ownerId, true);

        $activeCount = (clone $active)->count();
        $activeBytes = (int) (clone $active)->sum('size');

        if ($activeCount + count($files) > $policy->maxActiveFiles()) {
            throw new UploadPolicyViolation('The active file limit for this upload context has been reached.');
        }

        if ($activeBytes + $batchBytes > $policy->maxTotalBytes()) {
            throw new UploadPolicyViolation('The total upload size limit for this context has been reached.');
        }

        $storedFiles = [];

        try {
            return DB::transaction(function () use (
                $files,
                $context,
                $contextId,
                $ownerId,
                $temp,
                &$storedFiles
            ): Collection {
                $records = collect();
                $dir = $temp ? $this->storage->getTempDir() : $this->storage->getUploadDir();
                $disk = $this->storage->getDisk();

                foreach ($files as $file) {
                    $extension = strtolower(ltrim((string) $file->getClientOriginalExtension(), '.'));
                    $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'upload';
                    $filename = $baseName.'_'.Str::random(8).'.'.$extension;
                    $path = $this->storage->storeUploadedFile($file, $dir, $filename);
                    $storedFiles[] = ['path' => $path, 'disk' => $disk];

                    $record = UploadedFile::create([
                        'filename' => $filename,
                        'original_name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'disk' => $disk,
                        'size' => $file->getSize(),
                        'mime_type' => strtolower((string) $file->getMimeType()),
                        'context' => $context,
                        'context_id' => $contextId,
                        'uploaded_by' => $ownerId,
                        'status' => $temp ? 'temp' : 'permanent',
                        'metadata' => [],
                    ]);

                    $this->log('file_uploaded', "Uploaded {$filename} ({$context}#{$contextId})", [
                        'file_id' => $record->id,
                        'size' => $file->getSize(),
                        'mime' => $record->mime_type,
                    ]);

                    $records->push($record);
                }

                return $records;
            });
        } catch (Throwable $exception) {
            foreach (array_reverse($storedFiles) as $storedFile) {
                $this->storage->deleteFile($storedFile['path'], $storedFile['disk']);
            }

            throw new RuntimeException('Upload could not be completed.', 0, $exception);
        }
    }

    private function servicePolicy(string $context): UploadContextPolicy
    {
        if ($this->contexts->has($context)) {
            return $this->contexts->policy($context);
        }

        $maxFileBytes = max(1, $this->storage->getMaxFileSize()) * 1024;

        return new UploadContextPolicy(
            static fn (): bool => true,
            max(1, $this->storage->getMaxActiveFiles()),
            $maxFileBytes,
            max($maxFileBytes, max(1, $this->storage->getMaxAggregateSize()) * 1024),
            $this->storage->getAllowedTypeMap()
        );
    }

    private function lockKey(string $context, int $contextId, ?int $ownerId): string
    {
        return 'upload-portal:quota:'.hash('sha256', json_encode([
            'context' => $context,
            'context_id' => $contextId,
            'owner_id' => $ownerId,
        ], JSON_THROW_ON_ERROR));
    }

    private function scopeOwner(mixed $query, ?int $userId, bool $scopeAnonymous = false): void
    {
        if ($userId === null) {
            if ($scopeAnonymous) {
                $query->whereNull('uploaded_by');
            }

            return;
        }

        $query->where('uploaded_by', $userId);
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, string $message, array $context = []): void
    {
        if (! function_exists('hexaLog')) {
            return;
        }

        try {
            hexaLog('upload-portal', $event, $message, $context);
        } catch (Throwable) {
            // Observability must never change upload lifecycle state.
        }
    }
}
