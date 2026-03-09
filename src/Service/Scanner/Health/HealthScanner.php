<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner\Health;

use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use Gared\EtherScan\Service\VersionRangeService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

readonly class HealthScanner
{
    public function __construct(
        private VersionRangeService $versionRangeService,
    ) {
    }

    public function scan(Client $client, string $baseUrl, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $client->get($baseUrl . 'health');
        } catch (GuzzleException $e) {
            $callback->onHealthException(new HealthResponseException($e->getMessage()));
            return;
        }

        try {
            $healthData = json_decode(json: $response->getBody()->__toString(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $callback->onHealthException(new HealthResponseException($e->getMessage()));
            return;
        }

        if (is_array($healthData) === false || array_key_exists('releaseId', $healthData) === false || is_string($healthData['releaseId']) === false) {
            $callback->onHealthException(new HealthResponseException('Invalid realeaseId. Response body: ' . $response->getBody()));
            return;
        }

        $callback->onHealthResult($healthData);
        $this->versionRangeService->setHealthVersion($healthData['releaseId']);
    }
}
