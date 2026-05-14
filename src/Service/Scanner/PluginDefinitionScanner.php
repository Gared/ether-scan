<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

readonly class PluginDefinitionScanner
{
    public function scan(Client $client, string $baseUrl, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $client->get($baseUrl . 'pluginfw/plugin-definitions.json');

            try {
                $body = (string)$response->getBody();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($data) === false || array_key_exists('plugins', $data) === false) {
                    throw new EtherpadServiceNotFoundException('No Etherpad service found');
                }

                $onlyPlugins = $data['plugins'];
                $callback->onScanPluginsList($onlyPlugins);
            } catch (JsonException $e) {
                $callback->onScanApiException($e);
            }
        } catch (GuzzleException $e) {
            $callback->onScanApiException($e);
        }
    }
}
