<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Support;

use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
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

        // MediaArchiveService's constructor changed shape between
        // spora-core releases: v0.13.x (the locked dependency CI installs)
        // takes the collaborators directly; v0.18+ folds them into a
        // MediaArchiveIngestPipeline. Dispatch on the declared constructor
        // so this support class works against either, with no need to
        // touch the dependency pin per release — both branches construct
        // through a helper that takes a plain `string` so PHPStan can't
        // narrow the FQCN into a missing class-string on v0.13.0.
        $serviceCtor = (new ReflectionClass(MediaArchiveService::class))->getConstructor();
        $firstType = $serviceCtor?->getParameters()[0]?->getType();
        $firstTypeName = $firstType instanceof ReflectionNamedType ? $firstType->getName() : null;

        $pipelineName = 'Spora' . '\\Services\\MediaArchive\\MediaArchiveIngestPipeline';
        if (
            $firstTypeName !== null
            && $firstTypeName === $pipelineName
            && class_exists($pipelineName)
        ) {
            $pipeline = self::newInstanceViaReflection($pipelineName, [
                new MediaIngestDecoder(),
                $resolver,
                $sniffer,
                $metadata,
                $assetStore,
                self::buildConverterRegistry(),
                $logger,
            ]);

            return self::newInstanceViaReflection(MediaArchiveService::class, [$pipeline]);
        }

        return self::newInstanceViaReflection(MediaArchiveService::class, [
            $assetStore,
            $resolver,
            $sniffer,
            $metadata,
            self::buildConverterRegistry(),
            new MediaIngestDecoder(),
            $logger,
        ]);
    }

    /**
     * Construct an instance of `$class` with `$args` via ReflectionClass.
     *
     * The `$class` parameter is typed as a plain `string` (not
     * `class-string`) on purpose: the helper may receive FQCNs that
     * point at classes missing from the analysed spora-core version,
     * and PHPStan refuses to narrow `string` into a missing
     * class-string the way it would a literal class reference.
     */
    private static function newInstanceViaReflection(string $class, array $args): object
    {
        return (new ReflectionClass($class))->newInstanceArgs($args);
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
