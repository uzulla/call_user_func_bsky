<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\BlueSky;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * BlueSky APIクライアントクラス
 */
class BlueSkyClient
{
    private const API_BASE_URL = 'https://bsky.social/xrpc/';
    private const AUTH_ENDPOINT = 'com.atproto.server.createSession';
    private const POST_ENDPOINT = 'com.atproto.repo.createRecord';
    
    private Client $httpClient;
    private ?LoggerInterface $logger;
    private ?string $accessJwt = null;
    private ?string $refreshJwt = null;
    private ?string $did = null;
    private ?string $handle = null;
    
    /**
     * @param LoggerInterface|null $logger ロガー
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->httpClient = new Client([
            'base_uri' => self::API_BASE_URL,
            'timeout' => 10.0,
        ]);
        $this->logger = $logger;
    }
    
    /**
     * BlueSky APIに認証する
     *
     * @param string $username ユーザー名（handle）
     * @param string $password アプリパスワード
     * @return bool 認証成功したかどうか
     * @throws \RuntimeException 認証に失敗した場合
     */
    public function authenticate(string $username, string $password): bool
    {
        try {
            $this->logger?->info('Authenticating with BlueSky API', ['username' => $username]);
            
            $response = $this->httpClient->post(self::AUTH_ENDPOINT, [
                'json' => [
                    'identifier' => $username,
                    'password' => $password,
                ],
            ]);
            
            $data = json_decode((string) $response->getBody(), true);
            
            if (isset($data['accessJwt'], $data['refreshJwt'], $data['did'], $data['handle'])) {
                $this->accessJwt = $data['accessJwt'];
                $this->refreshJwt = $data['refreshJwt'];
                $this->did = $data['did'];
                $this->handle = $data['handle'];
                
                $this->logger?->info('Successfully authenticated with BlueSky API', [
                    'handle' => $this->handle,
                    'did' => $this->did,
                ]);
                
                return true;
            }
            
            $this->logger?->error('Authentication response missing required fields', ['data' => $data]);
            throw new \RuntimeException('Authentication response missing required fields');
        } catch (GuzzleException $e) {
            $this->logger?->error('Failed to authenticate with BlueSky API', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to authenticate with BlueSky API: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * BlueSkyに投稿する
     *
     * @param string $text 投稿テキスト
     * @param array<string, string> $externalLinks 外部リンク（オプション）
     * @return string 投稿のURI
     * @throws \RuntimeException 投稿に失敗した場合
     */
    public function createPost(string $text, array $externalLinks = []): string
    {
        if ($this->accessJwt === null || $this->did === null) {
            $this->logger?->error('Cannot create post: Not authenticated');
            throw new \RuntimeException('Cannot create post: Not authenticated');
        }
        
        try {
            $this->logger?->info('Creating post on BlueSky', ['text_length' => mb_strlen($text)]);
            
            $record = [
                'text' => $text,
                'createdAt' => (new \DateTime())->format('c'),
                'langs' => ['ja'],
            ];
            
            // 外部リンクがある場合は追加
            if (!empty($externalLinks)) {
                $facets = [];
                $byteStart = 0;
                
                foreach ($externalLinks as $linkText => $url) {
                    $pos = mb_strpos($text, $linkText);
                    if ($pos !== false) {
                        $byteStart = strlen(mb_substr($text, 0, $pos));
                        $byteEnd = $byteStart + strlen($linkText);
                        
                        $facets[] = [
                            'index' => [
                                'byteStart' => $byteStart,
                                'byteEnd' => $byteEnd,
                            ],
                            'features' => [
                                [
                                    '$type' => 'app.bsky.richtext.facet#link',
                                    'uri' => $url,
                                ],
                            ],
                        ];
                    }
                }
                
                if (!empty($facets)) {
                    $record['facets'] = $facets;
                }
            }
            
            $response = $this->httpClient->post(self::POST_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessJwt,
                ],
                'json' => [
                    'repo' => $this->did,
                    'collection' => 'app.bsky.feed.post',
                    'record' => $record,
                ],
            ]);
            
            $data = json_decode((string) $response->getBody(), true);
            
            if (isset($data['uri'])) {
                $this->logger?->info('Successfully created post on BlueSky', ['uri' => $data['uri']]);
                return $data['uri'];
            }
            
            $this->logger?->error('Post creation response missing URI', ['data' => $data]);
            throw new \RuntimeException('Post creation response missing URI');
        } catch (GuzzleException $e) {
            $this->logger?->error('Failed to create post on BlueSky', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to create post on BlueSky: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * 最新の投稿を取得する
     *
     * @param int $limit 取得する投稿数
     * @return array<int, array<string, mixed>> 投稿情報の配列
     * @throws \RuntimeException 投稿の取得に失敗した場合
     */
    public function getLatestPosts(int $limit = 10): array
    {
        if ($this->accessJwt === null || $this->did === null) {
            $this->logger?->error('Cannot get posts: Not authenticated');
            throw new \RuntimeException('Cannot get posts: Not authenticated');
        }
        
        try {
            $this->logger?->info('Fetching latest posts', ['limit' => $limit]);
            
            $response = $this->httpClient->get('app.bsky.feed.getAuthorFeed', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessJwt,
                ],
                'query' => [
                    'actor' => $this->did,
                    'limit' => $limit,
                ],
            ]);
            
            $data = json_decode((string) $response->getBody(), true);
            
            if (isset($data['feed']) && is_array($data['feed'])) {
                $posts = [];
                
                foreach ($data['feed'] as $item) {
                    if (isset($item['post'], $item['post']['record'])) {
                        $record = $item['post']['record'];
                        $createdAt = isset($record['createdAt']) 
                            ? new \DateTime($record['createdAt']) 
                            : new \DateTime();
                        
                        $posts[] = [
                            'uri' => $item['post']['uri'] ?? '',
                            'cid' => $item['post']['cid'] ?? '',
                            'text' => $record['text'] ?? '',
                            'createdAt' => $createdAt,
                            'timestamp' => $createdAt->getTimestamp(),
                        ];
                    }
                }
                
                $this->logger?->info('Successfully fetched posts', ['count' => count($posts)]);
                return $posts;
            }
            
            $this->logger?->error('Feed response missing required fields', ['data' => $data]);
            throw new \RuntimeException('Feed response missing required fields');
        } catch (GuzzleException $e) {
            $this->logger?->error('Failed to fetch posts from BlueSky', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to fetch posts from BlueSky: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * 最新の投稿日時を取得する
     *
     * @return \DateTime|null 最新の投稿日時、投稿がない場合はnull
     */
    public function getLatestPostDate(): ?\DateTime
    {
        try {
            $posts = $this->getLatestPosts(1);
            
            if (!empty($posts) && isset($posts[0]['createdAt'])) {
                return $posts[0]['createdAt'];
            }
            
            $this->logger?->info('No posts found');
            return null;
        } catch (\Exception $e) {
            $this->logger?->error('Failed to get latest post date', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
