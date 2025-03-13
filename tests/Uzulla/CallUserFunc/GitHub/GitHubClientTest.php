<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\GitHub;

use PHPUnit\Framework\TestCase;
use Uzulla\CallUserFunc\GitHub\GitHubClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

class GitHubClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private $container = [];
    
    /**
     * @param array<int, Response> $responses
     */
    private function createMockClient(array $responses): Client
    {
        $this->container = [];
        /** @var callable $history */
        $history = Middleware::history($this->container);
        
        /** @var array<int, mixed> $queue */
        $queue = $responses;
        $mock = new MockHandler($queue);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        
        return new Client(['handler' => $handlerStack]);
    }
    
    public function testGetUserInfo(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode([
                'login' => 'octocat',
                'id' => 583231,
                'created_at' => '2011-01-25T18:44:36Z',
            ])),
        ]);
        
        $client = new class($mockClient) extends GitHubClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $userInfo = $client->getUserInfo('octocat');
        
        $this->assertIsArray($userInfo);
        $this->assertEquals('octocat', $userInfo['login']);
        $this->assertEquals(583231, $userInfo['id']);
        $this->assertEquals('2011-01-25T18:44:36Z', $userInfo['created_at']);
    }
    
    public function testGetUserInfoNotFound(): void
    {
        $mockHandler = new MockHandler([
            new Response(404, [], '{"message": "Not Found"}'),
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        
        $client = new class($mockClient) extends GitHubClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $userInfo = $client->getUserInfo('non-existent-user');
        
        $this->assertNull($userInfo);
    }
    
    public function testUserExists(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode([
                'login' => 'octocat',
                'id' => 583231,
                'created_at' => '2011-01-25T18:44:36Z',
            ])),
        ]);
        
        $client = new class($mockClient) extends GitHubClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $exists = $client->userExists('octocat');
        
        $this->assertTrue($exists);
    }
    
    public function testUserDoesNotExist(): void
    {
        $mockHandler = new MockHandler([
            new Response(404, [], '{"message": "Not Found"}'),
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        
        $client = new class($mockClient) extends GitHubClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $exists = $client->userExists('non-existent-user');
        
        $this->assertFalse($exists);
    }
    
    public function testIsNewUser(): void
    {
        // 現在の日時から2日前の日付を生成
        $recentDate = (new \DateTime())->modify('-2 days')->format('Y-m-d\TH:i:s\Z');
        
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'login' => 'newuser',
                'id' => 12345,
                'created_at' => $recentDate,
            ])),
        ]);
        
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);
        
        $client = new class($mockClient) extends GitHubClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $isNew = $client->isNewUser('newuser');
        
        $this->assertTrue($isNew);
    }
    
    public function testIsNotNewUser(): void
    {
        // 現在の日時から30日前の日付を生成
        $oldDate = (new \DateTime())->modify('-30 days')->format('Y-m-d\TH:i:s\Z');
        
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode([
                'login' => 'olduser',
                'id' => 67890,
                'created_at' => $oldDate,
            ])),
        ]);
        
        $client = new class($mockClient) extends GitHubClient {
            private Client $mockClient;
            
            public function __construct(Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): Client
            {
                return $this->mockClient;
            }
        };
        
        $isNew = $client->isNewUser('olduser');
        
        $this->assertFalse($isNew);
    }
    
    public function testExtractUsernameFromRepositoryUrl(): void
    {
        $client = new GitHubClient();
        
        $username = $client->extractUsernameFromRepositoryUrl('https://github.com/octocat/Hello-World');
        
        $this->assertEquals('octocat', $username);
    }
    
    public function testExtractUsernameFromInvalidRepositoryUrl(): void
    {
        $client = new GitHubClient();
        
        $username = $client->extractUsernameFromRepositoryUrl('https://gitlab.com/octocat/Hello-World');
        
        $this->assertNull($username);
    }
}
