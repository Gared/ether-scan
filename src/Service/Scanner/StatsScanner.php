<?php

namespace Gared\EtherScan\Service\Scanner;

use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

readonly class StatsScanner
{
    public function __construct(
        private Client $client,
    ) {
    }

    public function scan(Config $config, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get($config->baseUrl . 'stats', ['timeout' => $config->timeout]);
            $callback->onStatsResult(json_decode(
                json: $response->getBody()->__toString(),
                associative: true,
                flags: JSON_THROW_ON_ERROR
            ));
        } catch (GuzzleException|JsonException $e) {
            $callback->onStatsException($e);
        }
    }
}
