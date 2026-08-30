<?php

declare(strict_types=1);

/**
 * This file is part of qianxiong/hyperf-flysystem-obs.
 *
 * Ported from mubbi/laravel-flysystem-huawei-obs (MIT) and adapted for Hyperf.
 */

namespace Hyperf\Flysystem\Obs\Exception;

use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * Base exception for the Huawei OBS adapter.
 */
class HuaweiObsException extends RuntimeException implements FilesystemException
{
}
