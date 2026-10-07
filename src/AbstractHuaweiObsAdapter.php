<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 *
 * Ported from mubbi/laravel-flysystem-huawei-obs (MIT). The Laravel service
 * provider / macro / `logger()` layer is gone; logging goes through PSR-3 and
 * sleeping between retries is coroutine-aware.
 */

namespace Hyperf\Flysystem\Obs;

use Hyperf\Flysystem\Obs\Exception\UnableToCreatePostSignature;
use Hyperf\Flysystem\Obs\Exception\UnableToCreateSignedUrl;
use Hyperf\Flysystem\Obs\Exception\UnableToDeleteObjectTags;
use Hyperf\Flysystem\Obs\Exception\UnableToGetObjectTags;
use Hyperf\Flysystem\Obs\Exception\UnableToRestoreObject;
use Hyperf\Flysystem\Obs\Exception\UnableToSetObjectTags;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use QianXiong\Internal\Common\Model;
use QianXiong\ObsClient;
use QianXiong\ObsException;
use RuntimeException;
use Throwable;

/**
 * Shared OBS plumbing: retries, logging, error classification and the OBS-only
 * extras (signed URLs, post signatures, object tags, archive restore).
 */
abstract class AbstractHuaweiObsAdapter
{
    protected ?bool $authenticated = null;

    protected ?int $authCacheExpiry = null;

    public function __construct(
        protected readonly ObsClient $client,
        protected readonly ObsConfig $config,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The underlying SDK client, for operations this adapter does not wrap.
     */
    public function getClient(): ObsClient
    {
        return $this->client;
    }

    public function getConfig(): ObsConfig
    {
        return $this->config;
    }

    /**
     * Swap in fresh credentials — useful for short-lived STS tokens.
     */
    public function refreshCredentials(string $accessKeyId, string $secretAccessKey, ?string $securityToken = null): void
    {
        $this->client->refresh($accessKeyId, $secretAccessKey, $securityToken);
        $this->authenticated = null;
        $this->authCacheExpiry = null;
    }

    /**
     * Create a pre-signed URL for temporary access to an object.
     *
     * @param array<string, string> $headers additional headers to sign
     *
     * @throws UnableToCreateSignedUrl
     */
    public function createSignedUrl(string $path, string $method = 'GET', ?int $expires = null, array $headers = []): string
    {
        $startTime = microtime(true);
        $expires ??= $this->config->signedUrlExpires;

        try {
            $this->checkAuthentication();

            $key = $this->getKey($path);

            $result = $this->withRetry(fn () => $this->client->createSignedUrl([
                'Method' => $method,
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'Expires' => $expires,
                'Headers' => $headers,
            ]));

            $this->logOperation('createSignedUrl', $path, microtime(true) - $startTime, [
                'method' => $method,
                'expires' => $expires,
            ]);

            return (string) $result['SignedUrl'];
        } catch (ObsException $e) {
            $this->logError('createSignedUrl', $path, $e);

            throw UnableToCreateSignedUrl::forLocation($path, $e);
        }
    }

    /**
     * Create a POST policy signature for direct browser uploads.
     *
     * @param array<string, mixed> $formParams extra form fields that become POST policy
     *                                         conditions (e.g. ['content-type' => 'text/plain',
     *                                         'x-obs-acl' => 'public-read'])
     *
     * @return array<string, mixed>
     *
     * @throws UnableToCreatePostSignature
     */
    public function createPostSignature(string $path, array $formParams = [], ?int $expires = null): array
    {
        $startTime = microtime(true);
        $expires ??= $this->config->signedUrlExpires;

        try {
            $this->checkAuthentication();

            $key = $this->getKey($path);

            $result = $this->withRetry(fn () => $this->client->createPostSignature([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'Expires' => $expires,
                'FormParams' => $formParams,
            ]));

            $this->logOperation('createPostSignature', $path, microtime(true) - $startTime, ['expires' => $expires]);

            return $this->normaliseResult($result);
        } catch (ObsException $e) {
            $this->logError('createPostSignature', $path, $e);

            throw UnableToCreatePostSignature::forLocation($path, $e);
        }
    }

    /**
     * Replace the object's tag set with the given map.
     *
     * @param array<string, string> $tags
     *
     * @throws UnableToSetObjectTags
     */
    public function setObjectTags(string $path, array $tags): void
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $key = $this->getKey($path);

            // OBS takes a tag set as a list of {Key, Value} pairs and this SDK exposes it
            // as the `Tags` argument (its resource map sends `Tags` as the TagSet XML
            // node). Passing `TagSet`, or an associative map, makes the client-side
            // validator abort with "param:Tags is required" before a request is ever sent.
            $tagSet = $this->toTagSet($tags);

            $this->withRetry(fn () => $this->client->setObjectTagging([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'Tags' => $tagSet,
            ]));

            $this->logOperation('setObjectTags', $path, microtime(true) - $startTime, ['tags_count' => count($tagSet)]);
        } catch (ObsException $e) {
            $this->logError('setObjectTags', $path, $e);

            throw UnableToSetObjectTags::forLocation($path, $e);
        }
    }

    /**
     * Read the object's tags as a plain map.
     *
     * An object that was never tagged comes back as `[]`: OBS answers 404
     * `NoSuchTagSet` for it, which is not a failure.
     *
     * @return array<string, string>
     *
     * @throws UnableToGetObjectTags
     */
    public function getObjectTags(string $path): array
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            // The SDK parses the TagSet XML node into `Tags`, a list of {Key, Value}
            // pairs: neither a `TagSet` key, nor a map of tag names to values.
            $tags = $this->fetchObjectTags($this->getKey($path)) ?? [];

            $this->logOperation('getObjectTags', $path, microtime(true) - $startTime, ['tags_count' => count($tags)]);

            return $tags;
        } catch (ObsException $e) {
            $this->logError('getObjectTags', $path, $e);

            throw UnableToGetObjectTags::forLocation($path, $e);
        }
    }

    /**
     * Remove every tag from an object.
     *
     * OBS's own DeleteObjectTagging is out of reach through this SDK: its
     * `deleteObjectTagging` resource map declares no `Key`, so the call would go to
     * `DELETE /{bucket}?tagging` — it wipes the *bucket* tag set and leaves the object's
     * tags untouched. Clearing therefore takes the documented OBS route instead: a
     * server-side self copy with `x-obs-tagging-directive: REPLACE` and an empty
     * `x-obs-tagging` header, replaying the content headers, user metadata and ACL read
     * beforehand so that nothing but the tags changes.
     *
     * Caveats of that copy: the object gets a fresh LastModified (and an extra version on
     * a versioned bucket), it has to stay under the 5 GiB copy limit, and an object still
     * in OBS Archive must be restored before it can be rewritten.
     *
     * @throws UnableToDeleteObjectTags
     */
    public function deleteObjectTags(string $path): void
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $key = $this->getKey($path);

            if (($this->fetchObjectTags($key) ?? []) === []) {
                // Untagged already: a copy would only bump LastModified.
                $this->logOperation('deleteObjectTags', $path, microtime(true) - $startTime, ['skipped' => 'no tags']);

                return;
            }

            $metadata = $this->normaliseResult($this->withRetry(fn () => $this->client->getObjectMetadata([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
            ])));

            $acl = $this->normaliseResult($this->withRetry(fn () => $this->client->getObjectAcl([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
            ])));

            $options = [
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'CopySource' => $this->config->bucket . '/' . $key,
                'MetadataDirective' => 'REPLACE',
                'TaggingDirective' => 'REPLACE',
                'Tagging' => '',
            ];

            // StorageClass is deliberately left out: omitting it keeps the source class,
            // while replaying it would move WARM/COLD objects back to STANDARD.
            foreach (['ContentType', 'CacheControl', 'ContentDisposition', 'ContentEncoding', 'ContentLanguage', 'Expires', 'WebsiteRedirectLocation', 'Metadata'] as $header) {
                if (! empty($metadata[$header])) {
                    $options[$header] = $metadata[$header];
                }
            }

            $this->withRetry(fn () => $this->client->copyObject($options));

            // A copy always lands private, so put the ACL that was read back in place.
            if (! empty($acl['Grants'])) {
                $restore = [
                    'Bucket' => $this->config->bucket,
                    'Key' => $key,
                    'Grants' => $acl['Grants'],
                ];

                if (! empty($acl['Owner'])) {
                    $restore['Owner'] = $acl['Owner'];
                }

                $this->withRetry(fn () => $this->client->setObjectAcl($restore));
            }

            $this->logOperation('deleteObjectTags', $path, microtime(true) - $startTime);
        } catch (ObsException $e) {
            $this->logError('deleteObjectTags', $path, $e);

            throw UnableToDeleteObjectTags::forLocation($path, $e);
        }
    }

    /**
     * OBS wants a tag set as a list of {Key, Value} pairs; callers pass a plain map.
     *
     * @param array<string, string> $tags
     *
     * @return array<int, array{Key: string, Value: string}>
     */
    protected function toTagSet(array $tags): array
    {
        $tagSet = [];

        foreach ($tags as $key => $value) {
            $tagSet[] = ['Key' => (string) $key, 'Value' => (string) $value];
        }

        return $tagSet;
    }

    /**
     * Read an object's tag set as a plain map.
     *
     * @return null|array<string, string> null when the object was never tagged: OBS
     *                                    answers NoSuchTagSet with a 404 instead of an
     *                                    empty tag set.
     */
    protected function fetchObjectTags(string $key): ?array
    {
        try {
            $result = $this->withRetry(fn () => $this->client->getObjectTagging([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
            ]));
        } catch (ObsException $e) {
            if ($this->extractErrorCode($e) === 'NoSuchTagSet') {
                return null;
            }

            throw $e;
        }

        $tags = [];

        foreach ($this->normaliseResult($result)['Tags'] ?? [] as $tag) {
            $tag = $this->normaliseResult($tag);

            if (isset($tag['Key'])) {
                $tags[(string) $tag['Key']] = (string) ($tag['Value'] ?? '');
            }
        }

        return $tags;
    }

    /**
     * Restore an object from cold/archive storage.
     *
     * @throws UnableToRestoreObject
     */
    public function restoreObject(string $path, int $days = 1): void
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $key = $this->getKey($path);

            $this->withRetry(fn () => $this->client->restoreObject([
                'Bucket' => $this->config->bucket,
                'Key' => $key,
                'Days' => $days,
            ]));

            $this->logOperation('restoreObject', $path, microtime(true) - $startTime, ['days' => $days]);
        } catch (ObsException $e) {
            $this->logError('restoreObject', $path, $e);

            throw UnableToRestoreObject::forLocation($path, $e);
        }
    }

    /**
     * Verify credentials and bucket access with a `headBucket` call.
     *
     * Disabled by default: it costs one extra round trip per adapter and the
     * per-operation error mapping in {@see self::describeObsError()} already
     * produces actionable messages. Enable with `'check_authentication' => true`.
     *
     * @throws RuntimeException when credentials or the bucket are unusable
     */
    protected function checkAuthentication(): void
    {
        if (! $this->config->checkAuthentication) {
            return;
        }

        if ($this->authenticated === true && $this->authCacheExpiry !== null && time() < $this->authCacheExpiry) {
            return;
        }

        $this->withRetry(function (): void {
            try {
                $this->client->headBucket(['Bucket' => $this->config->bucket]);
                $this->authenticated = true;
                $this->authCacheExpiry = time() + 300;
            } catch (ObsException $e) {
                $this->authenticated = false;

                if ($this->isAuthenticationError($e) || $this->isBucketError($e)) {
                    throw new RuntimeException($this->describeObsError($e), 0, $e);
                }

                throw $e;
            }
        });
    }

    /**
     * Run an operation, retrying transient OBS failures with exponential backoff.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    protected function withRetry(callable $operation)
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->config->retryAttempts) {
            try {
                return $operation();
            } catch (ObsException $e) {
                $lastException = $e;
                ++$attempts;

                // Credential and bucket problems will not fix themselves.
                if ($this->isAuthenticationError($e) || $this->isBucketError($e) || $this->isNotFoundError($e)) {
                    throw $e;
                }

                if ($attempts >= $this->config->retryAttempts) {
                    break;
                }

                $this->sleep($this->config->retryDelay * (2 ** ($attempts - 1)));
            }
        }

        if ($lastException === null) {
            throw new RuntimeException('Unexpected error in retry logic');
        }

        throw $lastException;
    }

    /**
     * Coroutine-aware sleep so a retry never blocks the whole worker.
     */
    protected function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);

            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function logOperation(string $operation, string $path, float $duration, array $context = []): void
    {
        if ($this->logger === null || ! $this->config->loggingEnabled || ! $this->config->logOperations) {
            return;
        }

        $this->logger->info('Huawei OBS operation', array_merge([
            'operation' => $operation,
            'path' => $path,
            'duration' => round($duration * 1000, 2),
            'bucket' => $this->config->bucket,
        ], $context));
    }

    protected function logError(string $operation, string $path, Throwable $exception): void
    {
        if ($this->logger === null || ! $this->config->loggingEnabled || ! $this->config->logErrors) {
            return;
        }

        $this->logger->error('Huawei OBS error', [
            'operation' => $operation,
            'path' => $path,
            'bucket' => $this->config->bucket,
            'error' => $exception->getMessage(),
            'code' => $exception instanceof ObsException ? $this->extractErrorCode($exception) : null,
        ]);
    }

    protected function getKey(string $path): string
    {
        return $this->config->applyPrefix($path);
    }

    protected function getRelativePath(string $key): string
    {
        return $this->config->stripPrefix($key);
    }

    protected function visibilityToAcl(string $visibility): string
    {
        return match ($visibility) {
            'public', 'public-read' => 'public-read',
            default => 'private',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $grants
     */
    protected function aclToVisibility(array $grants): string
    {
        foreach ($grants as $grant) {
            $uri = $grant['Grantee']['URI'] ?? null;
            $permission = $grant['Permission'] ?? null;

            // OBS mirrors the S3 canned-group URI for "everyone".
            if (is_string($uri)
                && (str_ends_with($uri, '/groups/global/AllUsers') || $uri === 'Everyone')
                && in_array($permission, ['READ', 'READ_ACP', 'FULL_CONTROL'], true)
            ) {
                return 'public';
            }
        }

        return 'private';
    }

    /**
     * Turn an OBS error into a message that names the likely misconfiguration.
     *
     * This is what replaces the eager `headBucket` pre-flight: instead of paying
     * a round trip up front, every operation maps its own failure.
     */
    protected function describeObsError(ObsException $exception): string
    {
        $code = $this->extractErrorCode($exception) ?? 'Unknown';
        $detail = $exception->getExceptionMessage() ?: $exception->getMessage();

        if ($this->isAuthenticationError($exception)) {
            return sprintf(
                'Huawei OBS rejected the credentials (%s). Check file.storage.*.key / secret'
                . ' (and security_token when using temporary credentials). bucket=%s endpoint=%s. %s',
                $code,
                $this->config->bucket,
                $this->config->endpoint,
                $detail
            );
        }

        if ($this->isBucketError($exception)) {
            return sprintf(
                'Huawei OBS bucket "%s" does not exist or is not reachable (%s). Check file.storage.*.bucket,'
                . ' endpoint and region — the endpoint must match the bucket\'s region. endpoint=%s. %s',
                $this->config->bucket,
                $code,
                $this->config->endpoint,
                $detail
            );
        }

        return sprintf('Huawei OBS error %s: %s', $code, $detail);
    }

    /**
     * OBS returns the machine-readable code in the body or, for HEAD requests
     * which have no body, in the `x-obs-error-code` header.
     */
    protected function extractErrorCode(ObsException $exception): ?string
    {
        $errorCode = $exception->getExceptionCode();

        if ($errorCode !== null && $errorCode !== '') {
            return (string) $errorCode;
        }

        $response = $exception->getResponse();

        if ($response instanceof ResponseInterface) {
            $header = $response->getHeaderLine('x-obs-error-code');

            if ($header !== '') {
                return $header;
            }
        }

        return null;
    }

    protected function isNotFoundError(ObsException $exception): bool
    {
        $errorCode = $this->extractErrorCode($exception);

        // NoSuchTagSet (an object that was never tagged) belongs here as well: it is a
        // definitive answer, so retrying it only burns round trips.
        if (in_array($errorCode, ['NoSuchKey', 'NoSuchResource', 'NoSuchVersion', 'NoSuchTagSet'], true)) {
            return true;
        }

        // HEAD responses carry neither body nor error header on some OBS regions.
        if ($errorCode === null && $exception->getStatusCode() === 404) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'NoSuchKey') || str_contains($message, 'NoSuchResource');
    }

    protected function isAuthenticationError(ObsException $exception): bool
    {
        return in_array($this->extractErrorCode($exception), [
            'AccessDenied',
            'InvalidAccessKeyId',
            'SignatureDoesNotMatch',
            'RequestTimeTooSkewed',
        ], true);
    }

    protected function isBucketError(ObsException $exception): bool
    {
        return in_array($this->extractErrorCode($exception), ['NoSuchBucket', 'BucketNotEmpty'], true);
    }

    /**
     * The SDK returns `QianXiong\Internal\Common\Model`, which already exposes the
     * raw payload — no reflection needed (the upstream package guessed at keys).
     *
     * @return array<string, mixed>
     */
    protected function normaliseResult(mixed $result): array
    {
        if ($result instanceof Model) {
            return $result->toArray();
        }

        if (is_array($result)) {
            return $result;
        }

        return is_object($result) ? get_object_vars($result) : (array) $result;
    }
}
