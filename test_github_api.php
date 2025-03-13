<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// ロガーを作成
$logger = new Logger('github-api-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// テスト対象のユーザー
$testUsers = [
    'uzulla',
    'avvertix',
    'non-existent-user-12345678',
];

// GitHubクライアントを作成
$client = new Client([
    'base_uri' => 'https://api.github.com/',
    'timeout' => 10.0,
    'headers' => [
        'User-Agent' => 'Packagist-to-BlueSky/1.0',
    ],
]);

// レート制限を確認
try {
    $response = $client->get('rate_limit');
    $data = json_decode($response->getBody(), true);
    
    $logger->info('GitHub API rate limit', [
        'limit' => $data['resources']['core']['limit'] ?? 'Unknown',
        'remaining' => $data['resources']['core']['remaining'] ?? 'Unknown',
        'reset_at' => date('Y-m-d H:i:s', $data['resources']['core']['reset'] ?? 0),
    ]);
    
    // レート制限が残っていない場合は警告
    if (($data['resources']['core']['remaining'] ?? 0) < count($testUsers)) {
        $logger->warning('Not enough API calls remaining for all test users', [
            'needed' => count($testUsers),
            'remaining' => $data['resources']['core']['remaining'] ?? 0,
        ]);
    }
} catch (GuzzleException $e) {
    $logger->error('Failed to check rate limit', ['error' => $e->getMessage()]);
}

// 各ユーザーをテスト
foreach ($testUsers as $username) {
    $logger->info('Testing GitHub user', ['username' => $username]);
    
    try {
        $response = $client->get("users/{$username}");
        $data = json_decode($response->getBody(), true);
        
        if (!is_array($data)) {
            $logger->error('Invalid response from GitHub API', ['username' => $username]);
            continue;
        }
        
        $logger->info('User exists', [
            'username' => $username,
            'id' => $data['id'] ?? 'Unknown',
            'created_at' => $data['created_at'] ?? 'Unknown',
        ]);
        
        // 1週間以内に作成されたかどうかを確認
        if (isset($data['created_at']) && is_string($data['created_at'])) {
            $createdAt = new DateTime($data['created_at']);
            $oneWeekAgo = new DateTime('-1 week');
            $isNew = $createdAt > $oneWeekAgo;
            
            $logger->info('User age check', [
                'username' => $username,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'one_week_ago' => $oneWeekAgo->format('Y-m-d H:i:s'),
                'is_new' => $isNew ? 'Yes' : 'No',
            ]);
        }
    } catch (GuzzleException $e) {
        if ($e->getCode() === 404) {
            $logger->info('User does not exist', ['username' => $username]);
        } elseif ($e->getCode() === 403 && strpos($e->getMessage(), 'rate limit exceeded') !== false) {
            $logger->error('Rate limit exceeded', ['username' => $username]);
            break; // レート制限に達したら終了
        } else {
            $logger->error('Failed to fetch user info', [
                'username' => $username,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }
}

$logger->info('Test completed');
