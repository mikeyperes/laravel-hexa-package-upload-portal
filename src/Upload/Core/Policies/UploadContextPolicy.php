<?php

namespace hexa_package_upload_portal\Upload\Core\Policies;

use Closure;
use InvalidArgumentException;

class UploadContextPolicy
{
    /** @var array<string, list<string>> */
    private array $allowedTypes;

    private Closure $authorization;

    /**
     * @param  callable(string, int, int|null, mixed): bool  $authorization
     * @param  array<string, string|list<string>>  $allowedTypes
     */
    public function __construct(
        callable $authorization,
        private readonly int $maxActiveFiles,
        private readonly int $maxFileBytes,
        private readonly int $maxTotalBytes,
        array $allowedTypes
    ) {
        if ($maxActiveFiles < 1 || $maxFileBytes < 1 || $maxTotalBytes < $maxFileBytes) {
            throw new InvalidArgumentException('Upload context limits must be positive and internally consistent.');
        }

        $this->authorization = Closure::fromCallable($authorization);
        $this->allowedTypes = $this->normalizeAllowedTypes($allowedTypes);

        if ($this->allowedTypes === []) {
            throw new InvalidArgumentException('An upload context must allow at least one extension and MIME type.');
        }
    }

    /**
     * @param array{
     *     authorize: callable(string, int, int|null, mixed): bool,
     *     max_active_files: int,
     *     max_file_bytes: int,
     *     max_total_bytes: int,
     *     allowed_types: array<string, string|list<string>>
     * } $policy
     */
    public static function fromArray(array $policy): self
    {
        foreach (['authorize', 'max_active_files', 'max_file_bytes', 'max_total_bytes', 'allowed_types'] as $key) {
            if (! array_key_exists($key, $policy)) {
                throw new InvalidArgumentException("Upload context policy is missing [{$key}].");
            }
        }

        if (! is_callable($policy['authorize'])) {
            throw new InvalidArgumentException('Upload context authorization must be callable.');
        }

        return new self(
            $policy['authorize'],
            (int) $policy['max_active_files'],
            (int) $policy['max_file_bytes'],
            (int) $policy['max_total_bytes'],
            (array) $policy['allowed_types']
        );
    }

    public function authorizes(string $action, int $contextId, ?int $userId, mixed $authorizationContext = null): bool
    {
        return (bool) ($this->authorization)($action, $contextId, $userId, $authorizationContext);
    }

    public function maxActiveFiles(): int
    {
        return $this->maxActiveFiles;
    }

    public function maxFileBytes(): int
    {
        return $this->maxFileBytes;
    }

    public function maxTotalBytes(): int
    {
        return $this->maxTotalBytes;
    }

    public function allows(string $extension, string $mimeType): bool
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        $mimeType = strtolower(trim($mimeType));

        return isset($this->allowedTypes[$extension])
            && in_array($mimeType, $this->allowedTypes[$extension], true);
    }

    /** @return array<string, list<string>> */
    public function allowedTypes(): array
    {
        return $this->allowedTypes;
    }

    /**
     * @param  array<string, string|list<string>>  $allowedTypes
     * @return array<string, list<string>>
     */
    private function normalizeAllowedTypes(array $allowedTypes): array
    {
        $normalized = [];

        foreach ($allowedTypes as $extension => $mimeTypes) {
            $extension = strtolower(ltrim(trim((string) $extension), '.'));
            if ($extension === '' || ! preg_match('/\A[a-z0-9]+\z/D', $extension)) {
                throw new InvalidArgumentException('Upload extensions must contain only lowercase letters and numbers.');
            }

            $mimeTypes = is_array($mimeTypes) ? $mimeTypes : [$mimeTypes];
            $mimeTypes = array_values(array_unique(array_filter(array_map(
                static fn (mixed $mimeType): string => strtolower(trim((string) $mimeType)),
                $mimeTypes
            ))));

            if ($mimeTypes === []) {
                throw new InvalidArgumentException("Upload extension [{$extension}] must define at least one MIME type.");
            }

            $normalized[$extension] = $mimeTypes;
        }

        return $normalized;
    }
}
