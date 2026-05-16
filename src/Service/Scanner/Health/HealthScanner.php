<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner\Health;

use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use Gared\EtherScan\Service\VersionRangeService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

readonly class HealthScanner
{
    public function __construct(
        private Client $client,
        private VersionRangeService $versionRangeService,
    ) {
    }

    public function scan(Config $config, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get($config->baseUrl . 'health', ['timeout' => $config->timeout]);
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
