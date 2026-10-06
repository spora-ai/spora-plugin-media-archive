<?php

declare(strict_types=1);

namespace Spora\Plugins\MediaArchive\Tests\Support;

use DI\Container;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaDerivativeService;
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
 *
 * The shape follows the core version the plugin's constraint names rather
 * than every shape that ever existed. A previous version of this helper
 * dispatched on the resolved core's constructor to survive the v0.13 →
 * v0.18 ingest-pipeline split; that dispatch is gone because the floor is
 * now `>=0.30.0`, where both the pipeline and the converter registry it
 * branched on are settled (the registry itself was deleted in core #285).
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
        ?MediaDerivativeService $derivatives = null,
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

        $derivatives ??= self::buildDerivativeService($assetStore, $logger);

        $pipeline = new MediaArchiveIngestPipeline(
            new MediaIngestDecoder(),
            $resolver,
            $sniffer,
            $metadata,
            $assetStore,
            $derivatives,
            new PrincipalService(new PrincipalResolver()),
        );

        return new MediaArchiveService($pipeline, $derivatives);
    }

    /**
     * A {@see MediaDerivativeService} wired to an empty container, standing
     * in for the DI container that resolves the real producers.
     *
     * Empty on purpose: no producer self-registers here, so the pipeline's
     * `ensureTextDerivative()` finds no claimant and returns null — the same
     * "best effort, never throws" path a `text/*` upload takes in
     * production. The plugin's own tests never assert on producer output;
     * they create derivatives explicitly through the service.
     */
    public static function buildDerivativeService(AssetStore $assetStore, ?LoggerInterface $logger = null): MediaDerivativeService
    {
        return new MediaDerivativeService(
            $assetStore,
            new PrincipalService(new PrincipalResolver()),
            new Container(),
            null,
            $logger ?? new NullLogger(),
        );
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
