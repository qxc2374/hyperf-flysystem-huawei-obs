<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 */

namespace Hyperf\Flysystem\Obs;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Filesystem\FilesystemFactory;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Builds the adapter that `HuaweiObsAdapter::class` resolves to.
 *
 * `HuaweiObsAdapter` cannot be autowired: its constructor needs an already
 * configured `QianXiong\ObsClient`, and the DI container would otherwise build
 * that client with an empty config ("key and secret must be provided"). The
 * package therefore registers this invoker in its own ConfigProvider, so an
 * application can type-hint the adapter without adding anything to
 * `config/autoload/dependencies.php`.
 *
 * Resolution mirrors `Hyperf\Filesystem\FilesystemInvoker`: the adapter of the
 * `file.default` filesystem is used when that disk is OBS-backed, otherwise the
 * first `file.storage.*` entry whose driver is `HuaweiObsAdapterFactory`. Going
 * through `FilesystemFactory::getAdapter()` reuses the very adapter instance the
 * Flysystem disk was built with, instead of creating a second ObsClient.
 */
class HuaweiObsAdapterInvoker
{
    public function __invoke(ContainerInterface $container): HuaweiObsAdapter
    {
        $fileConfig = $container->get(ConfigInterface::class)->get('file', []);
        $fileConfig = is_array($fileConfig) ? $fileConfig : [];
        $storages = isset($fileConfig['storage']) && is_array($fileConfig['storage']) ? $fileConfig['storage'] : [];

        foreach ($this->candidateStorages($fileConfig, $storages) as $name) {
            $adapter = $container->get(FilesystemFactory::class)->getAdapter($fileConfig, $name);
            if ($adapter instanceof HuaweiObsAdapter) {
                return $adapter;
            }
        }

        throw new RuntimeException(sprintf(
            'No Huawei Cloud OBS filesystem is configured. Point `file.default` (or one `file.storage.*` entry) at driver %s,'
            . ' or bind %s explicitly in config/autoload/dependencies.php.',
            HuaweiObsAdapterFactory::class,
            HuaweiObsAdapter::class
        ));
    }

    /**
     * The default disk first, so a type-hint matches the disk that
     * `League\Flysystem\Filesystem` resolves to; then every OBS-backed storage
     * in configuration order, so a `file.default` of `local` next to an `obs`
     * entry still resolves.
     *
     * @param array<string, mixed> $fileConfig the whole `file` config array
     * @param array<string, mixed> $storages the `file.storage` sub-array
     * @return array<int, string>
     */
    protected function candidateStorages(array $fileConfig, array $storages): array
    {
        $default = $fileConfig['default'] ?? null;
        $candidates = [];
        if (is_string($default) && $this->isObsStorage($storages, $default)) {
            $candidates[] = $default;
        }

        foreach ($storages as $name => $storage) {
            if (is_string($name) && $this->isObsStorage($storages, $name)) {
                $candidates[] = $name;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<string, mixed> $storages the `file.storage` sub-array
     */
    protected function isObsStorage(array $storages, string $name): bool
    {
        $storage = $storages[$name] ?? null;

        return is_array($storage) && ($storage['driver'] ?? null) === HuaweiObsAdapterFactory::class;
    }
}
