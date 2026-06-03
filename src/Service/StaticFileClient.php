<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class StaticFileClient
{
    private ?ResponseInterface $lastResponse = null;

    public function __construct(
        readonly private Client $client,
    ) {
    }

    public function getFileHash(
        string $baseUrl,
        string $path,
        float $timeout = 5.0,
        ?ScannerServiceCallbackInterface $callback = null,
    ): ?string {
        $this->lastResponse = null;

        try {
            $response = $this->client->get($path, [
                'base_uri' => $baseUrl,
                RequestOptions::HEADERS => ['Accept-Encoding' => 'gzip'],
                RequestOptions::TIMEOUT => $timeout,
            ]);
            $body = (string) $response->getBody();

            $callback?->getConsoleLogger()?->debug('Fetched file: ' . $baseUrl . '/' . $path . ':' . PHP_EOL . '(chars: ' . mb_strlen($body) . '): ' . mb_substr($body, 0, 100));

            $this->lastResponse = $response;
            return hash('md5', $body);
        } catch (GuzzleException $e) {
            $callback?->getConsoleLogger()?->debug('Could not load file: ' . $baseUrl . '/' . $path . ': ' . $e->getMessage());
        }

        return null;
    }

    public function getLastResponse(): ?ResponseInterface
    {
        return $this->lastResponse;
    }
}
