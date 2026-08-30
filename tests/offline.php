<?php

declare(strict_types=1);

/**
 * Offline verification for qinpei/hyperf-flysystem-obs — no credentials, no network.
 * Scratch file, deleted after the run.
 */

require __DIR__ . '/../vendor/autoload.php';

use Hyperf\Filesystem\Exception\InvalidArgumentException;
use Hyperf\Flysystem\Obs\HuaweiObsAdapter;
use Hyperf\Flysystem\Obs\ObsConfig;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use QianXiong\Internal\Common\Model;
use QianXiong\ObsClient;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            ++$passed;
            echo "  ok    {$label}\n";

            return;
        }

        ++$failed;
        echo "  FAIL  {$label}: {$result}\n";
    } catch (Throwable $e) {
        ++$failed;
        echo '  FAIL  ' . $label . ': ' . get_class($e) . ' — ' . $e->getMessage() . "\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** Records every call and replays canned responses. */
final class StubObsClient extends ObsClient
{
    /** @var array<int, array{method: string, args: array}> */
    public array $calls = [];

    /** @var array<string, array<int, mixed>> */
    private array $queues = [];

    public function __construct(array $queues = [])
    {
        $this->queues = $queues;
        parent::__construct([
            'key' => 'AK-stub',
            'secret' => 'SK-stub',
            'endpoint' => 'https://obs.cn-north-4.myhuaweicloud.com',
        ]);
    }

    public function __call($originMethod, $args)
    {
        $this->calls[] = ['method' => $originMethod, 'args' => $args[0] ?? []];

        $queue = $this->queues[$originMethod] ?? null;

        if ($queue === null) {
            return new Model([]);
        }

        // The last canned response repeats once the queue runs dry.
        $next = count($queue) > 1 ? array_shift($this->queues[$originMethod]) : $queue[0];

        return $next instanceof Model ? $next : new Model((array) $next);
    }

    public function countCalls(string $method): int
    {
        return count(array_filter($this->calls, fn ($call) => $call['method'] === $method));
    }

    /** @return array<int, array> */
    public function argsFor(string $method): array
    {
        return array_values(array_map(
            fn ($call) => $call['args'],
            array_filter($this->calls, fn ($call) => $call['method'] === $method)
        ));
    }
}

$baseOptions = [
    'driver' => \Hyperf\Flysystem\Obs\HuaweiObsAdapterFactory::class,
    'key' => 'HPUAB1234567890ABCDE',
    'secret' => 'secret-access-key-value',
    'bucket' => 'my-bucket',
    'endpoint' => 'https://obs.cn-north-4.myhuaweicloud.com',
];

// ---------------------------------------------------------------------------
section('1. Contracts');

check('implements FilesystemAdapter', fn () => is_a(HuaweiObsAdapter::class, FilesystemAdapter::class, true) ?: 'no');
check('implements PublicUrlGenerator', fn () => is_a(HuaweiObsAdapter::class, PublicUrlGenerator::class, true) ?: 'no');
check('implements TemporaryUrlGenerator', fn () => is_a(HuaweiObsAdapter::class, TemporaryUrlGenerator::class, true) ?: 'no');
check('implements ChecksumProvider', fn () => is_a(HuaweiObsAdapter::class, ChecksumProvider::class, true) ?: 'no');
check('factory implements AdapterFactoryInterface', fn () => is_a(
    \Hyperf\Flysystem\Obs\HuaweiObsAdapterFactory::class,
    \Hyperf\Filesystem\Contract\AdapterFactoryInterface::class,
    true
) ?: 'no');

// ---------------------------------------------------------------------------
section('2. ObsConfig');

foreach (['key', 'secret', 'bucket', 'endpoint'] as $required) {
    check("missing \"{$required}\" throws InvalidArgumentException naming the key", function () use ($baseOptions, $required) {
        $options = $baseOptions;
        unset($options[$required]);

        try {
            ObsConfig::fromArray($options, 'obs');
        } catch (InvalidArgumentException $e) {
            return str_contains($e->getMessage(), $required) && str_contains($e->getMessage(), 'file.storage.obs')
                ? true
                : 'unhelpful message: ' . $e->getMessage();
        }

        return 'no exception thrown';
    });
}

check('empty string counts as missing', function () use ($baseOptions) {
    try {
        ObsConfig::fromArray(array_merge($baseOptions, ['bucket' => '']), 'obs');
    } catch (InvalidArgumentException) {
        return true;
    }

    return 'no exception thrown';
});

check('malformed endpoint throws', function () use ($baseOptions) {
    try {
        ObsConfig::fromArray(array_merge($baseOptions, ['endpoint' => 'http://:::bad']), 'obs');
    } catch (InvalidArgumentException $e) {
        return str_contains($e->getMessage(), 'endpoint') ? true : $e->getMessage();
    }

    return 'no exception thrown';
});

check('scheme-less endpoint gets https://', function () use ($baseOptions) {
    $config = ObsConfig::fromArray(array_merge($baseOptions, [
        'endpoint' => 'obs.cn-north-4.myhuaweicloud.com',
    ]), 'obs');

    return $config->endpoint === 'https://obs.cn-north-4.myhuaweicloud.com'
        ? true
        : 'got ' . $config->endpoint;
});

check('trailing slash is trimmed', function () use ($baseOptions) {
    $config = ObsConfig::fromArray(array_merge($baseOptions, [
        'endpoint' => 'https://obs.cn-north-4.myhuaweicloud.com/',
    ]), 'obs');

    return $config->endpoint === 'https://obs.cn-north-4.myhuaweicloud.com' ? true : 'got ' . $config->endpoint;
});

check('toClientConfig only carries keys ObsClient understands', function () use ($baseOptions) {
    $config = ObsConfig::fromArray(array_merge($baseOptions, [
        // adapter-level — must NOT be forwarded
        'driver' => 'x', 'prefix' => 'p', 'domain' => 'https://cdn.example.com',
        'visibility' => 'public', 'retry_attempts' => 5, 'retry_delay' => 2,
        'check_authentication' => true, 'signed_url_expires' => 60,
        'logging_enabled' => true, 'log_operations' => true, 'log_errors' => false,
        // SDK-level — must be forwarded
        'region' => 'cn-north-4', 'security_token' => 'tok', 'signature' => 'obs',
        'path_style' => true, 'max_retry_count' => 4, 'timeout' => 120,
        'socket_timeout' => 30, 'connect_timeout' => 10, 'chunk_size' => 4096,
        'is_cname' => true, 'exception_response_mode' => 'true',
    ]), 'obs');

    $allowed = [
        'key', 'secret', 'endpoint', 'security_token', 'signature', 'path_style', 'region',
        'ssl_verify', 'ssl.certificate_authority', 'max_retry_count', 'timeout',
        'socket_timeout', 'connect_timeout', 'chunk_size', 'exception_response_mode', 'is_cname',
    ];

    $clientConfig = $config->toClientConfig();
    $unexpected = array_diff(array_keys($clientConfig), $allowed);

    if ($unexpected !== []) {
        return 'leaked adapter-level keys: ' . implode(', ', $unexpected);
    }

    foreach (['region' => 'cn-north-4', 'security_token' => 'tok', 'max_retry_count' => 4, 'timeout' => 120] as $key => $value) {
        if (($clientConfig[$key] ?? null) !== $value) {
            return "{$key} not forwarded";
        }
    }

    return $clientConfig['path_style'] === true && $clientConfig['is_cname'] === true
        ? true
        : 'bool casts wrong';
});

check('ssl_verify defaults to true (SDK default is false)', function () use ($baseOptions) {
    return ObsConfig::fromArray($baseOptions, 'obs')->toClientConfig()['ssl_verify'] === true
        ? true
        : 'not defaulted';
});

check('explicit ssl_verify=false is respected', function () use ($baseOptions) {
    return ObsConfig::fromArray(array_merge($baseOptions, ['ssl_verify' => false]), 'obs')
        ->toClientConfig()['ssl_verify'] === false ? true : 'overridden';
});

check('prefix round-trips through applyPrefix/stripPrefix', function () use ($baseOptions) {
    $config = ObsConfig::fromArray(array_merge($baseOptions, ['prefix' => 'uploads/']), 'obs');

    if ($config->applyPrefix('a/b.txt') !== 'uploads/a/b.txt') {
        return 'applyPrefix gave ' . $config->applyPrefix('a/b.txt');
    }

    return $config->stripPrefix('uploads/a/b.txt') === 'a/b.txt'
        ? true
        : 'stripPrefix gave ' . $config->stripPrefix('uploads/a/b.txt');
});

check('endpointHost keeps a custom port', function () {
    $config = ObsConfig::fromArray([
        'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        'endpoint' => 'http://127.0.0.1:9000',
    ], 'obs');

    return $config->endpointHost() === '127.0.0.1:9000' && $config->endpointScheme() === 'http'
        ? true
        : 'got ' . $config->endpointScheme() . '://' . $config->endpointHost();
});

// ---------------------------------------------------------------------------
section('3. publicUrl — three branches');

$adapterWith = function (array $extra) use ($baseOptions): HuaweiObsAdapter {
    $config = ObsConfig::fromArray(array_merge($baseOptions, $extra), 'obs');
    $reflection = new ReflectionClass(HuaweiObsAdapter::class);
    $adapter = $reflection->newInstanceWithoutConstructor();

    foreach (['client' => new StubObsClient(), 'config' => $config, 'logger' => null] as $property => $value) {
        $prop = new ReflectionProperty(\Hyperf\Flysystem\Obs\AbstractHuaweiObsAdapter::class, $property);
        $prop->setValue($adapter, $value);
    }

    return $adapter;
};

check('virtual-hosted style is the default', function () use ($adapterWith) {
    $url = $adapterWith([])->publicUrl('a/b.txt', new Config());

    return $url === 'https://my-bucket.obs.cn-north-4.myhuaweicloud.com/a/b.txt' ? true : 'got ' . $url;
});

check('path_style=true puts the bucket in the path', function () use ($adapterWith) {
    $url = $adapterWith(['path_style' => true])->publicUrl('a/b.txt', new Config());

    return $url === 'https://obs.cn-north-4.myhuaweicloud.com/my-bucket/a/b.txt' ? true : 'got ' . $url;
});

check('domain wins over both', function () use ($adapterWith) {
    $url = $adapterWith([
        'domain' => 'https://cdn.example.com',
        'path_style' => true,
    ])->publicUrl('a/b.txt', new Config());

    return $url === 'https://cdn.example.com/a/b.txt' ? true : 'got ' . $url;
});

check('prefix is applied to the URL', function () use ($adapterWith) {
    $url = $adapterWith(['prefix' => 'uploads'])->publicUrl('a/b.txt', new Config());

    return $url === 'https://my-bucket.obs.cn-north-4.myhuaweicloud.com/uploads/a/b.txt' ? true : 'got ' . $url;
});

check('spaces and CJK are percent-encoded, slashes are not', function () use ($adapterWith) {
    $url = $adapterWith([])->publicUrl('图片 目录/a b.txt', new Config());
    $expected = 'https://my-bucket.obs.cn-north-4.myhuaweicloud.com/'
        . rawurlencode('图片 目录') . '/' . rawurlencode('a b.txt');

    return $url === $expected ? true : "got {$url}";
});

check('url() honours visibility=public', function () use ($adapterWith) {
    $url = $adapterWith(['visibility' => 'public'])->url('a/b.txt');

    return str_starts_with($url, 'https://my-bucket.obs.') && ! str_contains($url, 'Signature')
        ? true
        : 'got ' . $url;
});

// ---------------------------------------------------------------------------
section('4. createSignedUrl (local signing, no network)');

check('signed URL carries AccessKeyId, Expires and Signature', function () use ($baseOptions) {
    $config = ObsConfig::fromArray($baseOptions, 'obs');
    $adapter = new HuaweiObsAdapter($config->createClient(), $config);
    $url = $adapter->createSignedUrl('a/b.txt', 'GET', 900);

    foreach (['AccessKeyId', 'Expires', 'Signature', 'my-bucket'] as $needle) {
        if (! str_contains($url, $needle)) {
            return "missing {$needle} in {$url}";
        }
    }

    return true;
});

check('temporaryUrl() goes through the same signer', function () use ($baseOptions) {
    $config = ObsConfig::fromArray($baseOptions, 'obs');
    $adapter = new HuaweiObsAdapter($config->createClient(), $config);
    $url = $adapter->temporaryUrl('a/b.txt', new DateTimeImmutable('+10 minutes'), new Config());

    return str_contains($url, 'Signature') ? true : 'got ' . $url;
});

check('temporaryUploadUrl() signs a PUT', function () use ($baseOptions) {
    $config = ObsConfig::fromArray($baseOptions, 'obs');
    $adapter = new HuaweiObsAdapter($config->createClient(), $config);
    $url = $adapter->temporaryUploadUrl('a/b.txt', new DateTimeImmutable('+10 minutes'));

    return str_contains($url, 'Signature') ? true : 'got ' . $url;
});

// ---------------------------------------------------------------------------
section('5. Pagination — the upstream data-loss bug');

$listPage = function (array $keys, bool $truncated, ?string $nextMarker = null): Model {
    $data = [
        'Contents' => array_map(fn ($key) => [
            'Key' => $key, 'Size' => 10, 'LastModified' => '2026-01-01T00:00:00Z',
            'ETag' => '"abc"', 'StorageClass' => 'STANDARD',
        ], $keys),
        'CommonPrefixes' => [],
        'IsTruncated' => $truncated,
    ];

    if ($nextMarker !== null) {
        $data['NextMarker'] = $nextMarker;
    }

    return new Model($data);
};

$stubAdapter = function (StubObsClient $client) use ($baseOptions): HuaweiObsAdapter {
    return new HuaweiObsAdapter($client, ObsConfig::fromArray($baseOptions, 'obs'));
};

check('deep listing follows IsTruncated when NextMarker is absent (upstream lost page 2+)', function () use ($listPage, $stubAdapter) {
    // Exactly the shape OBS returns for a deep (no Delimiter) listing:
    // IsTruncated=true but NO NextMarker.
    $client = new StubObsClient(['listObjects' => [
        $listPage(['dir/a.txt', 'dir/b.txt'], true),
        $listPage(['dir/c.txt', 'dir/d.txt'], true),
        $listPage(['dir/e.txt'], false),
    ]]);

    $paths = [];
    foreach ($stubAdapter($client)->listContents('dir', true) as $item) {
        $paths[] = $item->path();
    }

    $expected = ['dir/a.txt', 'dir/b.txt', 'dir/c.txt', 'dir/d.txt', 'dir/e.txt'];

    if ($paths !== $expected) {
        return 'got ' . implode(',', $paths);
    }

    if ($client->countCalls('listObjects') !== 3) {
        return 'expected 3 listObjects calls, made ' . $client->countCalls('listObjects');
    }

    // Page 2 must resume from the last key of page 1.
    $args = $client->argsFor('listObjects');

    if (($args[1]['Marker'] ?? null) !== 'dir/b.txt') {
        return 'page 2 marker was ' . var_export($args[1]['Marker'] ?? null, true);
    }

    return isset($args[0]['Delimiter']) ? 'deep listing must not send a Delimiter' : true;
});

check('shallow listing sends Delimiter and follows NextMarker', function () use ($stubAdapter) {
    $client = new StubObsClient(['listObjects' => [
        new Model([
            'Contents' => [['Key' => 'dir/a.txt', 'Size' => 1]],
            'CommonPrefixes' => [['Prefix' => 'dir/sub/']],
            'IsTruncated' => true,
            'NextMarker' => 'dir/marker-1',
        ]),
        new Model([
            'Contents' => [['Key' => 'dir/b.txt', 'Size' => 2]],
            'CommonPrefixes' => [],
            'IsTruncated' => false,
        ]),
    ]]);

    $files = [];
    $dirs = [];

    foreach ($stubAdapter($client)->listContents('dir', false) as $item) {
        $item instanceof DirectoryAttributes ? $dirs[] = $item->path() : $files[] = $item->path();
    }

    if ($files !== ['dir/a.txt', 'dir/b.txt']) {
        return 'files: ' . implode(',', $files);
    }

    if ($dirs !== ['dir/sub']) {
        return 'dirs: ' . implode(',', $dirs);
    }

    $args = $client->argsFor('listObjects');

    if (($args[0]['Delimiter'] ?? null) !== '/') {
        return 'shallow listing must send Delimiter=/';
    }

    return ($args[1]['Marker'] ?? null) === 'dir/marker-1' ? true : 'NextMarker not honoured';
});

check('a repeated marker breaks the loop instead of spinning forever', function () use ($stubAdapter) {
    // Pathological server: always truncated, always the same marker.
    $client = new StubObsClient(['listObjects' => [
        new Model([
            'Contents' => [['Key' => 'dir/a.txt', 'Size' => 1]],
            'IsTruncated' => true,
            'NextMarker' => 'dir/a.txt',
        ]),
    ]]);

    $count = 0;
    foreach ($stubAdapter($client)->listContents('dir', true) as $item) {
        ++$count;

        if ($count > 10) {
            return 'infinite loop';
        }
    }

    return $client->countCalls('listObjects') === 2 ? true : 'made ' . $client->countCalls('listObjects') . ' calls';
});

check('listContents reports visibility as null, not a fabricated "private"', function () use ($listPage, $stubAdapter) {
    $client = new StubObsClient(['listObjects' => [$listPage(['dir/a.txt'], false)]]);

    foreach ($stubAdapter($client)->listContents('dir', true) as $item) {
        /** @var FileAttributes $item */
        if ($item->visibility() !== null) {
            return 'visibility was ' . var_export($item->visibility(), true);
        }

        return $item->fileSize() === 10 && $item->lastModified() === strtotime('2026-01-01T00:00:00Z')
            ? true
            : 'size/mtime not mapped';
    }

    return 'nothing yielded';
});

check('the directory marker object is not yielded as a child', function () use ($stubAdapter) {
    $client = new StubObsClient(['listObjects' => [
        new Model([
            'Contents' => [['Key' => 'dir/', 'Size' => 0], ['Key' => 'dir/a.txt', 'Size' => 1]],
            'IsTruncated' => false,
        ]),
    ]]);

    $paths = [];
    foreach ($stubAdapter($client)->listContents('dir', true) as $item) {
        $paths[] = $item->path();
    }

    return $paths === ['dir/a.txt'] ? true : 'got ' . implode(',', $paths);
});

// ---------------------------------------------------------------------------
section('6. deleteDirectory — 1000-object batching');

check('2500 objects are removed in 3 deleteObjects calls of 1000/1000/500', function () use ($stubAdapter) {
    $pages = [];

    // 3 listing pages: 1000, 1000, 500.
    foreach ([[0, 1000, true], [1000, 1000, true], [2000, 500, false]] as [$offset, $size, $truncated]) {
        $contents = [];

        for ($i = 0; $i < $size; ++$i) {
            $contents[] = ['Key' => sprintf('dir/%05d.txt', $offset + $i), 'Size' => 1];
        }

        $pages[] = new Model(['Contents' => $contents, 'IsTruncated' => $truncated]);
    }

    $client = new StubObsClient(['listObjects' => $pages, 'deleteObjects' => [new Model(['Errors' => []])]]);
    $stubAdapter($client)->deleteDirectory('dir');

    if ($client->countCalls('deleteObjects') !== 3) {
        return 'expected 3 deleteObjects calls, made ' . $client->countCalls('deleteObjects');
    }

    $sizes = array_map(fn ($args) => count($args['Objects']), $client->argsFor('deleteObjects'));

    if ($sizes !== [1000, 1000, 500]) {
        return 'batch sizes were ' . implode(',', $sizes);
    }

    $first = $client->argsFor('deleteObjects')[0];

    return ($first['Quiet'] ?? null) === true ? true : 'Quiet mode not set';
});

check('a partial-failure response raises UnableToDeleteDirectory naming the key', function () use ($stubAdapter) {
    $client = new StubObsClient([
        'listObjects' => [new Model(['Contents' => [['Key' => 'dir/a.txt']], 'IsTruncated' => false])],
        'deleteObjects' => [new Model(['Errors' => [['Key' => 'dir/a.txt', 'Code' => 'AccessDenied', 'Message' => 'nope']]])],
    ]);

    try {
        $stubAdapter($client)->deleteDirectory('dir');
    } catch (\League\Flysystem\UnableToDeleteDirectory $e) {
        return str_contains($e->getMessage(), 'dir/a.txt') && str_contains($e->getMessage(), 'AccessDenied')
            ? true
            : 'unhelpful message: ' . $e->getMessage();
    }

    return 'no exception thrown';
});

check('deleting an empty directory issues no deleteObjects call', function () use ($stubAdapter) {
    $client = new StubObsClient(['listObjects' => [new Model(['Contents' => [], 'IsTruncated' => false])]]);
    $stubAdapter($client)->deleteDirectory('dir');

    return $client->countCalls('deleteObjects') === 0 ? true : 'made a pointless call';
});

// ---------------------------------------------------------------------------
section('7. Write / read / metadata mapping');

check('write defaults the ACL from the disk visibility', function () use ($baseOptions) {
    $client = new StubObsClient();
    $config = ObsConfig::fromArray(array_merge($baseOptions, ['visibility' => 'public']), 'obs');
    (new HuaweiObsAdapter($client, $config))->write('a.txt', 'x', new Config());

    return ($client->argsFor('putObject')[0]['ACL'] ?? null) === 'public-read'
        ? true
        : 'ACL was ' . var_export($client->argsFor('putObject')[0]['ACL'] ?? null, true);
});

check('a per-write visibility overrides the disk default', function () use ($baseOptions) {
    $client = new StubObsClient();
    $config = ObsConfig::fromArray(array_merge($baseOptions, ['visibility' => 'public']), 'obs');
    (new HuaweiObsAdapter($client, $config))->write('a.txt', 'x', new Config(['visibility' => 'private']));

    return ($client->argsFor('putObject')[0]['ACL'] ?? null) === 'private' ? true : 'not overridden';
});

check('readStream wraps the PSR-7 body instead of buffering it', function () use ($stubAdapter) {
    $body = \GuzzleHttp\Psr7\Utils::streamFor('hello obs');
    $client = new StubObsClient(['getObject' => [new Model(['Body' => $body])]]);

    $stream = $stubAdapter($client)->readStream('a.txt');

    if (! is_resource($stream)) {
        return 'not a resource';
    }

    $read = stream_get_contents($stream);
    fclose($stream);

    return $read === 'hello obs' ? true : 'got ' . var_export($read, true);
});

check('metadata maps ContentLength / ContentType / LastModified', function () use ($stubAdapter) {
    $client = new StubObsClient(['getObjectMetadata' => [new Model([
        'ContentLength' => '1234',
        'ContentType' => 'image/png',
        'LastModified' => 'Wed, 01 Jan 2026 00:00:00 GMT',
        'ETag' => '"d41d8cd98f00b204e9800998ecf8427e"',
    ])]]);

    $adapter = $stubAdapter($client);

    if ($adapter->fileSize('a.png')->fileSize() !== 1234) {
        return 'size wrong';
    }

    if ($adapter->mimeType('a.png')->mimeType() !== 'image/png') {
        return 'mime wrong';
    }

    return $adapter->lastModified('a.png')->lastModified() === strtotime('Wed, 01 Jan 2026 00:00:00 GMT')
        ? true
        : 'mtime wrong';
});

check('checksum returns the unquoted ETag', function () use ($stubAdapter) {
    $client = new StubObsClient(['getObjectMetadata' => [new Model([
        'ETag' => '"d41d8cd98f00b204e9800998ecf8427e"',
    ])]]);

    return $stubAdapter($client)->checksum('a.txt', new Config()) === 'd41d8cd98f00b204e9800998ecf8427e'
        ? true
        : 'got ' . $stubAdapter($client)->checksum('a.txt', new Config());
});

check('a multipart ETag is rejected so Flysystem falls back to streaming', function () use ($stubAdapter) {
    $client = new StubObsClient(['getObjectMetadata' => [new Model(['ETag' => '"abc-3"'])]]);

    try {
        $stubAdapter($client)->checksum('a.txt', new Config());
    } catch (\League\Flysystem\ChecksumAlgoIsNotSupported) {
        return true;
    }

    return 'multipart ETag accepted as an MD5';
});

check('a non-md5 algo is rejected', function () use ($stubAdapter) {
    try {
        $stubAdapter(new StubObsClient())->checksum('a.txt', new Config(['checksum_algo' => 'sha256']));
    } catch (\League\Flysystem\ChecksumAlgoIsNotSupported) {
        return true;
    }

    return 'sha256 accepted';
});

check('visibility() reads Grants and detects the AllUsers grant', function () use ($stubAdapter) {
    $client = new StubObsClient(['getObjectAcl' => [new Model(['Grants' => [
        ['Grantee' => ['URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'], 'Permission' => 'READ'],
    ]])]]);

    if ($stubAdapter($client)->visibility('a.txt')->visibility() !== 'public') {
        return 'AllUsers READ not detected as public';
    }

    $private = new StubObsClient(['getObjectAcl' => [new Model(['Grants' => [
        ['Grantee' => ['ID' => 'owner-id'], 'Permission' => 'FULL_CONTROL'],
    ]])]]);

    return $stubAdapter($private)->visibility('a.txt')->visibility() === 'private' ? true : 'owner grant read as public';
});

check('copy/move issue copyObject, and move also deletes the source', function () use ($stubAdapter) {
    $client = new StubObsClient();
    $adapter = $stubAdapter($client);

    $adapter->copy('a.txt', 'b.txt', new Config());

    if ($client->countCalls('copyObject') !== 1 || $client->countCalls('deleteObject') !== 0) {
        return 'copy touched the source';
    }

    $adapter->move('a.txt', 'c.txt', new Config());

    if ($client->countCalls('deleteObject') !== 1) {
        return 'move did not delete the source';
    }

    $args = $client->argsFor('copyObject')[0];

    return ($args['CopySource'] ?? null) === 'my-bucket/a.txt' ? true : 'CopySource was ' . ($args['CopySource'] ?? 'null');
});

// ---------------------------------------------------------------------------
section('8. Error mapping (check_authentication default off)');

check('check_authentication defaults to false — no headBucket pre-flight', function () use ($baseOptions, $stubAdapter) {
    $client = new StubObsClient(['getObjectMetadata' => [new Model(['ContentLength' => 1])]]);
    $stubAdapter($client)->fileExists('a.txt');

    return $client->countCalls('headBucket') === 0 ? true : 'headBucket was called anyway';
});

$obsError = function (string $code, int $status = 403): \QianXiong\ObsException {
    $exception = new \QianXiong\ObsException('boom');
    $exception->setExceptionCode($code);
    $exception->setResponse(new \GuzzleHttp\Psr7\Response($status));

    return $exception;
};

/** Throws on the first call to any operation. */
final class ThrowingObsClient extends ObsClient
{
    public int $calls = 0;

    public function __construct(private Throwable $error)
    {
        parent::__construct(['key' => 'k', 'secret' => 's', 'endpoint' => 'https://obs.example.com']);
    }

    public function __call($originMethod, $args)
    {
        ++$this->calls;

        throw $this->error;
    }
}

check('NoSuchKey makes fileExists() return false, not throw', function () use ($baseOptions, $obsError) {
    $client = new ThrowingObsClient($obsError('NoSuchKey', 404));
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray($baseOptions, 'obs'));

    return $adapter->fileExists('missing.txt') === false ? true : 'returned true';
});

check('a bodyless 404 is still recognised as not-found', function () use ($baseOptions) {
    $exception = new \QianXiong\ObsException('');
    $exception->setResponse(new \GuzzleHttp\Psr7\Response(404));

    $client = new ThrowingObsClient($exception);
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray($baseOptions, 'obs'));

    return $adapter->fileExists('missing.txt') === false ? true : 'returned true';
});

check('deleting a missing object is a no-op, per Flysystem semantics', function () use ($baseOptions, $obsError) {
    $client = new ThrowingObsClient($obsError('NoSuchKey', 404));
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray($baseOptions, 'obs'));
    $adapter->delete('missing.txt');

    return true;
});

check('not-found is not retried', function () use ($baseOptions, $obsError) {
    $client = new ThrowingObsClient($obsError('NoSuchKey', 404));
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray(
        array_merge($baseOptions, ['retry_attempts' => 3]),
        'obs'
    ));
    $adapter->fileExists('missing.txt');

    return $client->calls === 1 ? true : "retried {$client->calls} times";
});

check('InvalidAccessKeyId is not retried and names key/secret', function () use ($baseOptions, $obsError) {
    $client = new ThrowingObsClient($obsError('InvalidAccessKeyId'));
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray(
        array_merge($baseOptions, ['retry_attempts' => 3]),
        'obs'
    ));

    try {
        $adapter->write('a.txt', 'x', new Config());
    } catch (\League\Flysystem\UnableToWriteFile $e) {
        if ($client->calls !== 1) {
            return "retried {$client->calls} times";
        }

        $message = $e->getMessage();

        return str_contains($message, 'key') && str_contains($message, 'secret')
            && str_contains($message, 'my-bucket') && str_contains($message, 'InvalidAccessKeyId')
            ? true
            : 'unhelpful message: ' . $message;
    }

    return 'no exception thrown';
});

check('NoSuchBucket names bucket/endpoint/region', function () use ($baseOptions, $obsError) {
    $client = new ThrowingObsClient($obsError('NoSuchBucket', 404));
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray($baseOptions, 'obs'));

    try {
        $adapter->write('a.txt', 'x', new Config());
    } catch (\League\Flysystem\UnableToWriteFile $e) {
        $message = $e->getMessage();

        return str_contains($message, 'bucket') && str_contains($message, 'endpoint')
            && str_contains($message, 'region') && str_contains($message, 'obs.cn-north-4')
            ? true
            : 'unhelpful message: ' . $message;
    }

    return 'no exception thrown';
});

check('a transient 500 IS retried up to retry_attempts', function () use ($baseOptions) {
    $exception = new \QianXiong\ObsException('internal error');
    $exception->setExceptionCode('InternalError');
    $exception->setResponse(new \GuzzleHttp\Psr7\Response(500));

    $client = new ThrowingObsClient($exception);
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray(
        array_merge($baseOptions, ['retry_attempts' => 3, 'retry_delay' => 0]),
        'obs'
    ));

    try {
        $adapter->write('a.txt', 'x', new Config());
    } catch (\League\Flysystem\UnableToWriteFile) {
        return $client->calls === 3 ? true : "tried {$client->calls} times, expected 3";
    }

    return 'no exception thrown';
});

check('read() of a missing object throws UnableToReadFile mentioning the bucket', function () use ($baseOptions, $obsError) {
    $client = new ThrowingObsClient($obsError('NoSuchKey', 404));
    $adapter = new HuaweiObsAdapter($client, ObsConfig::fromArray($baseOptions, 'obs'));

    try {
        $adapter->read('missing.txt');
    } catch (\League\Flysystem\UnableToReadFile $e) {
        return str_contains($e->getMessage(), 'my-bucket') ? true : 'message: ' . $e->getMessage();
    }

    return 'no exception thrown';
});

// ---------------------------------------------------------------------------
section('9. Adapter caching in the Hyperf factory');

check('the same options array yields the same adapter instance', function () use ($baseOptions) {
    $container = new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException('nothing registered');
        }

        public function has(string $id): bool
        {
            return false;
        }
    };

    $factory = new \Hyperf\Flysystem\Obs\HuaweiObsAdapterFactory($container);

    $first = $factory->make($baseOptions);
    $second = $factory->make($baseOptions);
    $third = $factory->make(array_merge($baseOptions, ['bucket' => 'other-bucket']));

    if ($first !== $second) {
        return 'identical options built two adapters';
    }

    return $first !== $third ? true : 'different options shared an adapter';
});

// ---------------------------------------------------------------------------
echo "\n";
echo $failed === 0
    ? "All {$passed} checks passed.\n"
    : "{$passed} passed, {$failed} FAILED.\n";

exit($failed === 0 ? 0 : 1);
