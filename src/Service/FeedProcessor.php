<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeInterface;
use DOMDocument;
use DOMXPath;
use Psr\Cache\InvalidArgumentException;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class FeedProcessor
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface      $cache,
        #[Autowire('%kernel.project_dir%/config/feeds.yaml')]
        private string              $feedsConfigFile,
        #[Autowire('%kernel.project_dir%/public/combined_feed.atom')]
        private string              $outputFile
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function processFeeds(): void
    {
        $config = Yaml::parseFile($this->feedsConfigFile);
        $feedUrls = $config['feeds'] ?? [];

        $allItems = [];

        foreach ($feedUrls as $url) {
            $feedContent = $this->cache->get('feed_' . md5($url), function (ItemInterface $item) use ($url) {
                $item->expiresAfter(3600); // Cache for 1 hour
                $response = $this->httpClient->request('GET', $url);
                return $response->getContent();
            });

            $items = $this->parseFeed($feedContent);
            $allItems = array_merge($allItems, $items);
        }

        // Sort chronologically (descending)
        usort(
            $allItems,
            static fn ($a, $b) => $b['updated'] <=> $a['updated']
        );

        $this->generateAtomFeed($allItems);
    }

    private function parseFeed(string $xmlContent): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xmlContent, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $atomFeedTitle = trim((string) $xpath->evaluate('string(//atom:feed/atom:title[1])'));

        $items = [];
        $entries = $xpath->query('//atom:feed/atom:entry');

        if ($entries !== false && $entries->length > 0) {
            foreach ($entries as $entry) {
                $title = trim((string) $xpath->evaluate('string(atom:title)', $entry));
                $summary = trim((string) $xpath->evaluate('string(atom:summary)', $entry));
                $content = trim((string) $xpath->evaluate('string(atom:content)', $entry));
                $description = $summary !== '' ? $summary : $content;

                $link = '';
                $links = $xpath->query('atom:link', $entry);
                if ($links !== false) {
                    foreach ($links as $candidateLink) {
                        if (!$candidateLink->hasAttribute('href')) {
                            continue;
                        }

                        $rel = $candidateLink->getAttribute('rel');
                        if ($rel === '' || $rel === 'alternate') {
                            $link = $candidateLink->getAttribute('href');
                            break;
                        }

                        if ($link === '') {
                            $link = $candidateLink->getAttribute('href');
                        }
                    }
                }

                $rawUpdated = trim((string) $xpath->evaluate('string(atom:updated)', $entry));
                $timestamp = $rawUpdated !== '' ? strtotime($rawUpdated) : false;
                $updated = $timestamp !== false ? date(DateTimeInterface::ATOM, $timestamp) : date(DateTimeInterface::ATOM);

                $id = trim((string) $xpath->evaluate('string(atom:id)', $entry));
                if ($id === '') {
                    $id = $link !== '' ? $link : uniqid('entry_', true);
                }

                $items[] = [
                    'title' => $this->prependSourceToTitle(strip_tags($title), $atomFeedTitle),
                    'description' => $this->cleanDescription($description),
                    'link' => $link,
                    'updated' => $updated,
                    'id' => $id,
                ];
            }

            return $items;
        }

        $crawler = new Crawler($xmlContent);
        $rssFeedTitle = $crawler->filter('channel > title')->count() ? trim($crawler->filter('channel > title')->first()->text()) : '';
        $crawler->filter('item')->each(function (Crawler $node) use (&$items) {
            $rawPubDate = $node->filter('pubDate')->count() ? $node->filter('pubDate')->text() : '';
            $timestamp = $rawPubDate !== '' ? strtotime($rawPubDate) : false;

            $items[] = [
                'title' => strip_tags($node->filter('title')->text()),
                'description' => $this->cleanDescription($node->filter('description')->text()),
                'link' => $node->filter('link')->text(),
                'updated' => $timestamp !== false ? date(DateTimeInterface::ATOM, $timestamp) : date(DateTimeInterface::ATOM),
                'id' => $node->filter('guid')->count() ? $node->filter('guid')->text() : $node->filter('link')->text(),
            ];
        });

        foreach ($items as &$item) {
            $item['title'] = $this->prependSourceToTitle($item['title'], $rssFeedTitle);
        }
        unset($item);

        return $items;
    }

    private function prependSourceToTitle(string $title, string $feedTitle): string
    {
        if ($feedTitle === '') {
            return $title;
        }

        return sprintf('%s: %s', $feedTitle, $title);
    }

    private function cleanDescription(string $description): string
    {
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (str_contains($description, '<')) {
            $dom = new DOMDocument('1.0', 'UTF-8');

            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $description, LIBXML_NOERROR | LIBXML_NOWARNING);

            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//style|//script|//svg|//iframe') as $node) {
                $node->parentNode?->removeChild($node);
            }

            // There should be only one body element, but loop through all of them just in case
            foreach ($dom->getElementsByTagName('body') as $body) {
                $description = '';
                if ($body !== null) {
                    $allowedTags = ['p', 'ol', 'ul', 'li', 'h2', 'h3'];
                    foreach ($xpath->query('.//*', $body) as $node) {
                        if (in_array($node->nodeName, $allowedTags, true)) {
                            continue;
                        }

                        $parent = $node->parentNode;
                        if ($parent === null) {
                            continue;
                        }

                        while ($node->firstChild !== null) {
                            $parent->insertBefore($node->firstChild, $node);
                        }

                        $parent->removeChild($node);
                    }

                    foreach ($body->childNodes as $childNode) {
                        $description .= $dom->saveHTML($childNode);
                    }
                }
            }
        }

        $description = preg_replace('/\.cls-[\w-]+\s*\{[^}]*}/u', '', $description) ?? $description;
        $description = preg_replace('/\s+/u', ' ', $description) ?? $description;

        return trim($description);
    }

    private function generateAtomFeed(array $items): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>');
        $xml->addChild('title', 'Combined Feed');
        $xml->addChild('id', 'urn:uuid:combined-feed');
        $xml->addChild('updated', date(DateTimeInterface::ATOM));

        $link = $xml->addChild('link');
        $link->addAttribute('rel', 'self');
        $link->addAttribute('href', 'combined_feed.atom');

        foreach ($items as $itemData) {
            $entry = $xml->addChild('entry');
            $entry->addChild('title', htmlspecialchars($itemData['title']));
            $entry->addChild('id', htmlspecialchars($itemData['id']));
            $entry->addChild('updated', $itemData['updated']);

            $summary = $entry->addChild('summary', $itemData['description']);
            $summary->addAttribute('type', 'html');

            $entryLink = $entry->addChild('link');
            $entryLink->addAttribute('href', $itemData['link']);
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->save($this->outputFile);
    }
}
