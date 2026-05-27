<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
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
        float $timeout = 5.0
    ): ?string {
        $this->lastResponse = null;

        try {
            $response = $this->client->get($path, [
                'base_uri' => $baseUrl,
                'headers' => ['Accept-Encoding' => 'gzip'],
                'timeout' => $timeout,
            ]);
            $this->lastResponse = $response;
            $body = (string) $response->getBody();
            return hash('md5', $body);
        } catch (GuzzleException) {
        }

        return null;
    }

    public function getLastResponse(): ?ResponseInterface
    {
        return $this->lastResponse;
    }
}
