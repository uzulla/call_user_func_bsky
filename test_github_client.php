<?php

require_once __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Uzulla\CallUserFunc\GitHub\GitHubClient;

// ロガーを作成
$logger = new Logger('github-client-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// テスト対象のユーザー
$testUsers = [
    'uzulla',
    'non-existent-user-12345678',
];

// GitHubクライアントを作成
$githubClient = new GitHubClient($logger);

// 各ユーザーをテスト
foreach ($testUsers as $username) {
    $logger->info('Testing GitHub user with GitHubClient', ['username' => $username]);
    
    // ユーザー情報を取得
    $userInfo = $githubClient->getUserInfo($username);
    
    if ($userInfo === null) {
        $logger->info('GitHubClient reports user does not exist', ['username' => $username]);
        continue;
    }
    
    $logger->info('GitHubClient reports user exists', [
        'username' => $username,
        'id' => $userInfo['id'] ?? 'Unknown',
        'created_at' => $userInfo['created_at'] ?? 'Unknown',
    ]);
    
    // ユーザーが新しいかどうかを確認
    $isNew = $githubClient->isNewUser($username);
    $logger->info('GitHubClient new user check', [
        'username' => $username,
        'is_new' => $isNew ? 'Yes' : 'No',
    ]);
}

$logger->info('Test completed');
