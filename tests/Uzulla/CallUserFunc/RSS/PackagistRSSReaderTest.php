<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\RSS;

use PHPUnit\Framework\TestCase;
use Uzulla\CallUserFunc\RSS\PackagistRSSReader;

class PackagistRSSReaderTest extends TestCase
{
    private string $sampleRSS = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0">
<channel>
  <title>Packagist.org new releases</title>
  <link>https://packagist.org/feeds/releases.rss</link>
  <description>Latest releases of packages on Packagist.org</description>
  <item>
    <title>example/package (1.0.0)</title>
    <link>https://packagist.org/packages/example/package</link>
    <description>Example package description</description>
    <pubDate>Wed, 13 Mar 2025 05:00:00 +0000</pubDate>
    <guid>https://packagist.org/packages/example/package#1.0.0</guid>
  </item>
  <item>
    <title>example/older-package (0.9.0)</title>
    <link>https://packagist.org/packages/example/older-package</link>
    <description>Older package description</description>
    <pubDate>Tue, 12 Mar 2025 05:00:00 +0000</pubDate>
    <guid>https://packagist.org/packages/example/older-package#0.9.0</guid>
  </item>
</channel>
</rss>
XML;

    public function testParseRSSContent(): void
    {
        // テスト用のモッククライアントを作成
        $mockClient = $this->createMock(\GuzzleHttp\Client::class);
        $mockResponse = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('__toString')->willReturn($this->sampleRSS);
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockClient->method('get')->willReturn($mockResponse);
        
        $reader = new class($mockClient) extends PackagistRSSReader {
            private \GuzzleHttp\Client $mockClient;
            
            public function __construct(\GuzzleHttp\Client $mockClient)
            {
                parent::__construct();
                $this->mockClient = $mockClient;
            }
            
            protected function getHttpClient(): \GuzzleHttp\Client
            {
                return $this->mockClient;
            }
        };
        
        $packages = $reader->fetchPackages();
        
        $this->assertCount(2, $packages);
        $this->assertEquals('example/older-package (0.9.0)', $packages[0]['title']);
        $this->assertEquals('example/package (1.0.0)', $packages[1]['title']);
    }
    
    public function testFilterPackagesSince(): void
    {
        $reader = new PackagistRSSReader();
        
        $packages = [
            [
                'title' => 'example/package (1.0.0)',
                'pubDate' => new \DateTime('2025-03-13 05:00:00'),
                'timestamp' => (new \DateTime('2025-03-13 05:00:00'))->getTimestamp(),
            ],
            [
                'title' => 'example/older-package (0.9.0)',
                'pubDate' => new \DateTime('2025-03-12 05:00:00'),
                'timestamp' => (new \DateTime('2025-03-12 05:00:00'))->getTimestamp(),
            ],
        ];
        
        $filtered = $reader->filterPackagesSince($packages, new \DateTime('2025-03-12 12:00:00'));
        
        $this->assertCount(1, $filtered);
        $this->assertEquals('example/package (1.0.0)', $filtered[0]['title']);
    }
}
