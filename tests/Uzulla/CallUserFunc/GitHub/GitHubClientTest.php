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
    
    /**
     * 実際のGitHub APIを使用してユーザーが存在するかどうかをテストする
     */
    public function testRealUserExists(): void
    {
        // このテストはGitHub APIのレート制限に依存するため、スキップする場合がある
        $this->checkGitHubRateLimit();
        
        // 実際のGitHubクライアントを作成
        $githubClient = new GitHubClient();
        
        // 実際のGitHubクライアントを作成
        $githubClient = new GitHubClient();
        
        // 既知の存在するユーザー「uzulla」をテスト
        $exists = $githubClient->userExists('uzulla');
        $this->assertTrue($exists, 'User "uzulla" should exist on GitHub');
        
        // ユーザー情報を取得
        $userInfo = $githubClient->getUserInfo('uzulla');
        $this->assertIsArray($userInfo);
        $this->assertEquals('uzulla', $userInfo['login']);
        $this->assertArrayHasKey('id', $userInfo);
        $this->assertArrayHasKey('created_at', $userInfo);
        
        // 存在しないユーザーをテスト
        $nonExistentUser = 'non-existent-user-' . uniqid();
        $exists = $githubClient->userExists($nonExistentUser);
        $this->assertFalse($exists, "User \"$nonExistentUser\" should not exist on GitHub");
    }
    
    /**
     * レート制限エラーの処理をテストする
     */
    public function testRateLimitExceeded(): void
    {
        // userExists()用のモックレスポンス
        $mockHandler1 = new MockHandler([
            new Response(403, [], '{"message":"API rate limit exceeded for 52.183.72.253. (But here\'s the good news: Authenticated requests get a higher rate limit. Check out the documentation for more details.)","documentation_url":"https://docs.github.com/rest/overview/resources-in-the-rest-api#rate-limiting"}'),
        ]);
        
        $handlerStack1 = HandlerStack::create($mockHandler1);
        $mockClient1 = new Client(['handler' => $handlerStack1]);
        
        $client1 = new class($mockClient1) extends GitHubClient {
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
        
        // レート制限エラーの場合、userExists()はtrueを返す（ユーザーが存在すると仮定）
        $exists = $client1->userExists('any-user');
        $this->assertTrue($exists, 'When rate limited, userExists() should assume the user exists');
        
        // isNewUser()用の別のモックレスポンス
        $mockHandler2 = new MockHandler([
            new Response(403, [], '{"message":"API rate limit exceeded for 52.183.72.253. (But here\'s the good news: Authenticated requests get a higher rate limit. Check out the documentation for more details.)","documentation_url":"https://docs.github.com/rest/overview/resources-in-the-rest-api#rate-limiting"}'),
        ]);
        
        $handlerStack2 = HandlerStack::create($mockHandler2);
        $mockClient2 = new Client(['handler' => $handlerStack2]);
        
        $client2 = new class($mockClient2) extends GitHubClient {
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
        
        // レート制限エラーの場合、isNewUser()はfalseを返す（ユーザーが新しくないと仮定）
        $isNew = $client2->isNewUser('any-user');
        $this->assertFalse($isNew, 'When rate limited, isNewUser() should assume the user is not new');
    }
    
    /**
     * GitHub APIのレート制限を確認し、不足している場合はテストをスキップする
     */
    private function checkGitHubRateLimit(): void
    {
        $client = new Client([
            'base_uri' => 'https://api.github.com/',
            'timeout' => 10.0,
            'headers' => [
                'User-Agent' => 'Packagist-to-BlueSky/1.0',
            ],
        ]);
        
        try {
            $response = $client->get('rate_limit');
            $responseBody = (string) $response->getBody();
            $data = json_decode($responseBody, true);
            
            if (!is_array($data)) {
                $this->markTestSkipped('Invalid response from GitHub API rate limit check.');
            }
            
            if (!isset($data['resources']) || !is_array($data['resources'])) {
                $this->markTestSkipped('Missing resources data in GitHub API response.');
            }
            
            if (!isset($data['resources']['core']) || !is_array($data['resources']['core'])) {
                $this->markTestSkipped('Missing core data in GitHub API response.');
            }
            
            if (!isset($data['resources']['core']['remaining'])) {
                $this->markTestSkipped('Missing remaining data in GitHub API response.');
            }
            
            $remainingValue = $data['resources']['core']['remaining'];
            $remaining = is_numeric($remainingValue) ? (int) $remainingValue : 0;
            
            if ($remaining <= 0) {
                $this->markTestSkipped('GitHub API rate limit exceeded. Skipping test.');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Failed to check GitHub API rate limit: ' . $e->getMessage());
        }
    }
}
