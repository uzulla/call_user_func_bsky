<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\Formatter;

use Psr\Log\LoggerInterface;

/**
 * Packagistパッケージ情報をBlueSky投稿用に整形するクラス
 */
class PackagistFormatter
{
    private ?LoggerInterface $logger;
    private int $maxTitleLength;
    private int $maxDescriptionLength;
    
    /**
     * @param int $maxTitleLength タイトルの最大長
     * @param int $maxDescriptionLength 説明の最大長
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(
        int $maxTitleLength = 100,
        int $maxDescriptionLength = 200,
        ?LoggerInterface $logger = null
    ) {
        $this->maxTitleLength = $maxTitleLength;
        $this->maxDescriptionLength = $maxDescriptionLength;
        $this->logger = $logger;
    }
    
    /**
     * パッケージ情報を投稿用テキストに整形する
     *
     * @param array<string, mixed> $package パッケージ情報
     * @return string 整形されたテキスト
     */
    public function formatPackage(array $package): string
    {
        $this->logger?->debug('Formatting package for post', ['package' => $package['title'] ?? 'unknown']);
        
        $title = isset($package['title']) ? (string)$package['title'] : '';
        $link = isset($package['link']) ? (string)$package['link'] : '';
        $description = isset($package['description']) ? (string)$package['description'] : '';
        
        // タイトルが長すぎる場合は切り詰める
        if (mb_strlen($title) > $this->maxTitleLength) {
            $title = mb_substr($title, 0, $this->maxTitleLength - 3) . '...';
        }
        
        // 説明が長すぎる場合は切り詰める
        if (mb_strlen($description) > $this->maxDescriptionLength) {
            $description = mb_substr($description, 0, $this->maxDescriptionLength - 3) . '...';
        }
        
        // パッケージ名とバージョンを抽出
        $packageInfo = $this->extractPackageInfo($title);
        
        // 整形されたテキストを作成
        $text = "📦 {$packageInfo['name']} {$packageInfo['version']}\n\n";
        
        if (!empty($description)) {
            $text .= "{$description}\n\n";
        }
        
        $text .= "🔗 {$link}";
        
        $this->logger?->debug('Formatted package text', ['text_length' => mb_strlen($text)]);
        
        return $text;
    }
    
    /**
     * パッケージ情報から外部リンク情報を抽出する
     *
     * @param array<string, mixed> $package パッケージ情報
     * @return array<string, string> リンクテキストとURLのマップ
     */
    public function extractLinks(array $package): array
    {
        $links = [];
        
        if (isset($package['link']) && is_string($package['link'])) {
            $linkText = $package['link'];
            $links[$linkText] = $linkText;
        }
        
        return $links;
    }
    
    /**
     * 複数のパッケージをまとめて整形する
     *
     * @param array<int, array<string, mixed>> $packages パッケージ情報の配列
     * @param int $maxPackages 最大パッケージ数
     * @return array<int, array{text: string, links: array<string, string>}> 整形されたテキストとリンク情報の配列
     */
    public function formatPackages(array $packages, int $maxPackages = 10): array
    {
        $this->logger?->info('Formatting multiple packages', [
            'total' => count($packages),
            'max' => $maxPackages
        ]);
        
        $formattedPackages = [];
        $count = 0;
        
        foreach ($packages as $package) {
            if ($count >= $maxPackages) {
                break;
            }
            
            $formattedPackages[] = [
                'text' => $this->formatPackage($package),
                'links' => $this->extractLinks($package),
            ];
            
            $count++;
        }
        
        return $formattedPackages;
    }
    
    /**
     * パッケージタイトルからパッケージ名とバージョンを抽出する
     *
     * @param string $title パッケージタイトル（例: "vendor/package (1.0.0)"）
     * @return array{name: string, version: string} パッケージ名とバージョン
     */
    private function extractPackageInfo(string $title): array
    {
        $name = $title;
        $version = '';
        
        // バージョン情報を抽出（括弧内の文字列）
        if (preg_match('/^(.+?) \((.+?)\)$/', $title, $matches)) {
            $name = $matches[1];
            $version = $matches[2];
        }
        
        return [
            'name' => $name,
            'version' => $version,
        ];
    }
}
