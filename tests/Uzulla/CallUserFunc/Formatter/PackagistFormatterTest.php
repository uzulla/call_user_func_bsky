<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\Formatter;

use PHPUnit\Framework\TestCase;
use Uzulla\CallUserFunc\Formatter\PackagistFormatter;

class PackagistFormatterTest extends TestCase
{
    public function testFormatPackage(): void
    {
        $formatter = new PackagistFormatter();
        
        $package = [
            'title' => 'example/package (1.0.0)',
            'link' => 'https://packagist.org/packages/example/package',
            'description' => 'This is a test package description',
        ];
        
        $formatted = $formatter->formatPackage($package);
        
        $this->assertStringContainsString('📦 example/package 1.0.0', $formatted);
        $this->assertStringContainsString('This is a test package description', $formatted);
        $this->assertStringContainsString('🔗 https://packagist.org/packages/example/package', $formatted);
    }
    
    public function testFormatPackageWithLongTitle(): void
    {
        $formatter = new PackagistFormatter(10);
        
        $package = [
            'title' => 'very-long-vendor/very-long-package-name (1.0.0)',
            'link' => 'https://packagist.org/packages/example/package',
            'description' => 'Description',
        ];
        
        $formatted = $formatter->formatPackage($package);
        
        $this->assertStringContainsString('📦 very-lo', $formatted);
    }
    
    public function testFormatPackageWithLongDescription(): void
    {
        $formatter = new PackagistFormatter(100, 20);
        
        $package = [
            'title' => 'example/package (1.0.0)',
            'link' => 'https://packagist.org/packages/example/package',
            'description' => 'This is a very long description that should be truncated because it exceeds the maximum length',
        ];
        
        $formatted = $formatter->formatPackage($package);
        
        $this->assertStringContainsString('This is a very lo', $formatted);
    }
    
    public function testExtractLinks(): void
    {
        $formatter = new PackagistFormatter();
        
        $package = [
            'title' => 'example/package (1.0.0)',
            'link' => 'https://packagist.org/packages/example/package',
            'description' => 'Description',
        ];
        
        $links = $formatter->extractLinks($package);
        
        $this->assertCount(1, $links);
        $this->assertEquals('https://packagist.org/packages/example/package', $links['https://packagist.org/packages/example/package']);
    }
    
    public function testFormatPackages(): void
    {
        $formatter = new PackagistFormatter();
        
        $packages = [
            [
                'title' => 'example/package1 (1.0.0)',
                'link' => 'https://packagist.org/packages/example/package1',
                'description' => 'Description 1',
            ],
            [
                'title' => 'example/package2 (2.0.0)',
                'link' => 'https://packagist.org/packages/example/package2',
                'description' => 'Description 2',
            ],
        ];
        
        $formatted = $formatter->formatPackages($packages);
        
        $this->assertCount(2, $formatted);
        $this->assertStringContainsString('📦 example/package1 1.0.0', $formatted[0]['text']);
        $this->assertStringContainsString('📦 example/package2 2.0.0', $formatted[1]['text']);
    }
    
    public function testFormatPackagesWithLimit(): void
    {
        $formatter = new PackagistFormatter();
        
        $packages = [
            [
                'title' => 'example/package1 (1.0.0)',
                'link' => 'https://packagist.org/packages/example/package1',
                'description' => 'Description 1',
            ],
            [
                'title' => 'example/package2 (2.0.0)',
                'link' => 'https://packagist.org/packages/example/package2',
                'description' => 'Description 2',
            ],
            [
                'title' => 'example/package3 (3.0.0)',
                'link' => 'https://packagist.org/packages/example/package3',
                'description' => 'Description 3',
            ],
        ];
        
        $formatted = $formatter->formatPackages($packages, 2);
        
        $this->assertCount(2, $formatted);
        $this->assertStringContainsString('📦 example/package1 1.0.0', $formatted[0]['text']);
        $this->assertStringContainsString('📦 example/package2 2.0.0', $formatted[1]['text']);
    }
}
