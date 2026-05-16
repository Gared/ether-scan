<?php
declare(strict_types=1);

namespace Gared\EtherScan\Tests\Unit\Service\Scanner;

use Exception;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\Scanner\BaseUrlScanner;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class BaseUrlScannerTest extends TestCase
{
    private MockObject&Client $httpClientMock;

    private BaseUrlScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new BaseUrlScanner(
            $this->httpClientMock = $this->createMock(Client::class),
        );
    }

    #[DataProvider('baseUrlDataProvider')]
    public function testScanBaseUrl(array $responses, string $baseUrl, string $expectedBaseUrl): void
    {
        $this->httpClientMock->expects(self::atLeast(1))->method('get')->willReturnCallback(function (string $input) use (&$responses): ResponseInterface {
            foreach ($responses as $path => $response) {
                $uri = new Uri($input);
                if ($uri->getPath() === $path) {
                    return $response;
                }
            }

            throw new Exception('Unexpected path: ' . $input);
        });

        $config = new Config($baseUrl);
        $this->scanner->scan($config);

        self::assertSame($expectedBaseUrl, $config->baseUrl);
    }

    public static function baseUrlDataProvider(): iterable
    {
        $htmlResponse = new Response(200, [], '<html></html>');

        $padHtmlResponse = new Response(200, [], '<html><div class="editorcontainer"></div></html>');

        $errorResponse = new Response(400, [], 'Error occured');

        $apiResponse = new Response(200, [], '{"currentVersion": "1.3.0"}');

        $padId = 'testSZYN5';

        yield 'no path' => [
            [
                '/p/' . $padId => $padHtmlResponse,
            ],
            'http://localhost:9001',
            'http://localhost:9001/',
        ];

        yield 'with pad path' => [
            [
                '/p/testpad' => $padHtmlResponse,
            ],
            'http://localhost:9001/p/testpad',
            'http://localhost:9001/',
        ];

        yield 'with random path' => [
            [
                '/abc/def/ghi' => $errorResponse,
                '/abc/def/ghi/p/' . $padId => $errorResponse,
                '/abc/def/ghi/' . $padId => $errorResponse,
                '/abc/def/p/' . $padId => $errorResponse,
                '/abc/def/' . $padId => $errorResponse,
                '/abc/p/' . $padId => $errorResponse,
                '/abc/' . $padId => $errorResponse,
                '/p/' . $padId => $padHtmlResponse,
            ],
            'http://localhost:9001/abc/def/ghi',
            'http://localhost:9001/',
        ];

        yield 'pad without /p/ path prefix' => [
            [
                '/p/' . $padId => $errorResponse,
                '/' . $padId => $padHtmlResponse,
            ],
            'http://localhost:9001/',
            'http://localhost:9001/',
        ];

        yield 'pad with additional path prefix' => [
            [
                '/etherpad' => $htmlResponse,
                '/etherpad/p/' . $padId => $padHtmlResponse,
            ],
            'http://localhost:9001/etherpad/',
            'http://localhost:9001/etherpad/',
        ];

        yield 'pad not found, but api route' => [
            [
                '/p/' . $padId => $errorResponse,
                '/' . $padId => $errorResponse,
                '/api' => $apiResponse,
            ],
            'http://localhost:9001/',
            'http://localhost:9001/',
        ];
    }

    public function testScanOnNonEtherpadInstanceThrowsException(): void
    {
        $this->httpClientMock->expects(self::atLeast(1))->method('get')->willReturn(new Response(400, [], '<html></html>'));

        self::expectException(EtherpadServiceNotFoundException::class);

        $config = new Config('');
        $this->scanner->scan($config);
    }
}

