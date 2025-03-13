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
     * @param int $limit 投稿する最大パッケージ数
     * @param bool $dryRun ドライランかどうか
     * @param OutputInterface $output 出力
     * @return int 終了コード
     */
    public function run(int $limit, bool $dryRun, OutputInterface $output): int
    {
        $limitValue = $limit;
        
        try {
            // BlueSkyに認証
            $this->authenticateBlueSky($output);
            
            // GitHub Actions Variableから最後のパッケージ公開日時を取得
            $lastPackagePubDate = $this->getLastPackagePubDate($output);
            if ($lastPackagePubDate !== null) {
                $this->logger?->info("lastPackagePubDate: {$lastPackagePubDate->format('Y-m-d H:i:s')}");
            }
            // Packagistから新着パッケージを取得
            $packages = $this->fetchPackages($output);
            
            // スパムの可能性があるパッケージをフィルタリング
            $packages = $this->filterSpamPackages($packages, $output);
            
            // 最後に処理したパッケージ以降のパッケージをフィルタリング
            if ($lastPackagePubDate !== null) {
                $packages = $this->rssReader->filterPackagesSince($packages, $lastPackagePubDate);
                $output->writeln(sprintf(
                    '<info>%s以降の新着パッケージ: %d件</info>',
                    $lastPackagePubDate->format('Y-m-d H:i:s'),
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
     * GitHub Actions Variableから最後のパッケージ公開日時を取得する
     *
     * @param OutputInterface $output 出力
     * @return \DateTime|null 最後のパッケージ公開日時、取得できない場合はnull
     */
    private function getLastPackagePubDate(OutputInterface $output): ?\DateTime
    {
        $output->writeln('<info>GitHub Actions Variableから最後のパッケージ公開日時を取得します</info>');
        
        try {
            $lastPackagePubDate = $this->githubClient->getLastPackagePubDate();
            
            if ($lastPackagePubDate !== null) {
                $output->writeln(sprintf(
                    '<info>最後のパッケージ公開日時: %s</info>',
                    $lastPackagePubDate->format('Y-m-d H:i:s')
                ));
            } else {
                $output->writeln('<comment>最後のパッケージ公開日時が見つかりません</comment>');
            }
            
            return $lastPackagePubDate;
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<comment>GitHub Actions Variableの取得に失敗しました: %s</comment>', $e->getMessage()));
            return null;
        }
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
        $rateLimitedCount = 0;
        
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
            
            try {
                // ユーザーが存在するか確認
                $userExists = $this->githubClient->userExists($username);
                if (!$userExists) {
                    $this->logger?->info('Skipping package from non-existent GitHub user', [
                        'package' => $package['title'] ?? 'unknown',
                        'username' => $username,
                    ]);
                    $output->writeln(sprintf(
                        '<comment>スパムの可能性があるためスキップします: %s (GitHubユーザー: %s) - ユーザーが存在しません</comment>',
                        $package['title'] ?? 'unknown',
                        (string)$username
                    ));
                    $skippedCount++;
                    continue;
                }
                
                // ユーザーが新しいか確認
                $isNewUser = $this->githubClient->isNewUser($username);
                if ($isNewUser) {
                    // ユーザーの登録日時を取得
                    $createdAt = $this->githubClient->getUserCreatedAt($username);
                    $createdAtStr = $createdAt ? $createdAt->format('Y-m-d H:i:s') : '不明';
                    $oneWeekAgo = new \DateTime('-1 week');
                    
                    $this->logger?->info('Skipping package from new GitHub user', [
                        'package' => $package['title'] ?? 'unknown',
                        'username' => $username,
                        'created_at' => $createdAtStr,
                        'one_week_ago' => $oneWeekAgo->format('Y-m-d H:i:s'),
                    ]);
                    
                    $output->writeln(sprintf(
                        '<comment>スパムの可能性があるためスキップします: %s (GitHubユーザー: %s) - ユーザー登録日: %s (1週間以内)</comment>',
                        $package['title'] ?? 'unknown',
                        (string)$username,
                        $createdAtStr
                    ));
                    $skippedCount++;
                    continue;
                }
                
                $filteredPackages[] = $package;
            } catch (\RuntimeException $e) {
                // レート制限エラーの場合は、パッケージをスキップせずに含める
                if (strpos($e->getMessage(), 'rate limit exceeded') !== false) {
                    $this->logger?->warning('Including package despite rate limit', [
                        'package' => $package['title'] ?? 'unknown',
                        'username' => $username,
                    ]);
                    $output->writeln(sprintf(
                        '<comment>GitHub APIのレート制限により検証できませんが、パッケージを含めます: %s (GitHubユーザー: %s)</comment>',
                        $package['title'] ?? 'unknown',
                        (string)$username
                    ));
                    $filteredPackages[] = $package;
                    $rateLimitedCount++;
                } else {
                    // その他のエラーの場合はスキップ
                    $this->logger?->error('Skipping package due to error', [
                        'package' => $package['title'] ?? 'unknown',
                        'username' => $username,
                        'error' => $e->getMessage(),
                    ]);
                    $output->writeln(sprintf(
                        '<comment>エラーのためスキップします: %s (GitHubユーザー: %s) - %s</comment>',
                        $package['title'] ?? 'unknown',
                        (string)$username,
                        $e->getMessage()
                    ));
                    $skippedCount++;
                }
            }
        }
        
        $output->writeln(sprintf(
            '<info>スパムフィルタリング結果: %d件中%d件をスキップしました（レート制限により%d件を検証せずに含めました）</info>',
            count($packages),
            $skippedCount,
            $rateLimitedCount
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
        if (empty($packages)) {
            $output->writeln('<comment>投稿するパッケージがありません</comment>');
            return;
        }
        
        $output->writeln(sprintf('<info>%d件のパッケージを投稿します</info>', count($packages)));
        
        $formattedPackages = $this->formatter->formatPackages($packages);
        $this->logger?->info('Formatted packages for posting', [
            'input_count' => count($packages),
            'formatted_count' => count($formattedPackages)
        ]);
        
        // 最後に処理したパッケージの公開日時を記録
        $lastPackage = $packages[array_key_last($packages)];
        $lastPackagePubDate = null;
        
        if (isset($lastPackage['pubDate']) && $lastPackage['pubDate'] instanceof \DateTime) {
            $lastPackagePubDate = $lastPackage['pubDate'];
            $this->logger?->info('Last package pubDate', [
                'pubDate' => $lastPackagePubDate->format('Y-m-d H:i:s')
            ]);
        }
        
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
        
        // 最後に処理したパッケージの公開日時をGitHub Actions Variableに保存
        if (!$dryRun && $lastPackagePubDate !== null) {
            $this->saveLastPackagePubDate($lastPackagePubDate, $output);
        }
    }
    
    /**
     * 最後のパッケージ公開日時をGitHub Actions Variableに保存する
     *
     * @param \DateTime $pubDate 保存する公開日時
     * @param OutputInterface $output 出力
     */
    private function saveLastPackagePubDate(\DateTime $pubDate, OutputInterface $output): void
    {
        $output->writeln('<info>最後のパッケージ公開日時をGitHub Actions Variableに保存します</info>');
        
        try {
            $success = $this->githubClient->setLastPackagePubDate($pubDate);
            
            if ($success) {
                $output->writeln(sprintf(
                    '<info>最後のパッケージ公開日時を保存しました: %s</info>',
                    $pubDate->format('Y-m-d H:i:s')
                ));
            } else {
                $output->writeln('<error>最後のパッケージ公開日時の保存に失敗しました</error>');
            }
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>GitHub Actions Variableの保存に失敗しました: %s</error>', $e->getMessage()));
        }
    }
}
