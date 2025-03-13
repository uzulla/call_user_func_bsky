<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\GitHub;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * GitHub APIクライアントクラス
 */
class GitHubClient
{
    private const API_BASE_URL = 'https://api.github.com/';
    protected Client $httpClient;
    private ?LoggerInterface $logger;
    
    /**
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->httpClient = new Client([
            'base_uri' => self::API_BASE_URL,
            'timeout' => 10.0,
            'headers' => [
                'User-Agent' => 'Packagist-to-BlueSky/1.0',
            ],
        ]);
        $this->logger = $logger;
    }
    
    /**
     * GitHubユーザーの情報を取得する
     *
     * @param string $username GitHubユーザー名
     * @return array<string, mixed>|null ユーザー情報、存在しない場合はnull
     */
    public function getUserInfo(string $username): ?array
    {
        try {
            $this->logger?->info('Fetching GitHub user info', ['username' => $username]);
            
            $client = $this->getHttpClient();
            $response = $client->get("users/{$username}");
            $data = json_decode((string) $response->getBody(), true);
            
            if (!is_array($data)) {
                $this->logger?->error('Invalid response from GitHub API', ['username' => $username]);
                return null;
            }
            
            $this->logger?->info('Successfully fetched GitHub user info', ['username' => $username]);
            /** @var array<string, mixed> $data */
            return $data;
        } catch (GuzzleException $e) {
            // 404の場合はユーザーが存在しない
            if ($e->getCode() === 404) {
                $this->logger?->info('GitHub user does not exist', ['username' => $username]);
                return null;
            }
            
            $this->logger?->error('Failed to fetch GitHub user info', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * GitHubユーザーが存在するかどうかを確認する
     *
     * @param string $username GitHubユーザー名
     * @return bool ユーザーが存在するかどうか
     */
    public function userExists(string $username): bool
    {
        return $this->getUserInfo($username) !== null;
    }
    
    /**
     * GitHubユーザーが新しいかどうかを確認する（1週間以内に登録されたかどうか）
     *
     * @param string $username GitHubユーザー名
     * @return bool ユーザーが新しいかどうか（存在しない場合もtrueを返す）
     */
    public function isNewUser(string $username): bool
    {
        $userInfo = $this->getUserInfo($username);
        
        // ユーザーが存在しない場合は新しいとみなす
        if ($userInfo === null) {
            return true;
        }
        
        // created_atが存在しない場合は新しいとみなす
        if (!isset($userInfo['created_at'])) {
            $this->logger?->warning('GitHub user info missing created_at', ['username' => $username]);
            return true;
        }
        
        if (!is_string($userInfo['created_at'])) {
            $this->logger?->warning('GitHub user created_at is not a string', ['username' => $username]);
            return true;
        }
        
        try {
            $createdAt = new \DateTime($userInfo['created_at']);
            $oneWeekAgo = new \DateTime('-1 week');
            
            $isNew = $createdAt > $oneWeekAgo;
            
            $this->logger?->info('Checked if GitHub user is new', [
                'username' => $username,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'is_new' => $isNew,
            ]);
            
            return $isNew;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to parse GitHub user created_at', [
                'username' => $username,
                'created_at' => $userInfo['created_at'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return true; // エラーの場合は新しいとみなす
        }
    }
    
    /**
     * リポジトリURLからGitHubユーザー名を抽出する
     *
     * @param string $repositoryUrl リポジトリURL
     * @return string|null GitHubユーザー名、抽出できない場合はnull
     */
    public function extractUsernameFromRepositoryUrl(string $repositoryUrl): ?string
    {
        // GitHub URLからユーザー名を抽出
        if (preg_match('#github\.com/([^/]+)/#', $repositoryUrl, $matches)) {
            return $matches[1];
        }
        
        return null;
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
}
