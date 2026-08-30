<?php

namespace hexa_package_upload_portal\Upload\Core\Authorization;

use hexa_package_upload_portal\Upload\Core\Exceptions\UploadPolicyViolation;
use hexa_package_upload_portal\Upload\Core\Policies\UploadContextPolicy;
use InvalidArgumentException;
use Throwable;

class UploadContextRegistry
{
    private const ACTIONS = ['upload', 'list', 'delete', 'cleanup'];

    /** @var array<string, UploadContextPolicy> */
    private array $policies = [];

    /**
     * Register one exact context. Wildcards and prefix matches are not supported.
     *
     * @param UploadContextPolicy|array{
     *     authorize: callable(string, int, int|null, mixed): bool,
     *     max_active_files: int,
     *     max_file_bytes: int,
     *     max_total_bytes: int,
     *     allowed_types: array<string, string|list<string>>
     * } $policy
     */
    public function register(string $context, UploadContextPolicy|array $policy): self
    {
        $context = $this->normalizeContext($context);
        $this->policies[$context] = is_array($policy)
            ? UploadContextPolicy::fromArray($policy)
            : $policy;

        return $this;
    }

    public function has(string $context): bool
    {
        try {
            $context = $this->normalizeContext($context);
        } catch (InvalidArgumentException) {
            return false;
        }

        return isset($this->policies[$context]);
    }

    public function policy(string $context): UploadContextPolicy
    {
        try {
            $context = $this->normalizeContext($context);
        } catch (InvalidArgumentException) {
            throw new UploadPolicyViolation('Upload context is not available.', 404);
        }

        return $this->policies[$context]
            ?? throw new UploadPolicyViolation('Upload context is not available.', 404);
    }

    public function authorize(
        string $context,
        string $action,
        int $contextId,
        ?int $userId,
        mixed $authorizationContext = null
    ): UploadContextPolicy {
        $policy = $this->policy($context);

        try {
            $authorized = $contextId >= 0
                && in_array($action, self::ACTIONS, true)
                && $policy->authorizes($action, $contextId, $userId, $authorizationContext);
        } catch (Throwable) {
            $authorized = false;
        }

        if (! $authorized) {
            throw new UploadPolicyViolation('Upload context access is denied.', 403);
        }

        return $policy;
    }

    private function normalizeContext(string $context): string
    {
        if ($context !== trim($context)
            || ! preg_match('/\A[a-z0-9][a-z0-9._-]{0,49}\z/D', $context)) {
            throw new InvalidArgumentException('Upload contexts must be exact lowercase identifiers.');
        }

        return $context;
    }
}
