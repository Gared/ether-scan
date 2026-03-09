<?php
declare(strict_types=1);

namespace Gared\EtherScan\Tests\Unit\Service\Scanner\Health;

use Gared\EtherScan\Service\Scanner\Health\HealthScanner;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use Gared\EtherScan\Service\VersionRangeService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HealthScannerTest extends TestCase
{
    public function testScanSuccess(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('get')->willReturn(new Response(200, [], '{"status":"pass","releaseId":"2.2.7"}'));
        $versionRangeService = $this->createStub(VersionRangeService::class);
        $callback = $this->createMock(ScannerServiceCallbackInterface::class);
        $callback->expects(self::once())->method('onHealthResult');
        $callback->expects(self::never())->method('onHealthException');

        $scanner = new HealthScanner($versionRangeService);
        $scanner->scan($client, 'http://example.com', $callback);
    }

    public function testScanException(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('get')->willReturn(new Response(200, [], '{"abc":"test"}'));
        $versionRangeService = $this->createStub(VersionRangeService::class);
        $callback = $this->createMock(ScannerServiceCallbackInterface::class);
        $callback->expects(self::never())->method('onHealthResult');
        $callback->expects(self::once())->method('onHealthException');

        $scanner = new HealthScanner($versionRangeService);
        $scanner->scan($client, 'http://example.com', $callback);
    }
}
