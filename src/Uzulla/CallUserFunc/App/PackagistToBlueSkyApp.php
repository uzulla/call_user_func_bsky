<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\App;

use Psr\Log\LoggerInterface;
use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;
use Uzulla\CallUserFunc\Formatter\PackagistFormatter;
use Uzulla\CallUserFunc\GitHub\GitHubClient;
use Uzulla\CallUserFunc\RSS\PackagistRSSReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Packagist.orgの新着パッケージをBlueSkyに投稿するアプリケーションクラス
 */
class PackagistToBlueSkyApp
{
    private PackagistRSSReader $rssReader;
    private BlueSkyClient $blueSkyClient;
    private PackagistFormatter $formatter;
    private GitHubClient $githubClient;
    private ?LoggerInterface $logger;
    
    /**
     * @param PackagistRSSReader $rssReader RSSリーダー
     * @param BlueSkyClient $blueSkyClient BlueSkyクライアント
     * @param PackagistFormatter $formatter フォーマッター
     * @param GitHubClient $githubClient GitHubクライアント
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(
        PackagistRSSReader $rssReader,
        BlueSkyClient $blueSkyClient,
        PackagistFormatter $formatter,
        GitHubClient $githubClient,
        ?LoggerInterface $logger = null
    ) {
        $this->rssReader = $rssReader;
        $this->blueSkyClient = $blueSkyClient;
        $this->formatter = $formatter;
        $this->githubClient = $githubClient;
        $this->logger = $logger;
    }
    
    /**
     * アプリケーションの実行
     *
     * @param int|string|null $limit 投稿する最大パッケージ数
     * @param bool $dryRun ドライランかどうか
     * @param OutputInterface $output 出力
     * @return int 終了コード
     */
    public function run($limit, bool $dryRun, OutputInterface $output): int
    {
        $limitValue = is_numeric($limit) ? (int) $limit : 50;
        
        try {
            // BlueSkyに認証
            $this->authenticateBlueSky($output);
            
            // 最新の投稿日時を取得
            $lastPostDate = $this->getLastPostDate($output);
            
            // Packagistから新着パッケージを取得
            $packages = $this->fetchPackages($output);
            
            // スパムの可能性があるパッケージをフィルタリング
            $packages = $this->filterSpamPackages($packages, $output);
            
            // 最新の投稿以降のパッケージをフィルタリング
            if ($lastPostDate !== null) {
                $packages = $this->rssReader->filterPackagesSince($packages, $lastPostDate);
                $output->writeln(sprintf(
                    '<info>%s以降の新着パッケージ: %d件</info>',
                    $lastPostDate->format('Y-m-d H:i:s'),
                    count($packages)
                ));
            }
            
            // 投稿数を制限
            if (count($packages) > $limitValue) {
                $packages = array_slice($packages, 0, $limitValue);
                $output->writeln(sprintf('<info>投稿数を%d件に制限します</info>', $limitValue));
            }
            
            // パッケージを公開日時の昇順（古い順）でソート
            usort($packages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
            $output->writeln('<info>パッケージを公開日時の昇順（古い順）でソートしました</info>');
            
            if (empty($packages)) {
                $output->writeln('<comment>新着パッケージはありません</comment>');
                return Command::SUCCESS;
            }
            
            // パッケージを整形して投稿
            $this->postPackages($packages, $output, $dryRun);
            
            $output->writeln('<info>処理が完了しました</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger?->error('アプリケーションエラー', ['error' => $e->getMessage()]);
            $output->writeln(sprintf('<error>エラー: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
    
    /**
     * BlueSkyに認証する
     *
     * @param OutputInterface $output 出力
     * @throws \RuntimeException 認証に失敗した場合
     */
    private function authenticateBlueSky(OutputInterface $output): void
    {
        $username = $_ENV['BLUESKY_USERNAME'] ?? '';
        $password = $_ENV['BLUESKY_PASSWORD'] ?? '';
        
        if (empty($username) || empty($password)) {
            throw new \RuntimeException('BlueSkyの認証情報が設定されていません');
        }
        
        $output->writeln(sprintf('<info>BlueSkyに認証します: %s</info>', (string)$username));
        
        $this->blueSkyClient->authenticate((string)$username, (string)$password);
        
        $output->writeln('<info>BlueSkyに認証しました</info>');
    }
    
    /**
     * 最新の投稿日時を取得する
     *
     * @param OutputInterface $output 出力
     * @return \DateTime|null 最新の投稿日時、投稿がない場合はnull
     */
    private function getLastPostDate(OutputInterface $output): ?\DateTime
    {
        $output->writeln('<info>BlueSkyの最新投稿日時を取得します</info>');
        
        $lastPostDate = $this->blueSkyClient->getLatestPostDate();
        
        if ($lastPostDate !== null) {
            $output->writeln(sprintf(
                '<info>最新投稿日時: %s</info>',
                $lastPostDate->format('Y-m-d H:i:s')
            ));
        } else {
            $output->writeln('<comment>投稿がありません</comment>');
        }
        
        return $lastPostDate;
    }
    
    /**
     * Packagistから新着パッケージを取得する
     *
     * @param OutputInterface $output 出力
     * @return array<int, array<string, mixed>> パッケージ情報の配列
     */
    private function fetchPackages(OutputInterface $output): array
    {
        $output->writeln('<info>Packagist.orgから新着パッケージを取得します</info>');
        
        $packages = $this->rssReader->fetchPackages();
        
        $output->writeln(sprintf('<info>取得したパッケージ: %d件</info>', count($packages)));
        
        // パッケージに詳細情報を追加
        $output->writeln('<info>パッケージに詳細情報を追加します</info>');
        $packages = $this->rssReader->enrichPackagesWithDetails($packages);
        
        return $packages;
    }
    
    /**
     * スパムの可能性があるパッケージをフィルタリングする
     *
     * @param array<int, array<string, mixed>> $packages パッケージ情報の配列
     * @param OutputInterface $output 出力
     * @return array<int, array<string, mixed>> フィルタリングされたパッケージ情報の配列
     */
    private function filterSpamPackages(array $packages, OutputInterface $output): array
    {
        $output->writeln('<info>スパムの可能性があるパッケージをフィルタリングします</info>');
        
        $filteredPackages = [];
        $skippedCount = 0;
        
        foreach ($packages as $package) {
            // リポジトリURLがない場合はスキップしない
            if (!isset($package['repository_url']) || !is_string($package['repository_url'])) {
                $filteredPackages[] = $package;
                continue;
            }
            
            $repositoryUrl = $package['repository_url'];
            
            // GitHubリポジトリでない場合はスキップしない
            if (strpos($repositoryUrl, 'github.com') === false) {
                $filteredPackages[] = $package;
                continue;
            }
            
            // GitHubユーザー名を抽出
            $username = $this->githubClient->extractUsernameFromRepositoryUrl($repositoryUrl);
            
            // ユーザー名が抽出できない場合はスキップしない
            if ($username === null) {
                $filteredPackages[] = $package;
                continue;
            }
            
            // ユーザーが存在しないか、新しい場合はスキップ
            if (!$this->githubClient->userExists($username) || $this->githubClient->isNewUser($username)) {
                $this->logger?->info('Skipping package from new or non-existent GitHub user', [
                    'package' => $package['title'] ?? 'unknown',
                    'username' => $username,
                ]);
                $output->writeln(sprintf(
                    '<comment>スパムの可能性があるためスキップします: %s (GitHubユーザー: %s)</comment>',
                    $package['title'] ?? 'unknown',
                    (string)$username
                ));
                $skippedCount++;
                continue;
            }
            
            $filteredPackages[] = $package;
        }
        
        $output->writeln(sprintf(
            '<info>スパムフィルタリング結果: %d件中%d件をスキップしました</info>',
            count($packages),
            $skippedCount
        ));
        
        return $filteredPackages;
    }
    
    /**
     * パッケージを整形して投稿する
     *
     * @param array<int, array<string, mixed>> $packages パッケージ情報の配列
     * @param OutputInterface $output 出力
     * @param bool $dryRun ドライランかどうか
     */
    private function postPackages(array $packages, OutputInterface $output, bool $dryRun): void
    {
        $output->writeln(sprintf('<info>%d件のパッケージを投稿します</info>', count($packages)));
        
        $formattedPackages = $this->formatter->formatPackages($packages);
        $this->logger?->info('Formatted packages for posting', [
            'input_count' => count($packages),
            'formatted_count' => count($formattedPackages)
        ]);
        
        foreach ($formattedPackages as $index => $formattedPackage) {
            $packageNumber = $index + 1;
            $output->writeln(sprintf('<info>パッケージ %d/%d を投稿します</info>', $packageNumber, count($formattedPackages)));
            $output->writeln($formattedPackage['text']);
            
            if (!$dryRun) {
                try {
                    $postUri = $this->blueSkyClient->createPost(
                        $formattedPackage['text'],
                        $formattedPackage['links']
                    );
                    
                    $this->logger?->info('Posted package to BlueSky', [
                        'package_number' => $packageNumber,
                        'total_packages' => count($formattedPackages),
                        'uri' => $postUri
                    ]);
                    
                    $output->writeln(sprintf('<info>投稿しました: %s</info>', $postUri));
                } catch (\Exception $e) {
                    $this->logger?->error('Failed to post package', [
                        'package_number' => $packageNumber,
                        'error' => $e->getMessage()
                    ]);
                    $output->writeln(sprintf('<error>投稿に失敗しました: %s</error>', $e->getMessage()));
                    // Continue with the next package
                }
            }
        }
    }
}
