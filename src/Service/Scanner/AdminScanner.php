<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

readonly class AdminScanner
{
    public function __construct(
        private Client $client,
    ) {
    }

    public function scan(Config $config, ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanAdminStart();
        $this->getAdmin('admin', 'admin', $config, $callback);
        $this->getAdmin('admin', 'changeme1', $config, $callback);
        $this->getAdmin('user', 'changeme1', $config, $callback);
    }

    private function getAdmin(string $user, string $password, Config $config, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->post($config->baseUrl . 'admin-auth/', [
                'auth' => [$user, $password],
                'timeout' => $config->timeout,
            ]);
            $body = (string) $response->getBody();
            $callback->onScanAdminResult($user, $password, $response->getStatusCode() === 200 && $body === 'Authorized');
            return;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 401 || $e->getCode() === 403) {
                $callback->onScanAdminResult($user, $password, false);
                return;
            }
        }

        try {
            $response = $this->client->get($config->baseUrl . 'admin/', [
                'auth' => [$user, $password],
                'timeout' => $config->timeout,
            ]);
            $body = (string) $response->getBody();
            $callback->onScanAdminResult($user, $password, $response->getStatusCode() === 200 && str_contains($body, 'Plugin manager'));
            return;
        } catch (GuzzleException) {
            $callback->onScanAdminResult($user, $password, false);
        }
    }
}
