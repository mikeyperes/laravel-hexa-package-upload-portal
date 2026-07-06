<?php

namespace hexa_package_upload_portal\Upload\Core\Services;

use hexa_package_upload_portal\Upload\Media\Models\UploadedFile;
use hexa_package_upload_portal\Upload\Storage\Services\StorageService;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UploadService
{
    public function __construct(
        private StorageService $storage
    ) {}

    /**
     * Upload a file and create a DB record.
     *
     * @param LaravelUploadedFile $file
     * @param string $context e.g. 'article', 'profile'
     * @param int $contextId e.g. draft ID
     * @param int|null $userId
     * @param bool $temp Whether to store in temp directory
     * @return UploadedFile
     */
    public function upload(LaravelUploadedFile $file, string $context, int $contextId, ?int $userId = null, bool $temp = true): UploadedFile
    {
        $dir = $temp ? $this->storage->getTempDir() : $this->storage->getUploadDir();
        $disk = $this->storage->getDisk();
        $this->storage->ensureDirectory($dir);

        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . Str::random(8) . '.' . $ext;
        $path = Storage::disk($disk)->putFileAs($dir, $file, $filename);
        if (!is_string($path) || trim($path) === '' || $path === '0' || !Storage::disk($disk)->exists($path)) {
            $path = trim($dir, '/') . '/' . $filename;
            $source = $file->getRealPath();
            if (!$source || !is_readable($source)) {
                throw new RuntimeException('Upload failed: uploaded temp file is not readable.');
            }

            $stream = fopen($source, 'rb');
            try {
                $stored = $stream ? Storage::disk($disk)->put($path, $stream) : false;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (!$stored || !Storage::disk($disk)->exists($path)) {
                throw new RuntimeException('Upload failed: file was not written to disk ' . $disk . ' at ' . $path . '.');
            }
        }

        $record = UploadedFile::create([
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => $disk,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'context' => $context,
            'context_id' => $contextId,
            'uploaded_by' => $userId ?? auth()->id(),
            'status' => $temp ? 'temp' : 'permanent',
            'metadata' => [],
        ]);

        if (function_exists('hexaLog')) {
            hexaLog('upload-portal', 'file_uploaded', "Uploaded {$filename} ({$context}#{$contextId})", [
                'file_id' => $record->id,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ]);
        }

        return $record;
    }

    /**
     * Get files by context and context ID.
     *
     * @param string $context
     * @param int $contextId
     * @return Collection
     */
    public function getFiles(string $context, int $contextId, ?int $userId = null): Collection
    {
        $query = UploadedFile::where('context', $context)
            ->where('context_id', $contextId)
            ->where('status', '!=', 'deleted')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->where('path', '!=', '0');

        if ($userId !== null) {
            $query->where('uploaded_by', $userId);
        }

        $files = $query->orderBy('created_at')->get();

        return $files->filter(function (UploadedFile $file): bool {
            $path = trim((string) $file->path);
            if ($path === '' || $path === '0') {
                $file->update(['status' => 'deleted']);
                return false;
            }

            $disk = $file->disk ?: $this->storage->getDisk();
            if (!Storage::disk($disk)->exists($path)) {
                $file->update(['status' => 'deleted']);
                return false;
            }

            return true;
        })->values();
    }

    /**
     * Delete a file and its DB record. Enforces ownership.
     *
     * @param int $fileId
     * @param int|null $userId Owner check — null skips (admin)
     * @return bool
     */
    public function delete(int $fileId, ?int $userId = null): bool
    {
        $file = UploadedFile::find($fileId);
        if (!$file) return false;
        if ($userId !== null && (int) $file->uploaded_by !== $userId) return false;

        $this->storage->deleteFile($file->path, $file->disk ?: null);
        $file->update(['status' => 'deleted']);

        if (function_exists('hexaLog')) {
            hexaLog('upload-portal', 'file_deleted', "Deleted {$file->filename}", [
                'file_id' => $file->id,
                'context' => $file->context,
                'context_id' => $file->context_id,
            ]);
        }

        return true;
    }

    /**
     * Delete all files for a context.
     *
     * @param string $context
     * @param int $contextId
     * @return int Number of files cleaned up
     */
    public function cleanup(string $context, int $contextId, ?int $userId = null): int
    {
        $query = UploadedFile::where('context', $context)
            ->where('context_id', $contextId)
            ->where('status', 'temp');

        if ($userId !== null) {
            $query->where('uploaded_by', $userId);
        }

        $files = $query
            ->get();

        $count = 0;
        foreach ($files as $file) {
            $this->storage->deleteFile($file->path, $file->disk ?: null);
            $file->update(['status' => 'deleted']);
            $count++;
        }

        if ($count > 0 && function_exists('hexaLog')) {
            hexaLog('upload-portal', 'cleanup', "Cleaned up {$count} temp file(s) for {$context}#{$contextId}");
        }

        return $count;
    }

    /**
     * Get the configured temp directory path.
     *
     * @return string
     */
    public function getTempPath(): string
    {
        $disk = $this->storage->getDisk();
        $prefix = $disk === 'public' ? 'app/public/' : 'app/';
        return storage_path($prefix . $this->storage->getTempDir());
    }
}

