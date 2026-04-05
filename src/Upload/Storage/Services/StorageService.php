<?php

namespace hexa_package_upload_portal\Upload\Storage\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    /**
     * Get a setting value — checks DB Setting first, falls back to config.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function setting(string $key, mixed $default = null): mixed
    {
        if (class_exists(\hexa_core\Models\Setting::class)) {
            $dbValue = \hexa_core\Models\Setting::getValue('upload_portal_' . $key);
            if ($dbValue !== null && $dbValue !== '') {
                return $dbValue;
            }
        }
        return config('upload-portal.' . $key, $default);
    }

    /**
     * Get the upload directory path.
     *
     * @return string
     */
    public function getUploadDir(): string
    {
        return (string) $this->setting('upload_dir', 'uploads');
    }

    /**
     * Get the temp directory path.
     *
     * @return string
     */
    public function getTempDir(): string
    {
        return (string) $this->setting('temp_dir', 'uploads/temp');
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
        $val = $this->setting('allowed_types', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (is_string($val)) {
            return array_map('trim', explode(',', $val));
        }
        return (array) $val;
    }

    /**
     * Get max file size in KB.
     *
     * @return int
     */
    public function getMaxFileSize(): int
    {
        return (int) $this->setting('max_file_size', 10240);
    }

    /**
     * Get max files per upload.
     *
     * @return int
     */
    public function getMaxFilesPerUpload(): int
    {
        return (int) $this->setting('max_files_per_upload', 20);
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
