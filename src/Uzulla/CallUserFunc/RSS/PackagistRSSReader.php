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
    protected Client $httpClient;
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
            
            $response = $this->getHttpClient()->get($this->rssUrl);
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
     * HTTPクライアントを取得する（テスト用）
     *
     * @return Client HTTPクライアント
     */
    protected function getHttpClient(): Client
    {
        return $this->httpClient;
    }

    /**
     * RSS内容を解析してパッケージ情報の配列に変換する
     *
     * @param string $content RSSフィードの内容
     * @return array<int, array<string, mixed>> パッケージ情報の配列
     * @throws \RuntimeException 解析に失敗した場合
     */
    protected function parseRSSContent(string $content): array
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
    
    /**
     * パッケージの詳細情報をPackagist APIから取得する
     *
     * @param string $packageName パッケージ名
     * @return array<string, mixed>|null パッケージ詳細情報、取得に失敗した場合はnull
     */
    public function fetchPackageDetails(string $packageName): ?array
    {
        try {
            $this->logger?->info('Fetching package details from Packagist API', ['package' => $packageName]);
            
            $url = "https://packagist.org/packages/{$packageName}.json";
            $response = $this->httpClient->get($url);
            $data = json_decode((string) $response->getBody(), true);
            
            if (!is_array($data) || !isset($data['package']) || !is_array($data['package'])) {
                $this->logger?->error('Invalid response from Packagist API', ['package' => $packageName]);
                return null;
            }
            
            $this->logger?->info('Successfully fetched package details', ['package' => $packageName]);
            /** @var array<string, mixed> $packageData */
            $packageData = $data['package'];
            return $packageData;
        } catch (GuzzleException $e) {
            $this->logger?->error('Failed to fetch package details', [
                'package' => $packageName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * パッケージ名を抽出する
     *
     * @param string $title パッケージタイトル（例: "vendor/package (1.0.0)"）
     * @return string|null パッケージ名、抽出できない場合はnull
     */
    public function extractPackageName(string $title): ?string
    {
        if (preg_match('/^(.+?) \((.+?)\)$/', $title, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * パッケージの詳細情報を取得して元のパッケージ情報にマージする
     *
     * @param array<string, mixed> $package パッケージ情報
     * @return array<string, mixed> 詳細情報がマージされたパッケージ情報
     */
    public function enrichPackageWithDetails(array $package): array
    {
        if (!isset($package['title']) || !is_string($package['title'])) {
            return $package;
        }
        
        $packageName = $this->extractPackageName($package['title']);
        
        if ($packageName === null) {
            $this->logger?->warning('Failed to extract package name', ['title' => $package['title']]);
            return $package;
        }
        
        $details = $this->fetchPackageDetails($packageName);
        
        if ($details === null) {
            return $package;
        }
        
        // リポジトリURLを追加（Packagist APIから直接取得）
        if (isset($details['repository']) && is_string($details['repository'])) {
            $package['repository_url'] = $details['repository'];
            $this->logger?->info('Added repository URL from Packagist API', [
                'package' => $packageName,
                'repository_url' => $details['repository']
            ]);
        }
        
        return $package;
    }
    
    /**
     * 複数のパッケージに詳細情報を追加する
     *
     * @param array<int, array<string, mixed>> $packages パッケージ情報の配列
     * @return array<int, array<string, mixed>> 詳細情報が追加されたパッケージ情報の配列
     */
    public function enrichPackagesWithDetails(array $packages): array
    {
        $this->logger?->info('Enriching packages with details', ['count' => count($packages)]);
        
        $enrichedPackages = [];
        
        foreach ($packages as $package) {
            $enrichedPackages[] = $this->enrichPackageWithDetails($package);
        }
        
        return $enrichedPackages;
    }
}
