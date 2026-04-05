<?php

namespace hexa_package_upload_portal\Upload\Storage\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    /**
     * Get the upload directory path.
     *
     * @return string
     */
    public function getUploadDir(): string
    {
        return config('upload-portal.upload_dir', 'uploads');
    }

    /**
     * Get the temp directory path.
     *
     * @return string
     */
    public function getTempDir(): string
    {
        return config('upload-portal.temp_dir', 'uploads/temp');
    }

    /**
     * Ensure the directory exists.
     *
     * @param string $dir
     * @return void
     */
    public function ensureDirectory(string $dir): void
    {
        if (!Storage::exists($dir)) {
            Storage::makeDirectory($dir);
        }
    }

    /**
     * Get allowed file extensions.
     *
     * @return array
     */
    public function getAllowedTypes(): array
    {
        return config('upload-portal.allowed_types', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    /**
     * Get max file size in KB.
     *
     * @return int
     */
    public function getMaxFileSize(): int
    {
        return (int) config('upload-portal.max_file_size', 10240);
    }

    /**
     * Get max files per upload.
     *
     * @return int
     */
    public function getMaxFilesPerUpload(): int
    {
        return (int) config('upload-portal.max_files_per_upload', 20);
    }

    /**
     * Delete a file from storage.
     *
     * @param string $path
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        return Storage::delete($path);
    }
}
