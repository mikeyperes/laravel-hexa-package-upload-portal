<?php

namespace hexa_package_upload_portal\Upload\Core\Services;

use hexa_package_upload_portal\Upload\Media\Models\UploadedFile;
use hexa_package_upload_portal\Upload\Storage\Services\StorageService;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $this->storage->ensureDirectory($dir);

        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '_' . Str::random(8) . '.' . $ext;
        $path = $file->storeAs($dir, $filename);

        $record = UploadedFile::create([
            'filename' => $filename,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => config('filesystems.default', 'local'),
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
    public function getFiles(string $context, int $contextId): Collection
    {
        return UploadedFile::where('context', $context)
            ->where('context_id', $contextId)
            ->where('status', '!=', 'deleted')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Delete a file and its DB record.
     *
     * @param int $fileId
     * @return bool
     */
    public function delete(int $fileId): bool
    {
        $file = UploadedFile::find($fileId);
        if (!$file) return false;

        $this->storage->deleteFile($file->path);
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
    public function cleanup(string $context, int $contextId): int
    {
        $files = UploadedFile::where('context', $context)
            ->where('context_id', $contextId)
            ->where('status', 'temp')
            ->get();

        $count = 0;
        foreach ($files as $file) {
            $this->storage->deleteFile($file->path);
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
        return storage_path('app/' . $this->storage->getTempDir());
    }
}
