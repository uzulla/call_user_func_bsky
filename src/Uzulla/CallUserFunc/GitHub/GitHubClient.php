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
    private ?string $authToken;

    /**
     * @param string|null $authToken GitHub API認証トークン
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(?string $authToken = null, ?LoggerInterface $logger = null)
    {
        $this->authToken = $authToken;
        $this->logger = $logger;

        $headers = [
            'User-Agent' => 'Packagist-to-BlueSky/1.0',
        ];

        // 認証トークンが提供された場合はヘッダーに追加
        if ($this->authToken) {
            $headers['Authorization'] = 'token ' . $this->authToken;
            $this->logger?->info('GitHub API token provided, using authenticated requests');
        } else {
            $this->logger?->info('No GitHub API token provided, using unauthenticated requests (lower rate limits)');
        }

        $this->httpClient = new Client([
            'base_uri' => self::API_BASE_URL,
            'timeout' => 10.0,
            'headers' => $headers,
        ]);
    }

    /**
     * GitHubユーザーの情報を取得する
     *
     * @param string $username GitHubユーザー名
     * @return array<string, mixed>|null ユーザー情報、存在しない場合はnull
     * @throws \RuntimeException レート制限に達した場合
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
            
            // レート制限エラーの場合は特別に処理
            if ($e->getCode() === 403 && strpos($e->getMessage(), 'rate limit exceeded') !== false) {
                $this->logger?->warning('GitHub API rate limit exceeded', ['username' => $username]);
                // レート制限エラーの場合はnullを返さず、例外をスローする
                throw new \RuntimeException('GitHub API rate limit exceeded. Please try again later.');
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
        try {
            return $this->getUserInfo($username) !== null;
        } catch (\RuntimeException $e) {
            // レート制限エラーの場合は、ユーザーが存在すると仮定する
            if (strpos($e->getMessage(), 'rate limit exceeded') !== false) {
                $this->logger?->warning('Assuming user exists due to rate limit', ['username' => $username]);
                return true; // レート制限エラーの場合は、ユーザーが存在すると仮定する
            }
            throw $e; // その他のエラーは再スロー
        }
    }
    
    /**
     * GitHubユーザーが新しいかどうかを確認する（1週間以内に登録されたかどうか）
     *
     * @param string $username GitHubユーザー名
     * @return bool ユーザーが新しいかどうか（存在しない場合もtrueを返す）
     */
    public function isNewUser(string $username): bool
    {
        try {
            $userInfo = $this->getUserInfo($username);
            
            // ユーザーが存在しない場合は新しいとみなす
            if ($userInfo === null) {
                $this->logger?->info('GitHub user does not exist, considering as new', ['username' => $username]);
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
                    'one_week_ago' => $oneWeekAgo->format('Y-m-d H:i:s'),
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
        } catch (\RuntimeException $e) {
            // レート制限エラーの場合は、ユーザーが新しくないと仮定する
            if (strpos($e->getMessage(), 'rate limit exceeded') !== false) {
                $this->logger?->warning('Assuming user is not new due to rate limit', ['username' => $username]);
                return false; // レート制限エラーの場合は、ユーザーが新しくないと仮定する
            }
            throw $e; // その他のエラーは再スロー
        }
    }
    
    /**
     * GitHubユーザーの登録日時を取得する
     *
     * @param string $username GitHubユーザー名
     * @return \DateTime|null ユーザーの登録日時、取得できない場合はnull
     */
    public function getUserCreatedAt(string $username): ?\DateTime
    {
        $userInfo = $this->getUserInfo($username);
        
        if ($userInfo === null || !isset($userInfo['created_at']) || !is_string($userInfo['created_at'])) {
            return null;
        }
        
        try {
            return new \DateTime($userInfo['created_at']);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to parse GitHub user created_at', [
                'username' => $username,
                'created_at' => $userInfo['created_at'],
                'error' => $e->getMessage(),
            ]);
            return null;
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
