<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Support;

use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builder helper for {@see MediaArchiveService} in tests.
 *
 * Mirrors the same surface that spora-core's `tests/Support/MediaArchiveTestSupport.php`
 * exposes — vendored at the plugin level so the plugin's pest suite can
 * run without depending on the host test-support tree.
 */
final class MediaArchiveTestSupport
{
    public static function buildService(
        AssetStore $assetStore,
        ?HttpClientInterface $http = null,
        ?LoggerInterface $logger = null,
        bool $promoteExternal = true,
        int $maxPromoteBytes = 100 * 1024 * 1024,
        bool $ffprobeEnabled = false,
        ?RemoteMediaFetcher $fetcher = null,
        ?MimeSniffer $sniffer = null,
        ?MetadataExtractor $metadata = null,
    ): MediaArchiveService {
        $logger ??= new NullLogger();
        $sniffer ??= new MimeSniffer();
        $metadata ??= new MetadataExtractor($logger, $ffprobeEnabled);
        $fetcher ??= new RemoteMediaFetcher(
            $http ?? new MockHttpClient([]),
            $logger,
            30,
            $maxPromoteBytes,
        );

        $resolver = new MediaArchiveUrlResolver(
            $fetcher,
            $sniffer,
            $logger,
            $promoteExternal,
            $maxPromoteBytes,
        );

        // spora-core v0.18+ refactored MediaArchiveService to take a
        // MediaArchiveIngestPipeline; the service itself no longer
        // touches the asset store / sniffer / decoder directly. Mirror
        // the host's tests/Support/MediaArchiveTestSupport.php signature.
        $pipeline = new MediaArchiveIngestPipeline(
            new MediaIngestDecoder(),
            $resolver,
            $sniffer,
            $metadata,
            $assetStore,
            self::buildConverterRegistry(),
            $logger,
        );

        return new MediaArchiveService($pipeline);
    }

    public static function buildConverterRegistry(): MediaConverterRegistry
    {
        // A minimal PSR-11 stub that materialises any class the
        // discovery list points at via `new $id()`. Tests that want
        // to exercise specific converters add them via MediaConverterDiscovery
        // BEFORE calling this helper. Optional constructor parameters
        // are left at their declared default; required parameters are
        // intentionally fatal — tests must use Mockery for those.
        $stub = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                if (!class_exists($id)) {
                    throw new RuntimeException("Not registered: {$id}");
                }
                $reflection = new ReflectionClass($id);
                $constructor = $reflection->getConstructor();
                if ($constructor === null) {
                    return $reflection->newInstance();
                }
                $args = [];
                foreach ($constructor->getParameters() as $param) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    if (\Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class === $id) {
                        $args[] = Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
                        continue;
                    }
                    throw new RuntimeException("Cannot auto-construct {$id}: parameter {$param->getName()} has no default value.");
                }
                return $reflection->newInstanceArgs($args);
            }
            public function has(string $id): bool
            {
                return class_exists($id);
            }
        };
        // Core converters are available in the application container; mirror
        // that registration in the lightweight test container.
        MediaConverterDiscovery::add(\Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter::class);
        MediaConverterDiscovery::add(\Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter::class);

        return new MediaConverterRegistry($stub);
    }

    public static function buildAuth(): AuthService
    {
        // Tests run without a real auth session; the controller's
        // canEdit() is only consulted in PATCH/refresh flows. The
        // base {@see AuthService} ctor takes a delight-im `Auth`
        // instance we don't need here, so we build a Mockery stub
        // that only surfaces the two read-only predicates the
        // controller actually consults.
        $auth = Mockery::mock(AuthService::class);
        $auth->shouldReceive('currentUserId')->andReturn(1);
        $auth->shouldReceive('isAdmin')->andReturn(true);

        return $auth;
    }
}
