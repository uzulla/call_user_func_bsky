<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\RSS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * Packagist.orgのRSSフィードを読み込むクラス
 */
class PackagistRSSReader
{
    private Client $httpClient;
    private ?LoggerInterface $logger;
    private string $rssUrl;

    /**
     * @param string $rssUrl Packagist.orgのRSSフィードURL
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(
        string $rssUrl = 'https://packagist.org/feeds/releases.rss',
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = new Client();
        $this->logger = $logger;
        $this->rssUrl = $rssUrl;
    }

    /**
     * RSSフィードを取得して解析する
     *
     * @return array<int, array<string, mixed>> パッケージ情報の配列
     * @throws \RuntimeException RSSの取得や解析に失敗した場合
     */
    public function fetchPackages(): array
    {
        try {
            $this->logger?->info('Fetching RSS feed from Packagist.org', ['url' => $this->rssUrl]);
            
            $response = $this->httpClient->get($this->rssUrl);
            $content = (string) $response->getBody();
            
            return $this->parseRSSContent($content);
        } catch (GuzzleException $e) {
            $this->logger?->error('Failed to fetch RSS feed', [
                'url' => $this->rssUrl,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to fetch RSS feed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * RSS内容を解析してパッケージ情報の配列に変換する
     *
     * @param string $content RSSフィードの内容
     * @return array<int, array<string, mixed>> パッケージ情報の配列
     * @throws \RuntimeException 解析に失敗した場合
     */
    private function parseRSSContent(string $content): array
    {
        try {
            $xml = new SimpleXMLElement($content);
            $packages = [];

            foreach ($xml->channel->item as $item) {
                $pubDate = new \DateTime((string) $item->pubDate);
                
                $packages[] = [
                    'title' => (string) $item->title,
                    'link' => (string) $item->link,
                    'description' => (string) $item->description,
                    'pubDate' => $pubDate,
                    'guid' => (string) $item->guid,
                    'timestamp' => $pubDate->getTimestamp(),
                ];
            }

            // 公開日時の古い順にソート（昇順）
            usort($packages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
            
            $this->logger?->info('Successfully parsed RSS feed', ['count' => count($packages)]);
            
            return $packages;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to parse RSS content', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to parse RSS content: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 指定した日時以降に公開されたパッケージのみをフィルタリングする
     *
     * @param array<int, array<string, mixed>> $packages パッケージ情報の配列
     * @param \DateTime $since この日時以降のパッケージのみを返す
     * @return array<int, array<string, mixed>> フィルタリングされたパッケージ情報の配列
     */
    public function filterPackagesSince(array $packages, \DateTime $since): array
    {
        $filtered = array_filter(
            $packages,
            fn($package) => $package['pubDate'] > $since
        );

        $this->logger?->info('Filtered packages by date', [
            'total' => count($packages),
            'filtered' => count($filtered),
            'since' => $since->format('Y-m-d H:i:s')
        ]);

        return array_values($filtered);
    }
}
