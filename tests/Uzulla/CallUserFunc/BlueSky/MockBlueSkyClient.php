<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\BlueSky;

use GuzzleHttp\Client;
use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;

/**
 * テスト用のモックBlueSkyクライアント
 */
class MockBlueSkyClient extends BlueSkyClient
{
    private Client $mockClient;
    
    /**
     * @param Client $mockClient モッククライアント
     */
    public function __construct(Client $mockClient)
    {
        $this->mockClient = $mockClient;
        parent::__construct();
    }
    
    /**
     * @inheritDoc
     */
    protected function createHttpClient(): Client
    {
        return $this->mockClient;
    }
}
