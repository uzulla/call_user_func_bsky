<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\BlueSky;

use PHPUnit\Framework\TestCase;
use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

class BlueSkyClientTest extends TestCase
{

    

    
    public function testAuthenticate(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'accessJwt' => 'mock-access-token',
                'refreshJwt' => 'mock-refresh-token',
                'did' => 'did:plc:mock123456789',
                'handle' => 'test.bsky.social',
            ])),
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack, 'base_uri' => 'https://bsky.social/xrpc/']);
        
        $client = new class($mockClient) extends BlueSkyClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                $this->mockClient = $mockClient;
                parent::__construct();
            }
            
            protected function createHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $result = $client->authenticate('test.bsky.social', 'password123');
        
        $this->assertTrue($result);
    }
    
    public function testCreatePost(): void
    {
        $mockHandler = new MockHandler([
            // 認証レスポンス
            new Response(200, [], json_encode([
                'accessJwt' => 'mock-access-token',
                'refreshJwt' => 'mock-refresh-token',
                'did' => 'did:plc:mock123456789',
                'handle' => 'test.bsky.social',
            ])),
            // 投稿レスポンス
            new Response(200, [], json_encode([
                'uri' => 'at://did:plc:mock123456789/app.bsky.feed.post/mock-post-id',
                'cid' => 'mock-cid',
            ])),
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack, 'base_uri' => 'https://bsky.social/xrpc/']);
        
        $client = new class($mockClient) extends BlueSkyClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                $this->mockClient = $mockClient;
                parent::__construct();
            }
            
            protected function createHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        // 認証
        $client->authenticate('test.bsky.social', 'password123');
        
        // 投稿
        $postUri = $client->createPost('テスト投稿です');
        
        $this->assertStringContainsString('at://did:plc:mock123456789/app.bsky.feed.post', $postUri);
    }
    
    public function testGetLatestPosts(): void
    {
        $mockHandler = new MockHandler([
            // 認証レスポンス
            new Response(200, [], json_encode([
                'accessJwt' => 'mock-access-token',
                'refreshJwt' => 'mock-refresh-token',
                'did' => 'did:plc:mock123456789',
                'handle' => 'test.bsky.social',
            ])),
            // 投稿一覧レスポンス
            new Response(200, [], json_encode([
                'feed' => [
                    [
                        'post' => [
                            'uri' => 'at://did:plc:mock123456789/app.bsky.feed.post/post1',
                            'cid' => 'cid1',
                            'record' => [
                                'text' => 'テスト投稿1',
                                'createdAt' => '2025-03-13T05:00:00Z',
                            ],
                        ],
                    ],
                    [
                        'post' => [
                            'uri' => 'at://did:plc:mock123456789/app.bsky.feed.post/post2',
                            'cid' => 'cid2',
                            'record' => [
                                'text' => 'テスト投稿2',
                                'createdAt' => '2025-03-12T05:00:00Z',
                            ],
                        ],
                    ],
                ],
            ])),
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack, 'base_uri' => 'https://bsky.social/xrpc/']);
        
        $client = new class($mockClient) extends BlueSkyClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                $this->mockClient = $mockClient;
                parent::__construct();
            }
            
            protected function createHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        // 認証
        $client->authenticate('test.bsky.social', 'password123');
        
        // 投稿一覧取得
        $posts = $client->getLatestPosts();
        
        $this->assertCount(2, $posts);
        $this->assertEquals('テスト投稿1', $posts[0]['text']);
        $this->assertEquals('テスト投稿2', $posts[1]['text']);
    }
}
