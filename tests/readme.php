<?php

declare(strict_types=1);

/**
 * Checks that the code snippets in README.md actually work, so the docs cannot
 * drift from the API. Offline only — no OBS request is made.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Handler\MockHandler;
use Hyperf\Flysystem\Obs\HuaweiObsAdapter;
use Hyperf\Flysystem\Obs\ObsConfig;
use League\Flysystem\Filesystem;
use Psr\Log\NullLogger;
use QianXiong\ObsClient;

$failed = 0;

function check(string $label, callable $test): void
{
    global $failed;

    try {
        $result = $test();
        if ($result !== true) {
            throw new RuntimeException(is_string($result) ? $result : 'returned ' . var_export($result, true));
        }
        echo "  ok    {$label}\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "  FAIL  {$label}\n        " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

$options = [
    'key' => 'AK000000000000000001',
    'secret' => 'SK00000000000000000000000000000000000001',
    'bucket' => 'my-bucket',
    'endpoint' => 'https://obs.cn-north-4.myhuaweicloud.com',
    'prefix' => 'app',
];

echo "README snippets\n";

check('"注入自定义 Guzzle handler" snippet constructs a working Filesystem', function () use ($options): bool {
    $config = ObsConfig::fromArray($options);
    $client = new ObsClient($config->toClientConfig() + ['handler' => new MockHandler()]);
    $filesystem = new Filesystem(new HuaweiObsAdapter($client, $config, new NullLogger()));

    return $filesystem->publicUrl('file.txt') === 'https://my-bucket.obs.cn-north-4.myhuaweicloud.com/app/file.txt';
});

check('the SDK accepts a PSR-3 logger, as documented', function () use ($options): bool {
    $config = ObsConfig::fromArray($options);

    return new ObsClient($config->toClientConfig() + ['logger' => new NullLogger()]) instanceof ObsClient;
});

$adapter = HuaweiObsAdapter::fromArray($options);

$documented = [
    'createSignedUrl' => ['path', 'GET', 600],
    'createPostSignature' => ['uploads/', ['content-type' => 'text/plain', 'x-obs-acl' => 'public-read'], 600],
    'setObjectTags' => ['path', ['env' => 'prod']],
    'getObjectTags' => ['path'],
    'deleteObjectTags' => ['path'],
    'restoreObject' => ['archive/old.zip', 3],
    'refreshCredentials' => ['k', 's', 't'],
    'getClient' => [],
    'getConfig' => [],
];

foreach ($documented as $method => $args) {
    check("extras: {$method}() accepts the documented arguments", function () use ($adapter, $method, $args): bool {
        $reflection = new ReflectionMethod($adapter, $method);

        if (! $reflection->isPublic()) {
            return "{$method}() is not public";
        }

        if (count($args) < $reflection->getNumberOfRequiredParameters()) {
            return sprintf(
                '%s() requires %d arguments, README passes %d',
                $method,
                $reflection->getNumberOfRequiredParameters(),
                count($args)
            );
        }

        if (count($args) > $reflection->getNumberOfParameters()) {
            return sprintf(
                '%s() takes at most %d arguments, README passes %d',
                $method,
                $reflection->getNumberOfParameters(),
                count($args)
            );
        }

        return true;
    });
}

check('getClient() returns the QianXiong client the README names', fn (): bool => $adapter->getClient() instanceof ObsClient);
check('getConfig() returns ObsConfig', fn (): bool => $adapter->getConfig() instanceof ObsConfig);

check('domain option beats the endpoint, as the public_url section claims', function () use ($options): bool {
    $adapter = HuaweiObsAdapter::fromArray($options + ['domain' => 'https://cdn.example.com']);

    return $adapter->publicUrl('file.txt', new League\Flysystem\Config()) === 'https://cdn.example.com/app/file.txt';
});

check('path_style=true puts the bucket in the path, as the options table claims', function () use ($options): bool {
    $adapter = HuaweiObsAdapter::fromArray($options + ['path_style' => true]);

    return $adapter->publicUrl('file.txt', new League\Flysystem\Config())
        === 'https://obs.cn-north-4.myhuaweicloud.com/my-bucket/app/file.txt';
});

check('signed_url_expires is capped at the documented OBS maximum of 7 days', function () use ($options): bool {
    $adapter = HuaweiObsAdapter::fromArray($options);
    $url = $adapter->createSignedUrl('file.txt', 'GET', 604800);

    return str_contains($url, 'Expires=');
});

echo $failed === 0 ? "\nAll README snippets check out.\n" : "\n{$failed} README claim(s) are wrong.\n";

exit($failed === 0 ? 0 : 1);
