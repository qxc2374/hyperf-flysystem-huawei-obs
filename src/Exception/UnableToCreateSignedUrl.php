<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 *
 * Ported from mubbi/laravel-flysystem-huawei-obs (MIT) and adapted for Hyperf.
 */

namespace Hyperf\Flysystem\Obs\Exception;

use Throwable;

/**
 * Thrown when a signed URL could not be created.
 */
class UnableToCreateSignedUrl extends HuaweiObsException
{
    public static function forLocation(string $location, ?Throwable $previous = null): self
    {
        return new self("Unable to create signed URL for location: {$location}", 0, $previous);
    }
}
