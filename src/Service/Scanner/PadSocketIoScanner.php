<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use ElephantIO\Client as ElephantClient;
use ElephantIO\Engine\SocketIO;
use Exception;
use Gared\EtherScan\Exception\EtherpadServiceNotPublicException;
use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use Gared\EtherScan\Service\VersionRangeService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;

readonly class PadSocketIoScanner
{
    private const string TOKEN = 't.vbWE289T3YggPgRVvvuP';

    public function __construct(
        private Client $client,
    ) {
    }

    public function scan(Config $config, VersionRangeService $versionRangeService, ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanPadStart();
        $cookies = new CookieJar();
        try {
            $this->client->get($config->baseUrl . $config->pathPrefix . $config->padId, [
                'cookies' => $cookies,
                'timeout' => $config->timeout,
            ]);
        } catch (GuzzleException $e) {
            $callback->onScanPadException($e);
        }

        $cookieString = '';
        foreach ($cookies as $cookie) {
            $cookieString .= $cookie->getName() . '=' . $cookie->getValue() . ';';
        }

        $testVersions = [ElephantClient::CLIENT_4X, ElephantClient::CLIENT_2X, ElephantClient::CLIENT_1X];
        foreach ($testVersions as $socketIoVersion) {
            try {
                $this->connectToPad($socketIoVersion, $cookieString, $callback, $versionRangeService, $config);
                return;
            } catch (Exception $e) {
                // try next
            }
        }

        $callback->onScanPadException($e);
    }

    private function connectToPad(
        int $socketIoVersion,
        string $cookieString,
        ScannerServiceCallbackInterface $callback,
        VersionRangeService $versionRangeService,
        Config $config,
    ): void {
        $socketIoClient = new ElephantClient(ElephantClient::engine($socketIoVersion, $config->baseUrl . 'socket.io/', [
            'persistent' => false,
            'context' => [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ],
            'timeout' => $config->timeout,
            'headers' => [
                'Cookie' => $cookieString,
            ]
        ]), $callback->getConsoleLogger());

        $socketIoClient->connect();
        $socketIoClient->of('/');
        $socketIoClient->emit('message', [
            'component' => 'pad',
            'type' => 'CLIENT_READY',
            'padId' => $config->padId,
            'sessionID' => 'null',
            'token' => self::TOKEN,
            'password' => null,
            'protocolVersion' => 2,
        ]);
        $engine = $socketIoClient->getEngine();
        if ($engine instanceof SocketIO === false) {
            throw new Exception('Engine of unsupported class');
        }

        while ($result = $socketIoClient->wait('message', 2)) {
            if (is_array($result->data)) {
                $accessStatus = $result->data['accessStatus'] ?? null;
                $callback->onConnectedTransport($engine->getTransport() === SocketIO::TRANSPORT_WEBSOCKET);
                if ($accessStatus === 'deny') {
                    $callback->onScanPadException(new EtherpadServiceNotPublicException('Pads are not publicly accessible'));
                    return;
                }
                $data = $result->data;
                if (isset($data['data']['type']) && $data['data']['type'] === 'CUSTOM') {
                    continue;
                }

                $version = $data['data']['plugins']['plugins']['ep_etherpad-lite']['package']['version'];
                $onlyPlugins = $data['data']['plugins']['plugins'];
                unset($onlyPlugins['ep_etherpad-lite']);

                $versionRangeService->setPackageVersion($version);
                $callback->onClientVars($version, $result->data);
                $callback->onScanPluginsList($onlyPlugins);
                $callback->onScanPadSuccess();
                break;
            }
        }

        $socketIoClient->disconnect();
    }
}
