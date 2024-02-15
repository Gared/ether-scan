<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use ElephantIO\Client as ElephantClient;
use ElephantIO\Yeast;
use Exception;
use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use JsonException;
use Psr\Http\Message\ResponseInterface;

class ScannerService
{
    private readonly ApiVersionLookupService $versionLookup;
    private readonly GithubApi $githubApi;
    private readonly Client $client;
    private readonly string $url;
    private readonly FileHashLookupService $fileHashLookup;
    private readonly RevisionLookupService $revisionLookup;
    private array $versionRanges;
    private ?string $apiVersion = null;
    private ?string $packageVersion = null;
    private ?string $revisionVersion = null;
    private ?string $healthVersion = null;

    public function __construct(
        string $url
    ) {
        $stack = new HandlerStack(Utils::chooseHandler());
        $stack->push(Middleware::httpErrors(), 'http_errors');
        $stack->push(Middleware::cookies(), 'cookies');

        $this->url = $url;

        $this->client = new Client([
            'base_uri' => $url,
            'timeout' => 2.0,
            'connect_timeout' => 2.0,
            RequestOptions::HEADERS => [
                'User-Agent' => 'EtherpadScanner/2.1.0',
            ],
            'handler' => $stack,
            'verify' => false,
        ]);

        $this->versionLookup = new ApiVersionLookupService();
        $this->fileHashLookup = new FileHashLookupService();
        $this->revisionLookup = new RevisionLookupService();
        $this->githubApi = new GithubApi();
    }

    /**
     * @throws EtherpadServiceNotFoundException
     */
    public function scan(ScannerServiceCallbackInterface $callback): void
    {
        $this->versionRanges = [];

        $this->scanApi($callback);
        $this->scanStaticFiles($callback);
        $this->scanPad($callback);
        $this->scanHealth($callback);
        $this->calculateVersion($callback);
        $this->scanStats($callback);
        $this->scanAdmin($callback);
        $this->scanPlugins($callback);
    }

    private function scanApi(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanApiStart();
        try {
            $response = $this->client->get('/api');
            $callback->onScanApiResponse($response);

            $revision = $this->getRevisionFromHeaders($response);

            $callback->onScanApiRevision($revision);
            if ($revision !== null) {

                $commit = $this->githubApi->getCommit($revision);
                if ($commit !== null) {
                    $this->revisionVersion = $this->revisionLookup->getVersion($commit['sha']);
                    $callback->onScanApiRevisionCommit($commit);
                }
            }

            try {
                $body = (string)$response->getBody();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $apiVersion = $data['currentVersion'];
                $versionRange = $this->versionLookup->getEtherpadVersionRange($apiVersion);
                $this->apiVersion = $apiVersion;
                $callback->onScanApiVersion($apiVersion);
                $this->versionRanges[] = $versionRange;
            } catch (JsonException $e) {
                $callback->onScanApiException($e);
            }
        } catch (GuzzleException $e) {
            $callback->onScanApiException($e);
        }
    }

    private function scanPlugins(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanPluginsStart();
        try {
            $response = $this->client->get('/pluginfw/plugin-definitions.json');
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $callback->onScanPluginsList($data['plugins']);
        } catch (Exception $e) {
            $callback->onScanPluginsException($e);
        }
    }

    private function scanAdmin(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanAdminStart();
        $this->getAdmin('admin', 'admin', $callback);
        $this->getAdmin('admin', 'changeme1', $callback);
        $this->getAdmin('user', 'changeme1', $callback);
    }

    /**
     * @throws EtherpadServiceNotFoundException
     */
    private function scanPad(ScannerServiceCallbackInterface $callback): void
    {
        $padId = 'test' . rand(1, 99999);

        $callback->onScanPadStart();
        $cookies = new CookieJar();
        try {
            $response = $this->client->get('/p/' . $padId, [
                'cookies' => $cookies,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new EtherpadServiceNotFoundException('Etherpad service not found');
            }
        } catch (GuzzleException $e) {
            $callback->onScanPadException($e);
        }

        $socketIoVersion = ElephantClient::CLIENT_2X;
        if (version_compare($this->apiVersion ?? '999', '1.2.13', '<=')) {
            $socketIoVersion = ElephantClient::CLIENT_1X;
        }

        $cookieString = '';
        foreach ($cookies as $cookie) {
            $cookieString .= $cookie->getName() . '=' . $cookie->getValue() . ';';
        }
        $token = 't.vbWE289T3YggPgRVvvuP';

        try {
            $this->doSocketWebsocket($socketIoVersion, $cookieString, $callback, $padId, $token);
        } catch (Exception $e) {
            $callback->onScanPadException($e);
        }
    }

    private function getAdmin(string $user, string $password, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $this->client->get('/admin', [
                'auth' => [$user, $password],
            ]);

            $callback->onScanAdminResult($user, $password, true);
        } catch (GuzzleException) {
            $callback->onScanAdminResult($user, $password, false);
        }
    }

    private function getRevisionFromHeaders(ResponseInterface $response): ?string
    {
        $serverHeaders = $response->getHeader('Server');
        if (count($serverHeaders) === 0) {
            return null;
        }

        $matches = null;
        preg_match('/Etherpad(-Lite)?\s([0-9a-z]+)/', $serverHeaders[0], $matches);
        return $matches[2] ?? null;
    }

    private function scanStaticFiles(ScannerServiceCallbackInterface $callback): void
    {
        $hash = $this->getFileHash('static/js/AttributePool.js');
        $this->versionRanges[] = $this->fileHashLookup->getEtherpadVersionRange('static/js/AttributePool.js', $hash);
        $hash = $this->getFileHash('static/js/attributes.js');
        $this->versionRanges[] = $this->fileHashLookup->getEtherpadVersionRange('static/js/attributes.js', $hash);
        $hash = $this->getFileHash('static/js/pad_editbar.js');
        $this->versionRanges[] = $this->fileHashLookup->getEtherpadVersionRange('static/js/pad_editbar.js', $hash);
        $hash = $this->getFileHash('static/js/pad.js');
        $this->versionRanges[] = $this->fileHashLookup->getEtherpadVersionRange('static/js/pad.js', $hash);
        $hash = $this->getFileHash('static/js/pad_utils.js');
        $this->versionRanges[] = $this->fileHashLookup->getEtherpadVersionRange('static/js/pad_utils.js', $hash);
    }

    private function calculateVersion(ScannerServiceCallbackInterface $callback): void
    {
        if ($this->packageVersion !== null) {
            $callback->onVersionResult($this->packageVersion, $this->packageVersion);
            return;
        }

        if ($this->healthVersion !== null) {
            $callback->onVersionResult($this->healthVersion, $this->healthVersion);
            return;
        }

        if ($this->revisionVersion !== null) {
            $callback->onVersionResult($this->revisionVersion, $this->revisionVersion);
            return;
        }

        $maxVersion = null;
        $minVersion = null;
        foreach ($this->versionRanges as $version) {
            if ($version === null) {
                continue;
            }

            if ($maxVersion === null || version_compare($version->getMaxVersion() ?? '', $maxVersion, '<')) {
                $maxVersion = $version->getMaxVersion();
            }

            if ($minVersion === null || version_compare($version->getMinVersion() ?? '', $minVersion, '>')) {
                $minVersion = $version->getMinVersion();
            }
        }

        $callback->onVersionResult($minVersion, $maxVersion);
    }

    private function getFileHash(string $path): ?string
    {
        try {
            $response = $this->client->get($path);
            $body = (string) $response->getBody();
            return hash('md5', $body);
        } catch (GuzzleException) {
        }

        return null;
    }

    private function scanStats(ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get('/stats');
            $callback->onStatsResult(json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR));
        } catch (GuzzleException|JsonException $e) {
            $callback->onStatsException($e);
        }
    }

    private function scanHealth(ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get('/health');
            $healthData = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
            $callback->onHealthResult($healthData);
            $this->healthVersion = $healthData['releaseId'];
        } catch (GuzzleException|JsonException $e) {
            $callback->onHealthException($e);
        }
    }

    private function doSocketPolling(
        string $padId,
        CookieJar $cookies,
        string $token,
        ScannerServiceCallbackInterface $callback
    ): void {
        $queryParameters = [
            'padId' => $padId,
            'EIO' => 3,
            'transport' => 'polling',
            't' => Yeast::yeast(),
            'b64' => 1,
        ];

        $response = $this->client->get('/socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        if ($body === 'Welcome to socket.io.') {
            $this->packageVersion = '1.4.0';
            throw new Exception('Socket.io 1 not supported');
        }
        $body = substr($body, strpos($body, '{'));
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $sid = $data['sid'];

        $queryParameters['sid'] = $sid;
        $queryParameters['t'] = Yeast::yeast();

        $response = $this->client->get('/socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        if ($body !== '2:40') {
            throw new Exception('Invalid response: ' . $body);
        }

        $postData = json_encode([
            'message',
            [
                'component' => 'pad',
                'type' => 'CLIENT_READY',
                'padId' => $padId,
                'sessionID' => 'null',
                'token' => $token,
                'password' => null,
                'protocolVersion' => 2,
            ]
        ]);

        $queryParameters['t'] = Yeast::yeast();
        $response = $this->client->post('/socket.io/', [
            'query' => $queryParameters,
            'body' => (mb_strlen($postData) + 2) . ':42' . $postData,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        if ($body !== 'ok') {
            throw new Exception('Invalid response: ' . $body);
        }

        $queryParameters['t'] = Yeast::yeast();
        $response = $this->client->get('/socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        $body = substr($body, strpos($body, '['));
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $data = $data[1];
        $accessStatus = $data['accessStatus'] ?? null;
        if ($accessStatus === 'deny') {
            $callback->onScanPadException(new EtherpadServiceNotFoundException('Pads are not publicly accessible'));
            return;
        }

        $version = $data['data']['plugins']['plugins']['ep_etherpad-lite']['package']['version'];
        $this->packageVersion = $version;
        $callback->onClientVars($version, $data);
        $callback->onScanPadSuccess();
    }

    private function doSocketWebsocket(
        int $socketIoVersion,
        string $cookieString,
        ScannerServiceCallbackInterface $callback,
        string $padId,
        string $token
    ): void {
        $socketIoClient = new ElephantClient(ElephantClient::engine($socketIoVersion, $this->url . '/socket.io/', [
            'persistent' => false,
            'context' => [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ],
            'headers' => [
                'Cookie' => $cookieString,
            ]
        ]), $callback->getConsoleLogger());

        $socketIoClient->initialize();
        $socketIoClient->of('/');
        $socketIoClient->emit('message', [
            'component' => 'pad',
            'type' => 'CLIENT_READY',
            'padId' => $padId,
            'sessionID' => 'null',
            'token' => $token,
            'password' => null,
            'protocolVersion' => 2,
        ]);

        $expirationTime = microtime(true) + 2;

        while (microtime(true) < $expirationTime) {
            usleep(10000);
            $result = $socketIoClient->drain();
            if ($result !== null && is_array($result->data)) {
                $accessStatus = $result->data['accessStatus'] ?? null;
                if ($accessStatus === 'deny') {
                    $callback->onScanPadException(new EtherpadServiceNotFoundException('Pads are not publicly accessible'));
                    return;
                }
                $data = $result->data;
                if (isset($data['data']['type']) && $data['data']['type'] === 'CUSTOM') {
                    continue;
                }

                $version = $data['data']['plugins']['plugins']['ep_etherpad-lite']['package']['version'];
                $this->packageVersion = $version;
                $callback->onClientVars($version, $result->data);
                $callback->onScanPadSuccess();
                break;
            }
        }

        $socketIoClient->close();
    }
}