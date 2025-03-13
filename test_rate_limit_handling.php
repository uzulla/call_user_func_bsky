<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Uzulla\CallUserFunc\GitHub\GitHubClient;
use GuzzleHttp\Client;

// ロガーを作成
$logger = new Logger('rate-limit-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// GitHubクライアントを作成
$githubClient = new GitHubClient($logger);

// レート制限を確認
try {
    $client = new Client([
        'base_uri' => 'https://api.github.com/',
        'timeout' => 10.0,
        'headers' => [
            'User-Agent' => 'Packagist-to-BlueSky/1.0',
        ],
    ]);
    
    $response = $client->get('rate_limit');
    $data = json_decode((string)$response->getBody(), true);
    
    $logger->info('GitHub API rate limit', [
        'limit' => $data['resources']['core']['limit'] ?? 'Unknown',
        'remaining' => $data['resources']['core']['remaining'] ?? 'Unknown',
        'reset_at' => date('Y-m-d H:i:s', $data['resources']['core']['reset'] ?? 0),
    ]);
    
    // レート制限が残っていない場合は警告
    if (($data['resources']['core']['remaining'] ?? 0) < 1) {
        $logger->warning('GitHub API rate limit exceeded. Testing rate limit handling...');
    }
} catch (\Exception $e) {
    $logger->error('Failed to check rate limit', ['error' => $e->getMessage()]);
}

// テスト対象のユーザー
$testUsers = [
    'uzulla',
    'non-existent-user-12345678',
];

// 各ユーザーをテスト
foreach ($testUsers as $username) {
    $logger->info('Testing user existence with improved rate limit handling', ['username' => $username]);
    
    try {
        // ユーザーが存在するかテスト
        $exists = $githubClient->userExists($username);
        $logger->info('User existence check result', [
            'username' => $username,
            'exists' => $exists ? 'Yes' : 'No',
        ]);
        
        if ($exists) {
            // ユーザーが新しいかテスト
            $isNew = $githubClient->isNewUser($username);
            $logger->info('User newness check result', [
                'username' => $username,
                'is_new' => $isNew ? 'Yes (created within a week)' : 'No (older than a week)',
            ]);
            
            // ユーザーの登録日時を取得
            $createdAt = $githubClient->getUserCreatedAt($username);
            if ($createdAt) {
                $logger->info('User creation date', [
                    'username' => $username,
                    'created_at' => $createdAt->format('Y-m-d H:i:s'),
                    'days_ago' => $createdAt->diff(new \DateTime())->days,
                ]);
            }
        }
    } catch (\RuntimeException $e) {
        // レート制限エラーの場合
        if (strpos($e->getMessage(), 'rate limit exceeded') !== false) {
            $logger->warning('Rate limit exceeded during test', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            
            // レート制限エラーの場合の動作を確認
            $logger->info('Testing fallback behavior when rate limited');
            
            // userExists()はレート制限時にtrueを返すはず
            $exists = $githubClient->userExists($username);
            $logger->info('userExists() fallback result', [
                'username' => $username,
                'exists' => $exists ? 'Yes (assumed to exist due to rate limit)' : 'No',
            ]);
            
            // isNewUser()はレート制限時にfalseを返すはず
            $isNew = $githubClient->isNewUser($username);
            $logger->info('isNewUser() fallback result', [
                'username' => $username,
                'is_new' => $isNew ? 'Yes' : 'No (assumed not new due to rate limit)',
            ]);
        } else {
            $logger->error('Unexpected error checking user', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
        }
    } catch (\Exception $e) {
        $logger->error('Error checking user', [
            'username' => $username,
            'error' => $e->getMessage(),
        ]);
    }
}

$logger->info('Test completed');
