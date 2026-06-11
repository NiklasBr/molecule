<?php

use App\Service\FeedProcessor;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

it('parses atom feed correctly', function () {
    $atomContent = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>Test Feed</title>
    <entry>
        <title>Test Entry</title>
        <summary>Test Summary</summary>
        <link href="http://example.com/test"/>
        <updated>2023-10-27T10:00:00Z</updated>
        <id>urn:uuid:12345</id>
    </entry>
</feed>
XML;

    $mockResponse = new MockResponse($atomContent);
    $httpClient = new MockHttpClient($mockResponse);

    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('get')->andReturnUsing(function ($key, $callback) {
        $item = Mockery::mock(ItemInterface::class);
        $item->shouldReceive('expiresAfter')->andReturnSelf();
        return $callback($item);
    });
    $cache->shouldReceive('delete')->andReturn(true);

    $feedsConfig = sys_get_temp_dir() . '/feeds.yaml';
    file_put_contents($feedsConfig, "feeds: ['http://example.com/feed']");

    $outputFile = sys_get_temp_dir() . '/output.atom';

    $processor = new FeedProcessor($httpClient, $cache, $feedsConfig, $outputFile);
    $processor->processFeeds();

    expect(file_exists($outputFile))->toBeTrue();
    $outputContent = file_get_contents($outputFile);
    expect($outputContent)->toContain('Test Entry');
    expect($outputContent)->toContain('Test Summary');
    expect($outputContent)->toContain('http://example.com/test');

    unlink($feedsConfig);
    unlink($outputFile);
});

it('combines multiple feeds and sorts items by date', function () {
    $feed1Content = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <entry>
        <title>Oldest Entry</title>
        <summary>Oldest</summary>
        <link href="http://example.com/oldest"/>
        <updated>2023-10-01T10:00:00Z</updated>
        <id>id-1</id>
    </entry>
    <entry>
        <title>Newest Entry</title>
        <summary>Newest</summary>
        <link href="http://example.com/newest"/>
        <updated>2023-10-30T10:00:00Z</updated>
        <id>id-2</id>
    </entry>
</feed>
XML;

    $feed2Content = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <entry>
        <title>Middle Entry</title>
        <summary>Middle</summary>
        <link href="http://example.com/middle"/>
        <updated>2023-10-15T10:00:00Z</updated>
        <id>id-3</id>
    </entry>
</feed>
XML;

    $mockResponse1 = new MockResponse($feed1Content);
    $mockResponse2 = new MockResponse($feed2Content);
    $httpClient = new MockHttpClient([$mockResponse1, $mockResponse2]);

    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('get')->andReturnUsing(function ($key, $callback) {
        $item = Mockery::mock(ItemInterface::class);
        $item->shouldReceive('expiresAfter')->andReturnSelf();
        return $callback($item);
    });
    $cache->shouldReceive('delete')->andReturn(true);

    $feedsConfig = sys_get_temp_dir() . '/feeds_multi.yaml';
    file_put_contents($feedsConfig, "feeds: ['http://example.com/feed1', 'http://example.com/feed2']");

    $outputFile = sys_get_temp_dir() . '/output_multi.atom';

    $processor = new FeedProcessor($httpClient, $cache, $feedsConfig, $outputFile);
    $processor->processFeeds();

    expect(file_exists($outputFile))->toBeTrue();
    $outputContent = file_get_contents($outputFile);

    // Verify order: Newest (Oct 30) -> Middle (Oct 15) -> Oldest (Oct 1)
    $newestPos = strpos($outputContent, 'Newest Entry');
    $middlePos = strpos($outputContent, 'Middle Entry');
    $oldestPos = strpos($outputContent, 'Oldest Entry');

    expect($newestPos)->toBeLessThan($middlePos);
    expect($middlePos)->toBeLessThan($oldestPos);

    unlink($feedsConfig);
    unlink($outputFile);
});

it('cleans css artifacts from html descriptions', function () {
    $atomContent = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <entry>
        <title>Styled Entry</title>
        <summary type="html">&lt;div&gt;Hello&lt;svg&gt;&lt;style&gt;.cls-1{fill:currentColor;}.cls-2{fill:#63af5e;}&lt;/style&gt;&lt;/svg&gt;World&lt;/div&gt;</summary>
        <link href="http://example.com/styled"/>
        <updated>2023-10-27T10:00:00Z</updated>
        <id>urn:uuid:styled</id>
    </entry>
</feed>
XML;

    $mockResponse = new MockResponse($atomContent);
    $httpClient = new MockHttpClient($mockResponse);

    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('get')->andReturnUsing(function ($key, $callback) {
        $item = Mockery::mock(ItemInterface::class);
        $item->shouldReceive('expiresAfter')->andReturnSelf();
        return $callback($item);
    });
    $cache->shouldReceive('delete')->andReturn(true);

    $feedsConfig = sys_get_temp_dir() . '/feeds_clean.yaml';
    file_put_contents($feedsConfig, "feeds: ['http://example.com/styled-feed']");

    $outputFile = sys_get_temp_dir() . '/output_clean.atom';

    $processor = new FeedProcessor($httpClient, $cache, $feedsConfig, $outputFile);
    $processor->processFeeds();

    $outputContent = file_get_contents($outputFile);
    expect($outputContent)->toContain('HelloWorld');
    expect($outputContent)->not->toContain('.cls-1{fill:currentColor;}');
    expect($outputContent)->not->toContain('.cls-2{fill:#63af5e;}');

    unlink($feedsConfig);
    unlink($outputFile);
});

it('parses real-world atom links and content-only entries', function () {
    $atomContent = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <entry>
        <title>Release 1</title>
        <content type="html">&lt;p&gt;Release body&lt;/p&gt;</content>
        <link rel="self" href="https://example.com/releases.atom"/>
        <link rel="alternate" href="https://example.com/releases/1"/>
        <updated>2026-06-10T06:11:04Z</updated>
        <id>tag:example.com,2008:release-1</id>
    </entry>
</feed>
XML;

    $mockResponse = new MockResponse($atomContent);
    $httpClient = new MockHttpClient($mockResponse);

    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('get')->andReturnUsing(function ($key, $callback) {
        $item = Mockery::mock(ItemInterface::class);
        $item->shouldReceive('expiresAfter')->andReturnSelf();
        return $callback($item);
    });
    $cache->shouldReceive('delete')->andReturn(true);

    $feedsConfig = sys_get_temp_dir() . '/feeds_real_atom.yaml';
    file_put_contents($feedsConfig, "feeds: ['https://example.com/releases.atom']");

    $outputFile = sys_get_temp_dir() . '/output_real_atom.atom';

    $processor = new FeedProcessor($httpClient, $cache, $feedsConfig, $outputFile);
    $processor->processFeeds();

    $outputContent = file_get_contents($outputFile);
    expect($outputContent)->toContain('Release 1');
    expect($outputContent)->toContain('Release body');
    expect($outputContent)->toContain('type="html"');
    expect($outputContent)->toContain('&lt;p&gt;Release body&lt;/p&gt;');
    expect($outputContent)->toContain('https://example.com/releases/1');
    expect($outputContent)->not->toContain('https://example.com/releases.atom');

    unlink($feedsConfig);
    unlink($outputFile);
});

it('keeps list and paragraph tags in summary html', function () {
    $atomContent = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <entry>
        <title>Formatted Entry</title>
        <summary type="html">&lt;div&gt;&lt;p&gt;Intro&lt;/p&gt;&lt;ul&gt;&lt;li&gt;A&lt;/li&gt;&lt;li&gt;B&lt;/li&gt;&lt;/ul&gt;&lt;/div&gt;</summary>
        <link href="https://example.com/formatted"/>
        <updated>2026-06-11T10:00:00Z</updated>
        <id>urn:uuid:formatted</id>
    </entry>
</feed>
XML;

    $mockResponse = new MockResponse($atomContent);
    $httpClient = new MockHttpClient($mockResponse);

    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('get')->andReturnUsing(function ($key, $callback) {
        $item = Mockery::mock(ItemInterface::class);
        $item->shouldReceive('expiresAfter')->andReturnSelf();
        return $callback($item);
    });
    $cache->shouldReceive('delete')->andReturn(true);

    $feedsConfig = sys_get_temp_dir() . '/feeds_html_tags.yaml';
    file_put_contents($feedsConfig, "feeds: ['https://example.com/formatted.atom']");

    $outputFile = sys_get_temp_dir() . '/output_html_tags.atom';

    $processor = new FeedProcessor($httpClient, $cache, $feedsConfig, $outputFile);
    $processor->processFeeds();

    $outputContent = file_get_contents($outputFile);
    expect($outputContent)->toContain('type="html"');
    expect($outputContent)->toContain('&lt;p&gt;Intro&lt;/p&gt;');
    expect($outputContent)->toContain('&lt;ul&gt;');
    expect($outputContent)->toContain('&lt;li&gt;A&lt;/li&gt;');
    expect($outputContent)->toContain('&lt;li&gt;B&lt;/li&gt;');
    expect($outputContent)->not->toContain('&lt;div&gt;');

    unlink($feedsConfig);
    unlink($outputFile);
});

it('stores and uses ETag and Last-Modified headers', function () {
    $feedContent = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <entry>
        <title>Test Entry</title>
        <link href="http://example.com/test"/>
        <updated>2023-10-27T10:00:00Z</updated>
    </entry>
</feed>
XML;

    $url = 'http://example.com/feed';
    $etag = '"abc-123"';
    $lastModified = 'Fri, 27 Oct 2023 10:00:00 GMT';

    // First request: returns 200 OK with ETag and Last-Modified
    $response1 = new MockResponse($feedContent, [
        'http_code' => 200,
        'response_headers' => [
            'ETag' => [$etag],
            'Last-Modified' => [$lastModified],
        ],
    ]);

    // Second request: returns 304 Not Modified
    $response2 = function ($method, $url, $options) use ($etag, $lastModified) {
        // Verify headers were sent
        $headers = $options['headers'] ?? [];
        $ifNoneMatch = null;
        $ifModifiedSince = null;
        foreach ($headers as $h) {
            if (str_starts_with($h, 'If-None-Match: ')) {
                $ifNoneMatch = substr($h, 15);
            }
            if (str_starts_with($h, 'If-Modified-Since: ')) {
                $ifModifiedSince = substr($h, 19);
            }
        }

        if ($ifNoneMatch === $etag && $ifModifiedSince === $lastModified) {
            return new MockResponse('', ['http_code' => 304]);
        }

        return new MockResponse('Error', ['http_code' => 400]);
    };

    $httpClient = new MockHttpClient([$response1, $response2]);

    // Real cache or at least one that stores what we give it
    $cachedItems = [];
    $cache = Mockery::mock(CacheInterface::class);
    $cache->shouldReceive('delete')->andReturn(true);
    $cache->shouldReceive('get')->andReturnUsing(function ($key, $callback) use (&$cachedItems) {
        if (isset($cachedItems[$key])) {
            return $cachedItems[$key];
        }
        $item = Mockery::mock(ItemInterface::class);
        $item->shouldReceive('expiresAfter')->andReturnSelf();
        $value = $callback($item);
        $cachedItems[$key] = $value;
        return $value;
    });

    $feedsConfig = sys_get_temp_dir() . '/feeds_headers.yaml';
    file_put_contents($feedsConfig, "feeds: ['$url']");
    $outputFile = sys_get_temp_dir() . '/output_headers.atom';

    $processor = new FeedProcessor($httpClient, $cache, $feedsConfig, $outputFile);

    // First pass
    $processor->processFeeds();
    expect($cachedItems)->toHaveKey('feed_' . md5($url));
    $cacheEntry = $cachedItems['feed_' . md5($url)];
    expect($cacheEntry)->toBeArray();
    expect($cacheEntry)->toHaveKey('content');
    expect($cacheEntry)->toHaveKey('etag');
    expect($cacheEntry)->toHaveKey('last_modified');
    expect($cacheEntry['etag'])->toBe($etag);
    expect($cacheEntry['last_modified'])->toBe($lastModified);

    // Second pass - should use 304
    $processor->processFeeds();

    // Verify the second pass used the cached content
    $outputContent = file_get_contents($outputFile);
    expect($outputContent)->toContain('Test Entry');

    unlink($feedsConfig);
    unlink($outputFile);
});
