<?php

declare(strict_types=1);

/**
 * This file is part of qinpei/hyperf-flysystem-obs.
 *
 * Ported from mubbi/laravel-flysystem-huawei-obs (MIT) and adapted for Hyperf.
 */

namespace Hyperf\Flysystem\Obs\Exception;

use Throwable;

/**
 * Thrown when object tags could not be read.
 */
class UnableToGetObjectTags extends HuaweiObsException
{
    public static function forLocation(string $location, ?Throwable $previous = null): self
    {
        return new self("Unable to get object tags for location: {$location}", 0, $previous);
    }
}
