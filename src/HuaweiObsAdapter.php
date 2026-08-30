<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 *
 * Ported from mubbi/laravel-flysystem-huawei-obs (MIT) with the following fixes:
 *  - pagination driven by IsTruncated instead of NextMarker (upstream silently
 *    stopped after the first page on deep listings)
 *  - deleteDirectory streams and chunks deletes at the OBS 1000-object cap
 *  - listContents no longer reports every object as "private"
 *  - readStream no longer materialises the whole object as a PHP string
 *  - public URLs support virtual-hosted style, path style and CDN domains
 */

namespace Hyperf\Flysystem\Obs;

use DateTimeInterface;
use Generator;
use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use QianXiong\ObsException;
use RuntimeException;

/**
 * Flysystem v3 adapter for Huawei Cloud OBS.
 *
 * Implementing PublicUrlGenerator / TemporaryUrlGenerator / ChecksumProvider means
 * `$filesystem->publicUrl()`, `$filesystem->temporaryUrl()` and
 * `$filesystem->checksum()` work straight through League\Flysystem\Filesystem —
 * no need to reach for the adapter.
 */
final class HuaweiObsAdapter extends AbstractHuaweiObsAdapter implements
    FilesystemAdapter,
    PublicUrlGenerator,
    TemporaryUrlGenerator,
    ChecksumProvider
{
    /**
     * Maximum lifetime OBS accepts for a pre-signed URL: 7 days.
     */
    private const MAX_SIGNED_URL_TTL = 604800;

    /**
     * Build straight from a `storage.<name>` config array.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options, ?LoggerInterface $logger = null, string $name = 'obs'): self
    {
        $config = ObsConfig::fromArray($options, $name);

        return new self($config->createClient(), $config, $logger);
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->checkAuthentication();

            $this->withRetry(fn () => $this->client->getObjectMetadata([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));

            return true;
        } catch (ObsException $e) {
            if ($this->isNotFoundError($e)) {
                return false;
            }

            $this->logError('fileExists', $path, $e);

            throw UnableToCheckFileExistence::forLocation($path, $e);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $this->checkAuthentication();

            $result = $this->withRetry(fn () => $this->client->listObjects([
                'Bucket' => $this->config->bucket,
                'Prefix' => $this->directoryPrefix($path),
                'MaxKeys' => 1,
            ]));

            return ! empty($result['Contents']) || ! empty($result['CommonPrefixes']);
        } catch (ObsException $e) {
            if ($this->isNotFoundError($e)) {
                return false;
            }

            $this->logError('directoryExists', $path, $e);

            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * @param resource $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function read(string $path): string
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $result = $this->withRetry(fn () => $this->client->getObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));

            $this->logOperation('read', $path, microtime(true) - $startTime);

            return (string) $result['Body'];
        } catch (ObsException $e) {
            $this->logError('read', $path, $e);

            throw UnableToReadFile::fromLocation($path, $this->readFailureReason($e), $e);
        }
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $result = $this->withRetry(fn () => $this->client->getObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));

            $this->logOperation('readStream', $path, microtime(true) - $startTime);

            $body = $result['Body'];

            // The SDK hands back the Guzzle response body, which is already a
            // php://temp-backed stream (it spills to disk past 2 MB), so wrap it
            // instead of copying. `SaveAsStream` is deliberately NOT used: it
            // switches the SDK to its PHP-stream handler, which Swoole does not
            // reliably hook and which would block the worker.
            if ($body instanceof StreamInterface) {
                return StreamWrapper::getResource($body);
            }

            return $this->bufferToStream((string) $body);
        } catch (ObsException $e) {
            $this->logError('readStream', $path, $e);

            throw UnableToReadFile::fromLocation($path, $this->readFailureReason($e), $e);
        }
    }

    public function delete(string $path): void
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $this->withRetry(fn () => $this->client->deleteObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));

            $this->logOperation('delete', $path, microtime(true) - $startTime);
        } catch (ObsException $e) {
            // Deleting something that is already gone is a success in Flysystem.
            if ($this->isNotFoundError($e)) {
                return;
            }

            $this->logError('delete', $path, $e);

            throw UnableToDeleteFile::atLocation($path, $this->describeObsError($e), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $startTime = microtime(true);
        $prefix = $this->directoryPrefix($path);
        $deleted = 0;

        try {
            $this->checkAuthentication();

            $batch = [];

            // Streams the listing and flushes every 1000 keys: OBS rejects a
            // deleteObjects call carrying more than that, and buffering the whole
            // directory would be unbounded memory for large prefixes.
            foreach ($this->eachPage($prefix, null) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    if (! isset($object['Key'])) {
                        continue;
                    }

                    $batch[] = ['Key' => $object['Key']];

                    if (count($batch) >= ObsConfig::DELETE_BATCH_SIZE) {
                        $deleted += $this->deleteBatch($path, $batch);
                        $batch = [];
                    }
                }
            }

            if ($batch !== []) {
                $deleted += $this->deleteBatch($path, $batch);
            }

            $this->logOperation('deleteDirectory', $path, microtime(true) - $startTime, ['deleted' => $deleted]);
        } catch (ObsException $e) {
            $this->logError('deleteDirectory', $path, $e);

            throw UnableToDeleteDirectory::atLocation($path, $this->describeObsError($e), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->checkAuthentication();

            $this->withRetry(fn () => $this->client->putObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->directoryPrefix($path),
                'Body' => '',
            ]));
        } catch (ObsException $e) {
            $this->logError('createDirectory', $path, $e);

            throw UnableToCreateDirectory::atLocation($path, $this->describeObsError($e), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $this->checkAuthentication();

            $this->withRetry(fn () => $this->client->setObjectAcl([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
                'ACL' => $this->visibilityToAcl($visibility),
            ]));
        } catch (ObsException $e) {
            $this->logError('setVisibility', $path, $e);

            throw UnableToSetVisibility::atLocation($path, $this->describeObsError($e), $e);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $this->checkAuthentication();

            $result = $this->withRetry(fn () => $this->client->getObjectAcl([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));

            return new FileAttributes($path, null, $this->aclToVisibility($result['Grants'] ?? []));
        } catch (ObsException $e) {
            $this->logError('visibility', $path, $e);

            throw UnableToRetrieveMetadata::visibility($path, $this->readFailureReason($e), $e);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->metadata($path, 'mimeType');

        if ($attributes->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'The object has no Content-Type.');
        }

        return $attributes;
    }

    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->metadata($path, 'lastModified');

        if ($attributes->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path, 'The object has no Last-Modified.');
        }

        return $attributes;
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->metadata($path, 'fileSize');

        if ($attributes->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'The object has no Content-Length.');
        }

        return $attributes;
    }

    /**
     * @return iterable<FileAttributes|DirectoryAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $this->directoryPrefix($path);
        // Without a delimiter OBS flattens the whole subtree; with '/' it groups
        // one level into CommonPrefixes.
        $delimiter = $deep ? null : '/';
        $lastKey = null;

        try {
            $this->checkAuthentication();

            foreach ($this->eachPage($prefix, $delimiter) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $key = $object['Key'] ?? null;

                    // Skip the directory marker itself, and guard against a
                    // repeated key should OBS ever hand back a stuck marker.
                    if ($key === null || $key === $prefix || $key === $lastKey) {
                        continue;
                    }

                    $lastKey = $key;

                    yield new FileAttributes(
                        $this->getRelativePath($key),
                        isset($object['Size']) ? (int) $object['Size'] : null,
                        // ListObjects does NOT return Grants, so visibility is
                        // genuinely unknown here — upstream reported "private"
                        // for every object. Call visibility() for the real value.
                        null,
                        isset($object['LastModified']) ? $this->toTimestamp($object['LastModified']) : null,
                        null,
                        ['etag' => $object['ETag'] ?? null, 'storage_class' => $object['StorageClass'] ?? null],
                    );
                }

                foreach ($page['CommonPrefixes'] ?? [] as $commonPrefix) {
                    $prefixKey = $commonPrefix['Prefix'] ?? null;

                    if ($prefixKey === null || rtrim($prefixKey, '/') === rtrim($prefix, '/')) {
                        continue;
                    }

                    yield new DirectoryAttributes($this->getRelativePath(rtrim($prefixKey, '/')));
                }
            }
        } catch (ObsException $e) {
            $this->logError('listContents', $path, $e);

            throw UnableToListContents::atLocation($path, $deep, $e);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->checkAuthentication();
            $this->copyObject($source, $destination, $config);

            $this->withRetry(fn () => $this->client->deleteObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($source),
            ]));
        } catch (ObsException $e) {
            $this->logError('move', $source, $e);

            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->checkAuthentication();
            $this->copyObject($source, $destination, $config);
        } catch (ObsException $e) {
            $this->logError('copy', $source, $e);

            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Direct, unsigned URL to the object.
     *
     * Three shapes, in priority order:
     *  1. `domain` set              → https://cdn.example.com/key
     *  2. `path_style` true         → https://obs.cn-north-4.myhuaweicloud.com/bucket/key
     *  3. default virtual-hosted    → https://bucket.obs.cn-north-4.myhuaweicloud.com/key
     */
    public function publicUrl(string $path, Config $config): string
    {
        $key = $this->encodeKey($this->getKey($path));

        if ($this->config->domain !== null) {
            return $this->config->domain . '/' . $key;
        }

        $host = $this->config->endpointHost();

        if ($host === '') {
            throw UnableToGeneratePublicUrl::noGeneratorConfigured(
                $path,
                'the configured OBS endpoint has no host.'
            );
        }

        $scheme = $this->config->endpointScheme();

        return $this->config->pathStyle
            ? sprintf('%s://%s/%s/%s', $scheme, $host, $this->config->bucket, $key)
            : sprintf('%s://%s.%s/%s', $scheme, $this->config->bucket, $host, $key);
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        $expiresIn = max(1, min($expiresAt->getTimestamp() - time(), self::MAX_SIGNED_URL_TTL));

        try {
            return $this->createSignedUrl(
                $path,
                (string) $config->get('method', 'GET'),
                $expiresIn,
                (array) $config->get('headers', []),
            );
        } catch (RuntimeException $e) {
            throw UnableToGenerateTemporaryUrl::dueToError($path, $e);
        }
    }

    /**
     * A pre-signed URL a browser can PUT straight to.
     *
     * @param array{method?: string, headers?: array<string, string>} $options
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string
    {
        $expiresIn = max(1, min($expiresAt->getTimestamp() - time(), self::MAX_SIGNED_URL_TTL));

        return $this->createSignedUrl(
            $path,
            $options['method'] ?? 'PUT',
            $expiresIn,
            $options['headers'] ?? [],
        );
    }

    /**
     * Best URL for the configured visibility — a direct URL for a public disk,
     * otherwise a signed one.
     *
     * Unlike upstream this never issues a getObjectAcl round trip per call.
     */
    public function url(string $path): string
    {
        if ($this->config->visibility === 'public') {
            return $this->publicUrl($path, new Config());
        }

        return $this->createSignedUrl($path);
    }

    /**
     * OBS returns the object's MD5 in the ETag for single-part uploads. Multipart
     * ETags are of the form `<md5>-<parts>` and are not an MD5 of the content, so
     * they are rejected and Flysystem falls back to streaming the object.
     */
    public function checksum(string $path, Config $config): string
    {
        $algo = strtolower((string) $config->get('checksum_algo', 'md5'));

        if ($algo !== 'md5') {
            throw new ChecksumAlgoIsNotSupported('Huawei OBS only exposes MD5 checksums via ETag.');
        }

        try {
            $result = $this->withRetry(fn () => $this->client->getObjectMetadata([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));
        } catch (ObsException $e) {
            throw new UnableToProvideChecksum($this->describeObsError($e), $path, $e);
        }

        $etag = trim((string) ($result['ETag'] ?? ''), '"');

        if ($etag === '' || str_contains($etag, '-')) {
            throw new ChecksumAlgoIsNotSupported('The ETag of a multipart object is not an MD5 checksum.');
        }

        return $etag;
    }

    /**
     * @param resource|string $body
     */
    private function upload(string $path, $body, Config $config): void
    {
        $startTime = microtime(true);

        try {
            $this->checkAuthentication();

            $options = [
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
                'Body' => $body,
                'ACL' => $this->visibilityToAcl(
                    (string) $config->get('visibility', $this->config->visibility)
                ),
            ];

            $mimetype = $config->get('mimetype') ?? $config->get('ContentType');
            if ($mimetype !== null) {
                $options['ContentType'] = (string) $mimetype;
            }

            $storageClass = $config->get('StorageClass');
            if ($storageClass !== null) {
                $options['StorageClass'] = (string) $storageClass;
            }

            $metadata = $config->get('Metadata');
            if (is_array($metadata) && $metadata !== []) {
                $options['Metadata'] = $metadata;
            }

            $this->withRetry(fn () => $this->client->putObject($options));

            $this->logOperation('write', $path, microtime(true) - $startTime);
        } catch (ObsException $e) {
            $this->logError('write', $path, $e);

            throw UnableToWriteFile::atLocation($path, $this->describeObsError($e), $e);
        }
    }

    private function copyObject(string $source, string $destination, Config $config): void
    {
        $options = [
            'Bucket' => $this->config->bucket,
            'Key' => $this->getKey($destination),
            'CopySource' => $this->config->bucket . '/' . $this->getKey($source),
        ];

        $visibility = $config->get('visibility');
        if ($visibility !== null) {
            $options['ACL'] = $this->visibilityToAcl((string) $visibility);
        }

        $this->withRetry(fn () => $this->client->copyObject($options));
    }

    /**
     * @param array<int, array{Key: string}> $batch
     *
     * @return int number of objects the batch removed
     */
    private function deleteBatch(string $path, array $batch): int
    {
        $result = $this->withRetry(fn () => $this->client->deleteObjects([
            'Bucket' => $this->config->bucket,
            'Objects' => $batch,
            // Quiet mode: OBS then reports failures only, keeping the response small.
            'Quiet' => true,
        ]));

        $errors = $result['Errors'] ?? [];

        if (! empty($errors)) {
            $first = $errors[0] ?? [];

            throw UnableToDeleteDirectory::atLocation($path, sprintf(
                'Huawei OBS failed to delete %d of %d objects. First failure: key=%s code=%s message=%s',
                count($errors),
                count($batch),
                $first['Key'] ?? '?',
                $first['Code'] ?? '?',
                $first['Message'] ?? '?',
            ));
        }

        return count($batch);
    }

    /**
     * Walk every page of a ListObjects call.
     *
     * OBS only returns `NextMarker` when a `Delimiter` was supplied, so the
     * upstream `if ($nextMarker === null) break;` stopped after one page for deep
     * listings — silently losing everything past the first 1000 objects.
     * `IsTruncated` is the signal that actually works; when a truncated page
     * carries no `NextMarker`, ListObjects v1 lets us resume from the last key.
     *
     * @return Generator<int, mixed>
     */
    private function eachPage(string $prefix, ?string $delimiter): Generator
    {
        $marker = null;

        while (true) {
            $options = [
                'Bucket' => $this->config->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => ObsConfig::LIST_MAX_KEYS,
            ];

            if ($delimiter !== null) {
                $options['Delimiter'] = $delimiter;
            }

            if ($marker !== null) {
                $options['Marker'] = $marker;
            }

            $page = $this->withRetry(fn () => $this->client->listObjects($options));

            yield $page;

            if (! filter_var($page['IsTruncated'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            $next = $page['NextMarker'] ?? null;

            if ($next === null || $next === '') {
                $next = $this->lastKeyOfPage($page);
            }

            // No usable marker, or OBS handed back the one we just used.
            if ($next === null || $next === '' || $next === $marker) {
                return;
            }

            $marker = $next;
        }
    }

    /**
     * Lexicographically greatest key on a page — the resume point for
     * ListObjects v1 when OBS omits NextMarker.
     *
     * @param mixed $page
     */
    private function lastKeyOfPage($page): ?string
    {
        $last = null;

        $contents = $page['Contents'] ?? [];
        if (is_array($contents) && $contents !== []) {
            $candidate = end($contents)['Key'] ?? null;
            $last = is_string($candidate) ? $candidate : null;
        }

        $prefixes = $page['CommonPrefixes'] ?? [];
        if (is_array($prefixes) && $prefixes !== []) {
            $candidate = end($prefixes)['Prefix'] ?? null;

            if (is_string($candidate) && ($last === null || strcmp($candidate, $last) > 0)) {
                $last = $candidate;
            }
        }

        return $last;
    }

    /**
     * One getObjectMetadata call, mapped to a fully populated FileAttributes.
     */
    private function metadata(string $path, string $operation): FileAttributes
    {
        try {
            $this->checkAuthentication();

            $result = $this->withRetry(fn () => $this->client->getObjectMetadata([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getKey($path),
            ]));
        } catch (ObsException $e) {
            $this->logError($operation, $path, $e);
            $reason = $this->readFailureReason($e);

            throw match ($operation) {
                'mimeType' => UnableToRetrieveMetadata::mimeType($path, $reason, $e),
                'lastModified' => UnableToRetrieveMetadata::lastModified($path, $reason, $e),
                default => UnableToRetrieveMetadata::fileSize($path, $reason, $e),
            };
        }

        $contentType = $result['ContentType'] ?? null;
        $contentLength = $result['ContentLength'] ?? null;
        $lastModified = $result['LastModified'] ?? null;

        return new FileAttributes(
            $path,
            $contentLength === null || $contentLength === '' ? null : (int) $contentLength,
            null,
            $lastModified === null ? null : $this->toTimestamp($lastModified),
            $contentType === null || $contentType === '' ? null : (string) $contentType,
            ['etag' => isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null],
        );
    }

    /**
     * Object key with the configured prefix applied, ready to slot into a URL.
     */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function directoryPrefix(string $path): string
    {
        $key = $this->getKey($path);
        $key = trim($key, '/');

        return $key === '' ? '' : $key . '/';
    }

    private function readFailureReason(ObsException $exception): string
    {
        return $this->isNotFoundError($exception)
            ? 'Object not found in bucket ' . $this->config->bucket . '.'
            : $this->describeObsError($exception);
    }

    /**
     * @param mixed $value
     */
    private function toTimestamp($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return resource
     */
    private function bufferToStream(string $contents)
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open a temporary stream for the OBS response body.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
