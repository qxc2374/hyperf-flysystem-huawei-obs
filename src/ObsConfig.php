<?php

declare(strict_types=1);

/**
 * This file is part of qinpei/hyperf-flysystem-obs.
 *
 * Replaces the 24-parameter constructor of the upstream Laravel package with a
 * validated, immutable value object.
 */

namespace Hyperf\Flysystem\Obs;

use Hyperf\Filesystem\Exception\InvalidArgumentException;
use QianXiong\ObsClient;

/**
 * Normalised configuration for one `storage.*` entry of `config/autoload/file.php`.
 *
 * Splits the flat config array into two groups:
 *  - adapter-level behaviour (prefix, domain, retries, logging, ...)
 *  - options `QianXiong\ObsClient` actually understands, see {@see self::toClientConfig()}
 */
final class ObsConfig
{
    /**
     * OBS caps `deleteObjects` at 1000 objects per request.
     */
    public const DELETE_BATCH_SIZE = 1000;

    /**
     * OBS caps `listObjects` at 1000 keys per request.
     */
    public const LIST_MAX_KEYS = 1000;

    /**
     * Config keys forwarded verbatim to the SDK, mapped to their cast.
     *
     * Verified against `QianXiong\ObsClient::__construct()`. The SDK also reads
     * `handler` (a Guzzle handler) and `logger` (a PSR-3 instance), but neither
     * belongs in a config file, so they are not exposed here — build the client
     * yourself and pass it to {@see HuaweiObsAdapter::__construct()} instead.
     * Every other key is ignored by the SDK, `http_client` included.
     *
     * @var array<string, string>
     */
    private const CLIENT_OPTIONS = [
        'security_token' => 'string',
        'signature' => 'string',
        'path_style' => 'bool',
        'region' => 'string',
        'ssl_verify' => 'bool',
        'ssl.certificate_authority' => 'string',
        'max_retry_count' => 'int',
        'timeout' => 'int',
        'socket_timeout' => 'int',
        'connect_timeout' => 'int',
        'chunk_size' => 'int',
        'exception_response_mode' => 'string',
        'is_cname' => 'bool',
    ];

    /**
     * @param array<string, mixed> $clientOptions optional options understood by ObsClient
     */
    private function __construct(
        public readonly string $key,
        public readonly string $secret,
        public readonly string $bucket,
        public readonly string $endpoint,
        public readonly ?string $prefix,
        public readonly ?string $domain,
        public readonly bool $pathStyle,
        public readonly string $visibility,
        public readonly int $retryAttempts,
        public readonly int $retryDelay,
        public readonly bool $checkAuthentication,
        public readonly int $signedUrlExpires,
        public readonly bool $loggingEnabled,
        public readonly bool $logOperations,
        public readonly bool $logErrors,
        private readonly array $clientOptions,
    ) {
    }

    /**
     * Build from a `storage.<name>` config array.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException when a required option is missing or the endpoint is malformed
     */
    public static function fromArray(array $options, string $name = 'obs'): self
    {
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $required) {
            if (! isset($options[$required]) || (string) $options[$required] === '') {
                throw new InvalidArgumentException(sprintf(
                    'file.storage.%s is missing the required "%s" option for the Huawei OBS driver.',
                    $name,
                    $required
                ));
            }
        }

        $endpoint = self::normaliseEndpoint((string) $options['endpoint']);
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf(
                'file.storage.%s has an invalid "endpoint" for the Huawei OBS driver: %s',
                $name,
                (string) $options['endpoint']
            ));
        }

        return new self(
            key: (string) $options['key'],
            secret: (string) $options['secret'],
            bucket: (string) $options['bucket'],
            endpoint: $endpoint,
            prefix: self::optionalString($options, 'prefix'),
            domain: self::optionalString($options, 'domain'),
            pathStyle: (bool) ($options['path_style'] ?? false),
            visibility: self::normaliseVisibility($options['visibility'] ?? 'private'),
            retryAttempts: max(1, (int) ($options['retry_attempts'] ?? 3)),
            retryDelay: max(0, (int) ($options['retry_delay'] ?? 1)),
            checkAuthentication: (bool) ($options['check_authentication'] ?? false),
            signedUrlExpires: max(1, (int) ($options['signed_url_expires'] ?? 3600)),
            loggingEnabled: (bool) ($options['logging_enabled'] ?? false),
            logOperations: (bool) ($options['log_operations'] ?? false),
            logErrors: (bool) ($options['log_errors'] ?? true),
            clientOptions: self::extractClientOptions($options),
        );
    }

    /**
     * The config array handed to `new ObsClient(...)`.
     *
     * @return array<string, mixed>
     */
    public function toClientConfig(): array
    {
        return array_merge([
            'key' => $this->key,
            'secret' => $this->secret,
            'endpoint' => $this->endpoint,
        ], $this->clientOptions);
    }

    public function createClient(): ObsClient
    {
        return new ObsClient($this->toClientConfig());
    }

    /**
     * Prepend the object key with the configured prefix.
     */
    public function applyPrefix(string $path): string
    {
        $key = ltrim($path, '/');

        if ($this->prefix !== null) {
            $key = ltrim($this->prefix . '/' . $key, '/');
        }

        return $key;
    }

    /**
     * Strip the configured prefix from an OBS object key.
     */
    public function stripPrefix(string $key): string
    {
        if ($this->prefix !== null) {
            $stripped = preg_replace('/^' . preg_quote(trim($this->prefix, '/') . '/', '/') . '/', '', ltrim($key, '/'));
            $key = $stripped ?? '';
        }

        return ltrim($key, '/');
    }

    /**
     * Scheme + host of the endpoint, without a trailing slash.
     */
    public function endpointScheme(): string
    {
        return parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
    }

    public function endpointHost(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST) ?: '';
        $port = parse_url($this->endpoint, PHP_URL_PORT);

        return $port === null ? $host : $host . ':' . $port;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function extractClientOptions(array $options): array
    {
        $client = [];

        foreach (self::CLIENT_OPTIONS as $option => $cast) {
            if (! array_key_exists($option, $options) || $options[$option] === null) {
                continue;
            }

            $client[$option] = match ($cast) {
                'bool' => (bool) $options[$option],
                'int' => (int) $options[$option],
                default => (string) $options[$option],
            };
        }

        // The SDK defaults ssl_verify to false; verifying certificates is the safer default.
        $client['ssl_verify'] ??= true;

        return $client;
    }

    private static function normaliseEndpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');

        if (! preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }

        return $endpoint;
    }

    private static function normaliseVisibility(mixed $visibility): string
    {
        return match ((string) $visibility) {
            'public', 'public-read' => 'public',
            default => 'private',
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function optionalString(array $options, string $key): ?string
    {
        if (! isset($options[$key])) {
            return null;
        }

        $value = trim((string) $options[$key], '/');

        return $value === '' ? null : $value;
    }
}
