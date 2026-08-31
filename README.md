# qianxiong/hyperf-flysystem-obs

华为云 OBS 适配器，供 **Hyperf 官方 [`hyperf/filesystem`](https://hyperf.wiki/3.1/#/zh-cn/filesystem)** 组件使用（Flysystem v3）。

在 `config/autoload/file.php` 里配一个 `storage.obs` 驱动，之后注入 `League\Flysystem\Filesystem` 或 `Hyperf\Filesystem\FilesystemFactory` 就能读写 OBS —— 用法与官方的阿里云 OSS / S3 / 七牛 / COS 适配器完全一致。

- 不依赖 Laravel。整个依赖树里没有 `laravel/framework`，也没有任何 `illuminate/*`。
- 底层 SDK 为 [`qianxiong/huaweicloud-obs-sdk`](https://packagist.org/packages/qianxiong/huaweicloud-obs-sdk)（命名空间 `QianXiong\`），华为官方 SDK 的现代化 fork，支持 PHP 8.1–8.5。
- 实现了 Flysystem v3 的全部适配器契约，另外实现 `PublicUrlGenerator`、`TemporaryUrlGenerator`、`ChecksumProvider`，所以 `$filesystem->publicUrl()` / `temporaryUrl()` / `checksum()` 都能直接用。

---

## 安装

```bash
composer require qianxiong/huaweicloud-obs-sdk:dev-main qianxiong/hyperf-flysystem-obs
```

> **为什么要显式写上 SDK？**
> `qianxiong/huaweicloud-obs-sdk` 目前**只有 `dev-main`，没有发布任何稳定 tag**。Composer 默认的 `minimum-stability` 是 `stable`，会把它过滤掉，于是只 require 本包会直接解析失败：
>
> ```
> qianxiong/hyperf-flysystem-obs 1.0.0 requires qianxiong/huaweicloud-obs-sdk dev-main
>   -> found qianxiong/huaweicloud-obs-sdk[dev-main] but it does not match your minimum-stability.
> ```
>
> 在**根** `composer.json` 里显式 require 它，等于只给这一个包开了 dev 稳定性标记，其余依赖树仍然保持 `stable`。这比全局改 `"minimum-stability": "dev"` 干净得多（后者会放宽整棵树，虽然配上 `"prefer-stable": true` 也能用）。两种方式都已实测可解析。

## 配置

先发布官方 filesystem 配置（如果 `config/autoload/file.php` 还不存在）：

```bash
php bin/hyperf.php vendor:publish hyperf/filesystem
```

然后加上 `obs` 这一项：

```php
<?php

use function Hyperf\Support\env;

return [
    'default' => env('FILESYSTEM_DRIVER', 'obs'),
    'storage' => [
        'obs' => [
            'driver'   => \Hyperf\Flysystem\Obs\HuaweiObsAdapterFactory::class,
            'key'      => env('OBS_ACCESS_KEY_ID'),
            'secret'   => env('OBS_SECRET_ACCESS_KEY'),
            'bucket'   => env('OBS_BUCKET'),
            'endpoint' => env('OBS_ENDPOINT', 'https://obs.cn-north-4.myhuaweicloud.com'),
        ],
    ],
];
```

`key` / `secret` / `bucket` / `endpoint` 是必填项，缺任何一个都会抛出 `Hyperf\Filesystem\Exception\InvalidArgumentException`，消息里会点名是哪一项、属于哪个 storage。

### 全部可选项

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| `prefix` | `null` | 所有 key 的公共前缀，相当于把这个驱动限定在桶内某个目录下 |
| `domain` | `null` | CDN / 自定义域名。配了之后 `publicUrl()` 直接拼它 |
| `path_style` | `false` | `true` → `https://host/bucket/key`；`false`（默认，华为云常规形态）→ `https://bucket.host/key` |
| `visibility` | `private` | 写入对象的默认 ACL，同时决定 `url()` 返回直链还是签名 URL |
| `retry_attempts` | `3` | 适配器层重试次数（最小 1） |
| `retry_delay` | `1` | 首次重试前等待秒数，之后按 2 的幂退避 |
| `check_authentication` | `false` | 每个操作前先发 `headBucket` 预检（结果缓存 5 分钟）。**默认关闭**，见下文 |
| `signed_url_expires` | `3600` | 签名 URL 默认有效期（秒），OBS 上限 7 天（604800） |
| `logging_enabled` | `false` | PSR-3 日志总开关，需要装 `hyperf/logger` |
| `log_operations` | `false` | 记录每次成功操作（仅在 `logging_enabled` 为真时生效） |
| `log_errors` | `true` | 记录失败操作（仅在 `logging_enabled` 为真时生效） |
| `log_name` / `log_group` | `obs` / `default` | 传给 `LoggerFactory::get()` 的两个参数 |

以下选项原样转交给 SDK 的 `ObsClient`：`security_token`（临时凭证）、`signature`（`obs` \| `v2` \| `v4`）、`region`、`is_cname`、`ssl_verify`、`ssl.certificate_authority`、`max_retry_count`、`timeout`、`socket_timeout`、`connect_timeout`、`chunk_size`、`exception_response_mode`。

> `ssl_verify` 在本包里默认 **`true`**。SDK 自己的默认值是 `false`（不校验证书），这是个不安全的默认，所以这里改掉了。要关掉请显式写 `'ssl_verify' => false`。

## 自检

```bash
php bin/hyperf.php obs:check          # 检查 storage.obs
php bin/hyperf.php obs:check -s obs2  # 检查别的 storage 项
```

它会依次做四件事：

1. 读取并**脱敏**打印生效配置（`key` 只显示前 4 位，`secret` 全遮）。没配 `obs` 项时直接打印一段可粘贴的配置块。
2. 检查协程 curl hook（见下节），分别报告当前进程和 server worker 的 `hook_flags`。
3. 跑一遍完整往返：`resolve → write → fileExists → read → readStream → metadata → listContents → temporaryUrl → publicUrl → checksum → delete`，逐步打印耗时。
4. 无论成功失败都清理测试对象（写在 `.hyperf-obs-selfcheck/` 下）。

凭证错误时的输出形如：

```
Round trip
  ✓ resolve           19.5 ms
  ✗ write            230.6 ms

✗ Self-check failed: Unable to write file at location: .hyperf-obs-selfcheck/check-xxx.txt.
  Huawei OBS rejected the credentials (InvalidAccessKeyId). Check file.storage.*.key / secret
  (and security_token when using temporary credentials).
  bucket=my-bucket endpoint=https://obs.cn-north-4.myhuaweicloud.com.
  The OBS Access Key Id you provided does not exist in our records.
```

失败时退出码为 `1`，可以直接放进部署检查。

## 必须开启 Curl Hook

OBS SDK 通过 curl 发请求，和官方文档对阿里云 OSS / 七牛 / COS 的要求一样：**必须开启 `SWOOLE_HOOK_NATIVE_CURL`**，否则每个请求都会阻塞整个 worker。

Swoole 5 以上 `SWOOLE_HOOK_ALL` 已经包含 `SWOOLE_HOOK_NATIVE_CURL`，而 Hyperf 在未显式配置 `hook_flags` 时就是用 `SWOOLE_HOOK_ALL`，所以**默认开箱即可**。只有你自己收窄过 `hook_flags` 时才需要补上：

```php
// config/autoload/server.php
'settings' => [
    'hook_flags' => SWOOLE_HOOK_ALL | SWOOLE_HOOK_NATIVE_CURL,
],
```

`obs:check` 会明确告诉你当前是哪种情况。

## 用法

默认驱动就是 `obs` 时，直接注入 `Filesystem`：

```php
use League\Flysystem\Filesystem;

class FooController
{
    #[Inject]
    protected Filesystem $filesystem;

    public function upload()
    {
        $this->filesystem->write('path/to/file.txt', 'contents');
        $this->filesystem->writeStream('path/to/big.bin', $resource);

        $contents = $this->filesystem->read('path/to/file.txt');
        $stream   = $this->filesystem->readStream('path/to/big.bin');

        $url = $this->filesystem->publicUrl('path/to/file.txt');
        $tmp = $this->filesystem->temporaryUrl('path/to/file.txt', new DateTimeImmutable('+10 minutes'));
    }
}
```

多驱动共存时用工厂：

```php
use Hyperf\Filesystem\FilesystemFactory;

$obs = $container->get(FilesystemFactory::class)->get('obs');
$obs->write('path/to/file.txt', 'contents');
```

### OBS 专有能力

Flysystem v3 的 `Filesystem` 只转发标准契约里的方法，所以签名 URL、表单直传、对象标签、归档取回这些 OBS 专有能力要先拿到底层适配器：

```php
use Hyperf\Flysystem\Obs\HuaweiObsAdapter;

/** @var HuaweiObsAdapter $adapter */
$adapter = $filesystem->getAdapter();

// 任意 HTTP 方法的签名 URL
$adapter->createSignedUrl('path/to/file.txt', 'GET', 600);
$adapter->createSignedUrl('path/to/file.txt', 'PUT', 600, ['Content-Type' => 'text/plain']);

// 浏览器表单直传签名（第二个参数是表单字段，会写进 POST policy 条件）
$adapter->createPostSignature('uploads/', ['content-type' => 'text/plain', 'x-obs-acl' => 'public-read'], 600);

// 对象标签
$adapter->setObjectTags('path/to/file.txt', ['env' => 'prod', 'owner' => 'team-a']);
$adapter->getObjectTags('path/to/file.txt');
$adapter->deleteObjectTags('path/to/file.txt');

// 归档存储取回，保持可读 3 天
$adapter->restoreObject('archive/old.zip', 3);

// 轮换临时凭证，不用重建适配器
$adapter->refreshCredentials($newKey, $newSecret, $newToken);

// 需要更底层时
$adapter->getClient();   // QianXiong\ObsClient
$adapter->getConfig();   // Hyperf\Flysystem\Obs\ObsConfig
```

这些方法替代了上游 Laravel 包用 11 个 `FilesystemAdapter::macro()` 提供的能力。

### 注入自定义 Guzzle handler 或 SDK 日志

SDK 的 `ObsClient` 还认 `handler`（Guzzle handler）和 `logger`（PSR-3 实例）两个键，但它们放不进配置文件，所以本包不从配置里转交。需要时自己建客户端：

```php
use Hyperf\Flysystem\Obs\HuaweiObsAdapter;
use Hyperf\Flysystem\Obs\ObsConfig;
use League\Flysystem\Filesystem;
use QianXiong\ObsClient;

$config = ObsConfig::fromArray($options);
$client = new ObsClient($config->toClientConfig() + ['handler' => $myHandler]);

$filesystem = new Filesystem(new HuaweiObsAdapter($client, $config, $psrLogger));
```

## 关于 `public_url`

Flysystem 的 `Filesystem::publicUrl()` 会**先**看配置里的 `public_url`：配了就用它自己的 `PrefixPublicUrlGenerator`，**完全绕过本适配器**。而 `Hyperf\Filesystem\FilesystemFactory` 会把整个 storage 配置数组当成 Flysystem 的 `Config` 传进去，所以你在 storage 项里写的 `public_url` 是会生效的。

这有个坑：绕过适配器意味着 **`prefix` 不会被应用**，key 也不会按段做 `rawurlencode`。要 CDN / 自定义域名请用本包的 **`domain`** 选项，它走的是适配器内部，前缀和编码都正确：

```php
'domain' => 'https://cdn.example.com',   // 推荐
// 'public_url' => 'https://cdn.example.com',   // Flysystem 的，会绕过 prefix 和编码
```

`temporaryUrl()` 没有这个问题，它只认适配器。

## 与上游 Laravel 包（`mubbi/laravel-flysystem-huawei-obs`）的行为差异

移植过程中修掉了几个确认存在的缺陷，行为因此有变：

| 差异 | 说明 |
| --- | --- |
| **深度遍历 / 递归删除不再丢数据** | 上游用 `NextMarker` 是否为空判断分页结束，但 OBS 的 ListObjects v1 **只在设置了 `Delimiter` 时才返回 `NextMarker`**。`$deep = true` 时不设 `Delimiter`，于是上游永远在第一页就停下 —— 超过 1000 个对象的目录会静默漏掉后面全部。这里改为以 `IsTruncated` 为准，`NextMarker` 缺失时回落到本页最后一个 key，并防御 marker 不推进导致的死循环。 |
| **删掉了 100 页的静默截断** | 上游有个 `$maxIterations = 100`，超过 10 万对象就悄悄返回不完整结果。已移除。 |
| **`deleteDirectory()` 按 1000 分批** | OBS 单次 `deleteObjects` 上限 1000 个对象，上游一次性全塞进去，大目录直接失败。现在流式攒批、满 1000 就发一次，并检查每批返回的 `Errors[]`。 |
| **`publicUrl()` 支持 virtual-hosted 与自定义域名** | 上游只会拼 path-style。现在按 `domain` → `path_style` → virtual-hosted（华为云默认）三条分支，key 按段 `rawurlencode`。 |
| **`url()` 不再发 ACL 请求** | 上游每次调用都要发一次 `getObjectAcl` 才决定返回直链还是签名 URL。现在直接依据配置的 `visibility`，省掉一次网络往返。 |
| **`listContents()` 的 `visibility` 是 `null` 而不是假的 `private`** | OBS 的 `listObjects` 响应里 `Contents[]` 根本没有 `Grants` 字段，上游却拿 `$object['Grants'] ?? []` 去推断，结果每个文件都被报成 `private`。现在报 `null`（语义是"未知"），需要真实可见性请单独调 `visibility($path)`。 |
| **`readStream()` 零拷贝** | 上游先把整个对象读成 PHP 字符串再写进流。现在直接包装 SDK 返回的 PSR-7 流（它本来就是溢出到磁盘的 `php://temp`）。 |
| **`check_authentication` 默认关闭** | 上游每个操作前都可能发 `headBucket` 预检。现在默认关闭，友好报错下沉到各操作的异常映射：凭证类错误提示查 `key`/`secret`/`security_token`，桶类错误提示查 `bucket`/`endpoint`/`region`，消息里始终带上 bucket、endpoint 和 OBS 错误码。需要预检就设 `'check_authentication' => true`。 |
| **凭证 / 桶 / 404 类错误不再重试** | 这些错误不会自己好转，重试只是白等。仅对可恢复错误按 2 的幂退避重试。 |
| **构造签名** | 上游是 24 个参数的构造函数，这里换成 `ObsConfig` 值对象。 |
| **`Exception\` 而不是 `Exceptions\`** | 对齐 Hyperf 的 `Hyperf\Filesystem\Exception\` 命名惯例。 |
| **日志走 PSR-3** | 上游用 Laravel 的 `logger()` 助手，在 Hyperf 下永远静默。现在走 PSR-3，`hyperf/logger` 可选。 |

## 已知问题

- **`hyperf/command` 在 PHP 8.5 上的 bug（不影响本包，但值得知道）**：`InteractsWithIO::parseVerbosity()` 做了 `isset($map[null])`，PHP 8.5 把 null 数组下标标记为 deprecated，而 Hyperf 的 `ErrorExceptionHandler` 会把 deprecation 升级成致命错误 —— 于是 `line()` / `info()` / `error()` / `warn()` / `comment()` 在 PHP 8.5 + hyperf/command 3.2.0 上全都不能用。`obs:check` 因此直接写 `$this->output->writeln()`，绕开了这个问题。
- **SDK 日志的常数开销**：`qianxiong` fork 的 `ObsLog::commonLog()` 不再像华为原版那样用 `if (ObsLog::$log)` 短路，即使没配日志也会照样格式化消息并调用 `debug_backtrace()`。每次 OBS 操作有 6 处这样的调用。实测（PHP 8.5）每次操作多花 **约 3.2 微秒**，相对几十毫秒的网络往返可以忽略，但这是一处相对原版的退化。本包无法从外部关掉它（`ObsLog::$logger` 是 private static）。

## 环境要求

- PHP >= 8.1（实测 8.5.9）
- `hyperf/filesystem` ^3.1（实测 3.2.0）
- `league/flysystem` ^3.0（实测 3.35.3）
- 扩展：`ext-json`，加上 SDK 需要的 `ext-libxml` / `ext-simplexml`，协程下另需 `ext-swoole` 并开启 native curl hook

## License

MIT
