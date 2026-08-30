<?php

namespace hexa_package_upload_portal\Upload\Storage\Services;

use hexa_core\Models\Setting;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StorageService
{
    /**
     * Get a setting value — checks DB Setting first, falls back to config.
     */
    private function setting(string $key, mixed $default = null): mixed
    {
        if (class_exists(Setting::class)) {
            $dbValue = Setting::getValue('upload_portal_'.$key);
            if ($dbValue !== null && $dbValue !== '') {
                return $dbValue;
            }
        }

        return config('upload-portal.'.$key, $default);
    }

    /**
     * Get the upload directory path.
     */
    public function getUploadDir(): string
    {
        return (string) $this->setting('upload_dir', 'uploads');
    }

    /**
     * Get the temp directory path.
     */
    public function getTempDir(): string
    {
        return (string) $this->setting('temp_dir', 'uploads/temp');
    }

    /**
     * Get the storage disk used for upload portal files.
     */
    public function getDisk(): string
    {
        return (string) $this->setting('disk', 'public');
    }

    /**
     * Ensure the directory exists.
     */
    public function ensureDirectory(string $dir): void
    {
        try {
            $disk = Storage::disk($this->getDisk());
            if (! $disk->exists($dir) && ! $disk->makeDirectory($dir)) {
                throw new RuntimeException('Directory creation failed.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Upload storage is unavailable.', 0, $exception);
        }
    }

    /**
     * Get allowed file extensions.
     */
    public function getAllowedTypes(): array
    {
        $val = $this->setting('allowed_types', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (is_string($val)) {
            $val = explode(',', $val);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $extension): string => strtolower(ltrim(trim((string) $extension), '.')),
            (array) $val
        ))));
    }

    /** @return array<string, list<string>> */
    public function getAllowedTypeMap(): array
    {
        $configured = (array) $this->setting('allowed_mime_types', [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ]);
        $allowed = array_flip($this->getAllowedTypes());
        $types = [];

        foreach ($configured as $extension => $mimeTypes) {
            $extension = strtolower(ltrim(trim((string) $extension), '.'));
            if (! isset($allowed[$extension])) {
                continue;
            }

            $mimeTypes = is_array($mimeTypes) ? $mimeTypes : [$mimeTypes];
            $types[$extension] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $mimeType): string => strtolower(trim((string) $mimeType)),
                $mimeTypes
            ))));
        }

        return $types;
    }

    /**
     * Get max file size in KB.
     */
    public function getMaxFileSize(): int
    {
        return (int) $this->setting('max_file_size', 10240);
    }

    /**
     * Get max files per upload.
     */
    public function getMaxFilesPerUpload(): int
    {
        return (int) $this->setting('max_files_per_upload', 20);
    }

    public function getMaxActiveFiles(): int
    {
        return (int) $this->setting('max_active_files', 100);
    }

    public function getMaxAggregateSize(): int
    {
        return (int) $this->setting('max_aggregate_size', 102400);
    }

    public function storeUploadedFile(LaravelUploadedFile $file, string $dir, string $filename): string
    {
        $this->ensureDirectory($dir);
        $diskName = $this->getDisk();
        $candidatePath = trim($dir, '/').'/'.$filename;

        try {
            $disk = Storage::disk($diskName);
            $path = $disk->putFileAs($dir, $file, $filename);

            if (is_string($path) && trim($path) !== '' && $path !== '0' && $disk->exists($path)) {
                return $path;
            }

            $path = $candidatePath;
            $source = $file->getRealPath();
            if (! $source || ! is_readable($source)) {
                throw new RuntimeException('Uploaded temporary file is not readable.');
            }

            $stream = fopen($source, 'rb');
            try {
                $stored = $stream ? $disk->put($path, $stream) : false;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $stored || ! $disk->exists($path)) {
                throw new RuntimeException('File storage failed.');
            }

            return $path;
        } catch (Throwable $exception) {
            try {
                $disk = Storage::disk($diskName);
                if ($disk->exists($candidatePath)) {
                    $disk->delete($candidatePath);
                }
            } catch (Throwable) {
                // The caller receives one generic storage failure either way.
            }

            throw new RuntimeException('Upload storage failed.', 0, $exception);
        }
    }

    public function fileExists(string $path, ?string $disk = null): bool
    {
        try {
            return trim($path) !== ''
                && $path !== '0'
                && Storage::disk($disk ?: $this->getDisk())->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function url(string $path, ?string $disk = null): string
    {
        try {
            return Storage::disk($disk ?: $this->getDisk())->url($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('Upload storage is unavailable.', 0, $exception);
        }
    }

    /**
     * Delete a file from storage.
     */
    public function deleteFile(string $path, ?string $disk = null): bool
    {
        try {
            return trim($path) !== ''
                && $path !== '0'
                && Storage::disk($disk ?: $this->getDisk())->delete($path);
        } catch (Throwable) {
            return false;
        }
    }
}
