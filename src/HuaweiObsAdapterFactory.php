<?php

declare(strict_types=1);

/**
 * This file is part of qinpei/hyperf-flysystem-obs.
 */

namespace Hyperf\Flysystem\Obs;

use Hyperf\Filesystem\Contract\AdapterFactoryInterface;
use Hyperf\Logger\LoggerFactory;
use League\Flysystem\FilesystemAdapter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The `driver` you point a `file.storage.*` entry at.
 *
 * `Hyperf\Filesystem\FilesystemFactory::get()` resolves this from the container
 * and calls `make()` on every call, so adapters are cached per config array —
 * otherwise each call would build a fresh ObsClient along with a fresh Guzzle
 * client and curl multi handle.
 */
class HuaweiObsAdapterFactory implements AdapterFactoryInterface
{
    /**
     * @var array<string, HuaweiObsAdapter>
     */
    protected array $adapters = [];

    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * @param array<string, mixed> $options one `file.storage.*` entry
     */
    public function make(array $options): FilesystemAdapter
    {
        $cacheKey = $this->cacheKey($options);

        if ($cacheKey === null) {
            return $this->create($options);
        }

        // A concurrent miss is harmless: the loser is garbage collected, and
        // Guzzle's CurlMultiHandler closes its own handle on destruction.
        return $this->adapters[$cacheKey] ??= $this->create($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function create(array $options): HuaweiObsAdapter
    {
        return HuaweiObsAdapter::fromArray(
            $options,
            $this->resolveLogger($options),
            $this->storageName($options),
        );
    }

    /**
     * Null when the entry cannot be hashed — a closure or resource in the config,
     * for instance a hand-built Guzzle handler. Skipping the cache costs a client
     * per call, which beats handing back an adapter built from a different entry.
     *
     * @param array<string, mixed> $options
     */
    protected function cacheKey(array $options): ?string
    {
        try {
            return md5(serialize($options));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * PSR-3 logger, when hyperf/logger is installed. The package works without it.
     *
     * @param array<string, mixed> $options
     */
    protected function resolveLogger(array $options): ?LoggerInterface
    {
        if (! (bool) ($options['logging_enabled'] ?? false)) {
            return null;
        }

        if (! class_exists(LoggerFactory::class) || ! $this->container->has(LoggerFactory::class)) {
            return null;
        }

        try {
            return $this->container->get(LoggerFactory::class)->get(
                (string) ($options['log_name'] ?? 'obs'),
                (string) ($options['log_group'] ?? 'default'),
            );
        } catch (Throwable) {
            // A missing log group must never take down file storage.
            return null;
        }
    }

    /**
     * Only used to name the offending key in configuration errors.
     *
     * @param array<string, mixed> $options
     */
    protected function storageName(array $options): string
    {
        $name = $options['name'] ?? $options['storage_name'] ?? 'obs';

        return is_string($name) && $name !== '' ? $name : 'obs';
    }
}
