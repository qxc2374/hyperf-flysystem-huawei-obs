<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 */

namespace Hyperf\Flysystem\Obs;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Flysystem\Obs\Command\ObsSelfCheckCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                HuaweiObsAdapterFactory::class => HuaweiObsAdapterFactory::class,
            ],
            // hyperf/command is a suggest, not a require.
            'commands' => class_exists(HyperfCommand::class) ? [ObsSelfCheckCommand::class] : [],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            // Deliberately no 'publish': hyperf/filesystem already publishes
            // config/autoload/file.php under the id "config". Publishing it again
            // would have the two packages overwrite each other. `obs:check`
            // prints the block to paste instead.
        ];
    }
}
