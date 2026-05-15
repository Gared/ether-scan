<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

readonly class StaticFileClient
{
    public function __construct(
        private Client $client,
    ) {
    }

    public function getFileHash(
        string $baseUrl,
        string $path,
        float $timeout = 5.0
    ): ?string {
        try {
            $response = $this->client->get($path, [
                'base_uri' => $baseUrl,
                'headers' => ['Accept-Encoding' => 'gzip'],
                'timeout' => $timeout,
            ]);
            $body = (string) $response->getBody();
            return hash('md5', $body);
        } catch (GuzzleException) {
        }

        return null;
    }
}
