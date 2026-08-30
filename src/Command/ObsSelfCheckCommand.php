<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 */

namespace Hyperf\Flysystem\Obs\Command;

use DateTimeImmutable;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\Flysystem\Obs\HuaweiObsAdapterFactory;
use Hyperf\Flysystem\Obs\ObsConfig;
use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function Hyperf\Support\swoole_hook_flags;

/**
 * `php bin/hyperf.php obs:check` — proves the whole chain end to end:
 * configuration, coroutine hooks, and a real write/read/list/delete round trip.
 */
class ObsSelfCheckCommand extends HyperfCommand
{
    protected ?string $name = 'obs:check';

    protected string $description = 'Verify the Huawei Cloud OBS filesystem configuration with a live round trip.';

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $storage = (string) ($this->option('storage') ?: 'obs');

        $this->out("<options=bold>Huawei OBS self-check</> — file.storage.{$storage}");
        $this->out();

        $options = $this->readStorageConfig($storage);

        if ($options === null) {
            return self::FAILURE;
        }

        if (! $this->reportConfig($storage, $options)) {
            return self::FAILURE;
        }

        $this->reportCoroutineHooks();

        return $this->roundTrip($storage);
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'storage',
            's',
            InputOption::VALUE_REQUIRED,
            'The file.storage.* entry to check.',
            'obs'
        );
    }

    /**
     * hyperf/command's `line()` / `info()` / `error()` all route through
     * `parseVerbosity()`, which does `isset($map[null])` — a deprecation that
     * PHP 8.5 raises and Hyperf's ErrorExceptionHandler turns into a fatal.
     * Writing straight to the output sidesteps it.
     */
    private function out(string $message = ''): void
    {
        $this->output?->writeln($message);
    }

    private function fail(string $message): void
    {
        $this->out("<fg=red;options=bold>✗</> {$message}");
    }

    private function warnLine(string $message): void
    {
        $this->out("<fg=yellow;options=bold>!</> {$message}");
    }

    /**
     * @return null|array<string, mixed> null when the entry is missing
     */
    private function readStorageConfig(string $storage): ?array
    {
        $options = $this->container->get(ConfigInterface::class)->get('file.storage.' . $storage);

        if (! is_array($options) || $options === []) {
            $this->fail("config/autoload/file.php has no \"storage.{$storage}\" entry.");
            $this->out();
            $this->out('Add this block (run `php bin/hyperf.php vendor:publish hyperf/filesystem` first if the file does not exist):');
            $this->out();
            $this->out($this->sampleConfig($storage));

            return null;
        }

        $driver = $options['driver'] ?? null;

        if ($driver !== HuaweiObsAdapterFactory::class) {
            $this->warnLine(sprintf(
                'storage.%s uses driver "%s", not %s. Checking it anyway.',
                $storage,
                is_string($driver) ? $driver : gettype($driver),
                HuaweiObsAdapterFactory::class
            ));
            $this->out();
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function reportConfig(string $storage, array $options): bool
    {
        try {
            $config = ObsConfig::fromArray($options, $storage);
        } catch (Throwable $e) {
            $this->fail('Configuration is invalid: ' . $e->getMessage());

            return false;
        }

        $this->out('<options=bold>Configuration</>');
        $this->table(['Option', 'Value'], [
            ['key', $this->mask($config->key)],
            ['secret', $config->secret === '' ? '(empty)' : '****************'],
            ['bucket', $config->bucket],
            ['endpoint', $config->endpoint],
            ['prefix', $config->prefix ?? '(none)'],
            ['domain', $config->domain ?? '(none)'],
            ['path_style', $config->pathStyle ? 'true' : 'false'],
            ['visibility', $config->visibility],
            ['retry_attempts', (string) $config->retryAttempts],
            ['check_authentication', $config->checkAuthentication ? 'true' : 'false'],
            ['signed_url_expires', $config->signedUrlExpires . 's'],
        ]);
        $this->out();

        return true;
    }

    /**
     * The OBS SDK talks to the network through curl. Without
     * SWOOLE_HOOK_NATIVE_CURL every request blocks the whole worker — the same
     * requirement the official docs state for the Aliyun OSS / Qiniu / COS
     * adapters.
     */
    private function reportCoroutineHooks(): void
    {
        $this->out('<options=bold>Coroutine hooks</>');

        if (! defined('SWOOLE_HOOK_NATIVE_CURL')) {
            $this->warnLine('This PHP build has no SWOOLE_HOOK_NATIVE_CURL constant — is ext-swoole loaded?');
            $this->out();

            return;
        }

        // What this command itself runs with.
        $this->reportHookFlags('current runtime', swoole_hook_flags(), SWOOLE_HOOK_NATIVE_CURL);

        // What the HTTP server workers will run with — the one that matters in production.
        $serverFlags = $this->container->get(ConfigInterface::class)->get('server.settings.hook_flags');

        if ($serverFlags === null) {
            $this->out('  server workers: <fg=gray>server.settings.hook_flags not set — Hyperf falls back to'
                . ' SWOOLE_HOOK_ALL, which includes native curl on Swoole 5+.</>');
        } else {
            $this->reportHookFlags('server workers', (int) $serverFlags, SWOOLE_HOOK_NATIVE_CURL);
        }

        $this->out();
    }

    private function reportHookFlags(string $label, int $flags, int $nativeCurl): void
    {
        if (($flags & $nativeCurl) === $nativeCurl) {
            $this->out(sprintf('  <fg=green>✓</> %-15s native curl hook enabled (flags=%d)', $label, $flags));

            return;
        }

        $this->warnLine(sprintf(
            '%s: native curl hook DISABLED (flags=%d). The OBS SDK uses curl, so every request'
            . ' would block the worker. Add SWOOLE_HOOK_NATIVE_CURL to server.settings.hook_flags.',
            $label,
            $flags
        ));
    }

    private function roundTrip(string $storage): int
    {
        $this->out('<options=bold>Round trip</>');

        $path = '.hyperf-obs-selfcheck/' . uniqid('check-', true) . '.txt';
        $payload = 'hyperf-flysystem-obs self-check ' . date('c');
        $filesystem = null;

        try {
            $filesystem = $this->step('resolve', fn (): Filesystem => $this->container
                ->get(FilesystemFactory::class)
                ->get($storage));

            $this->step('write', function () use ($filesystem, $path, $payload): string {
                $filesystem->write($path, $payload);

                return $path;
            });

            $this->step('fileExists', function () use ($filesystem, $path): string {
                if (! $filesystem->fileExists($path)) {
                    throw new RuntimeException('the object was written but fileExists() says it is not there.');
                }

                return 'true';
            });

            $this->step('read', function () use ($filesystem, $path, $payload): string {
                $read = $filesystem->read($path);

                if ($read !== $payload) {
                    throw new RuntimeException('the object came back with different contents.');
                }

                return strlen($read) . ' bytes, contents match';
            });

            $this->step('readStream', function () use ($filesystem, $path, $payload): string {
                $stream = $filesystem->readStream($path);
                $read = stream_get_contents($stream);
                fclose($stream);

                if ($read !== $payload) {
                    throw new RuntimeException('the streamed object came back with different contents.');
                }

                return 'contents match';
            });

            $this->step('metadata', fn (): string => sprintf(
                'size=%d mime=%s mtime=%s',
                $filesystem->fileSize($path),
                $filesystem->mimeType($path),
                date('c', $filesystem->lastModified($path)),
            ));

            $this->step('listContents', function () use ($filesystem, $path): string {
                $directory = dirname($path);
                $found = false;
                $count = 0;

                /** @var StorageAttributes $item */
                foreach ($filesystem->listContents($directory, false) as $item) {
                    ++$count;
                    $found = $found || $item->path() === $path;
                }

                if (! $found) {
                    throw new RuntimeException("the object is not in the listing of {$directory} ({$count} entries seen).");
                }

                return "{$count} entries, test object present";
            });

            $this->step('temporaryUrl', fn (): string => $this->summariseUrl(
                $filesystem->temporaryUrl($path, new DateTimeImmutable('+10 minutes'))
            ));

            $this->step('publicUrl', fn (): string => $this->summariseUrl($filesystem->publicUrl($path)));

            $this->step('checksum', fn (): string => $filesystem->checksum($path));

            $this->step('delete', function () use ($filesystem, $path): string {
                $filesystem->delete($path);

                return $filesystem->fileExists($path) ? 'deleted but still listed?!' : 'gone';
            });
        } catch (Throwable $e) {
            $this->out();
            $this->fail('Self-check failed: ' . $e->getMessage());

            for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
                $this->out('  <fg=gray>caused by ' . get_class($previous) . ': ' . $previous->getMessage() . '</>');
            }

            $this->cleanUp($filesystem, $path);

            return self::FAILURE;
        }

        $this->cleanUp($filesystem, $path);

        $this->out();
        $this->out('<fg=green;options=bold>OBS is reachable and the adapter works end to end.</>');

        return self::SUCCESS;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function step(string $label, callable $operation)
    {
        $startTime = microtime(true);

        try {
            $result = $operation();
        } catch (Throwable $e) {
            $this->out(sprintf('  <fg=red>✗</> %-14s %7.1f ms', $label, (microtime(true) - $startTime) * 1000));

            throw $e;
        }

        $this->out(sprintf(
            '  <fg=green>✓</> %-14s %7.1f ms  <fg=gray>%s</>',
            $label,
            (microtime(true) - $startTime) * 1000,
            is_string($result) ? $result : ''
        ));

        return $result;
    }

    /**
     * Best-effort removal of the test object; never masks the original failure.
     */
    private function cleanUp(?Filesystem $filesystem, string $path): void
    {
        if ($filesystem === null) {
            return;
        }

        try {
            $filesystem->delete($path);
        } catch (Throwable) {
            // Nothing useful to do — the object may never have been created.
        }
    }

    /**
     * Signed URLs carry the signature; show enough to recognise, not enough to use.
     */
    private function summariseUrl(string $url): string
    {
        $parts = parse_url($url);
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '?') . ($parts['path'] ?? '');

        return isset($parts['query']) ? $base . '?' . substr($parts['query'], 0, 24) . '...' : $base;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }

        return strlen($value) <= 4 ? '****' : substr($value, 0, 4) . str_repeat('*', 12);
    }

    private function sampleConfig(string $storage): string
    {
        return <<<PHP
            'storage' => [
                '{$storage}' => [
                    'driver'   => \\Hyperf\\Flysystem\\Obs\\HuaweiObsAdapterFactory::class,
                    'key'      => env('OBS_ACCESS_KEY_ID'),
                    'secret'   => env('OBS_SECRET_ACCESS_KEY'),
                    'bucket'   => env('OBS_BUCKET'),
                    'endpoint' => env('OBS_ENDPOINT', 'https://obs.cn-north-4.myhuaweicloud.com'),
                ],
            ],
            PHP;
    }
}
