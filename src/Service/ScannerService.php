<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use ElephantIO\Client as ElephantClient;
use ElephantIO\Engine\SocketIO;
use Exception;
use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Exception\EtherpadServiceNotPublicException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class ScannerService
{
    private readonly ApiVersionLookupService $versionLookup;
    private readonly GithubApi $githubApi;
    private readonly Client $client;
    private readonly FileHashLookupService $fileHashLookup;
    private readonly RevisionLookupService $revisionLookup;
    private readonly VersionRangeService $versionRangeService;
    private string $baseUrl;
    private ?string $pathPrefix = null;
    private ?string $apiVersion = null;
    private string $padId;

    public function __construct(
        string $url,
        float $timeout = 2.0,
    ) {
        $stack = new HandlerStack(Utils::chooseHandler());
        $stack->push(Middleware::httpErrors(), 'http_errors');
        $stack->push(Middleware::cookies(), 'cookies');

        $this->baseUrl = $url;

        $this->client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => 2.0,
            RequestOptions::HEADERS => [
                'User-Agent' => 'EtherpadScanner/3.4.0',
            ],
            'handler' => $stack,
            'verify' => false,
        ]);

        $this->versionLookup = new ApiVersionLookupService();
        $this->fileHashLookup = new FileHashLookupService();
        $this->revisionLookup = new RevisionLookupService();
        $this->versionRangeService = new VersionRangeService();
        $this->githubApi = new GithubApi();
    }

    /**
     * @throws EtherpadServiceNotFoundException
     */
    public function scan(ScannerServiceCallbackInterface $callback): void
    {
        $this->padId = 'test' . rand(1, 99999);

        $this->scanBaseUrl($callback);
        $this->scanApi($callback);
        $this->scanStaticFiles($callback);
        $this->scanPad($callback);
        $this->scanHealth($callback);
        $this->progressVersionRanges($callback);
        $this->scanStats($callback);
        $this->scanAdmin($callback);
    }

    private function scanBaseUrl(ScannerServiceCallbackInterface $callback): void
    {
        $uri = (new Uri($this->baseUrl))
            ->withFragment('')
            ->withQuery('');

        if ($uri->getScheme() === '') {
            $uri = $uri->withScheme('http');
        }

        while (true) {
            $uriWithPad = $uri->withPath($uri->getPath() . '/p/' . $this->padId);
            $result = $this->scanForPath($uriWithPad);
            if ($result) {
                $this->baseUrl = $uri->__toString() . '/';
                $this->pathPrefix = 'p/';
                return;
            }

            $uriWithPad = $uri->withPath($uri->getPath() . '/' . $this->padId);
            $result = $this->scanForPath($uriWithPad);
            if ($result) {
                $this->baseUrl = $uri->__toString() . '/';
                return;
            }

            $pathParts = explode('/', $uri->getPath());
            if (count($pathParts) === 1) {
                try {
                    $uriApi = $uri->withPath($uri->getPath() . '/api');
                    $response = $this->client->get($uriApi->__toString());
                    $body = (string)$response->getBody();
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if (array_key_exists('currentVersion', $data) === false) {
                        throw new EtherpadServiceNotFoundException('No Etherpad service found');
                    }
                    $this->baseUrl = $uri->__toString() . '/';
                    return;
                } catch (Exception) {
                    throw new EtherpadServiceNotFoundException('No Etherpad service found');
                }
            }
            unset($pathParts[count($pathParts) - 1]);
            $uri = $uri->withPath(implode('/', $pathParts));
        }
    }

    private function scanForPath(UriInterface $uri): bool
    {
        try {
            $response = $this->client->get($uri->__toString());
            $body = (string) $response->getBody();
            $isEtherpad = $response->getStatusCode() === 200 && str_contains($body, '"editorcontainer"');
            if ($isEtherpad) {
                return true;
            }
        } catch (GuzzleException) {
        }

        return false;
    }

    private function scanApi(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanApiStart($this->baseUrl);
        try {
            $response = $this->client->get($this->baseUrl . 'api');
            $callback->onScanApiResponse($response);

            $revision = $this->getRevisionFromHeaders($response);

            $callback->onScanApiRevision($revision);
            if ($revision !== null) {

                $commit = $this->githubApi->getCommit($revision);
                if ($commit !== null) {
                    $this->versionRangeService->setRevisionVersion($this->revisionLookup->getVersion($commit['sha']));
                    $callback->onScanApiRevisionCommit($commit);
                }
            }

            try {
                $body = (string)$response->getBody();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                if (isset($data['currentVersion']) === false) {
                    throw new EtherpadServiceNotFoundException('No Etherpad service found');
                }

                $apiVersion = $data['currentVersion'];
                $versionRange = $this->versionLookup->getEtherpadVersionRange($apiVersion);
                $this->apiVersion = $apiVersion;
                $callback->onScanApiVersion($apiVersion);
                $this->versionRangeService->addVersionRange($versionRange);
            } catch (JsonException $e) {
                $callback->onScanApiException($e);
            }
        } catch (GuzzleException $e) {
            $callback->onScanApiException($e);
        }
    }

    private function scanAdmin(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanAdminStart();
        $this->getAdmin('admin', 'admin', $callback);
        $this->getAdmin('admin', 'changeme1', $callback);
        $this->getAdmin('user', 'changeme1', $callback);
    }

    private function scanPad(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanPadStart();
        $cookies = new CookieJar();
        try {
            $this->client->get($this->baseUrl . $this->pathPrefix . $this->padId, [
                'cookies' => $cookies,
            ]);
        } catch (GuzzleException $e) {
            $callback->onScanPadException($e);
        }

        $versionRange = $this->versionRangeService->calculateVersion();

        $socketIoVersion = ElephantClient::CLIENT_2X;
        if (version_compare($this->apiVersion ?? '999', '1.2.13', '<=')) {
            $socketIoVersion = ElephantClient::CLIENT_1X;
        } else if (version_compare($versionRange?->getMinVersion() ?? '0.1', '2.0.0', '>=')) {
            $socketIoVersion = ElephantClient::CLIENT_4X;
        }

        $cookieString = '';
        foreach ($cookies as $cookie) {
            $cookieString .= $cookie->getName() . '=' . $cookie->getValue() . ';';
        }
        $token = 't.vbWE289T3YggPgRVvvuP';

        try {
            $this->doSocketWebsocket($socketIoVersion, $cookieString, $callback, $token);
        } catch (Exception $e) {
            $callback->onScanPadException($e);
        }
    }

    private function getAdmin(string $user, string $password, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->post($this->baseUrl . 'admin-auth/', [
                'auth' => [$user, $password],
            ]);
            $callback->onScanAdminResult($user, $password, $response->getStatusCode() === 200);
            return;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 401) {
                $callback->onScanAdminResult($user, $password, false);
                return;
            }
        }

        try {
            $response = $this->client->get($this->baseUrl . 'admin/', [
                'auth' => [$user, $password],
            ]);
            $callback->onScanAdminResult($user, $password, $response->getStatusCode() === 200);
            return;
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
        foreach (FileHashLookupService::getFileNames() as $file) {
            $hash = $this->getFileHash($file);
            $versionRange = $this->fileHashLookup->getEtherpadVersionRange($file, $hash);
            $this->versionRangeService->addVersionRange($versionRange);
        }
    }

    private function progressVersionRanges(ScannerServiceCallbackInterface $callback): void
    {
        $versionRange = $this->versionRangeService->calculateVersion();

        if ($versionRange === null) {
            throw new EtherpadServiceNotFoundException('No version information found');
        }

        $callback->onVersionResult($versionRange->getMinVersion(), $versionRange->getMaxVersion());
    }

    private function getFileHash(string $path): ?string
    {
        try {
            $response = $this->client->get($this->baseUrl . $path, [
                'headers' => ['Accept-Encoding' => 'gzip']
            ]);
            $body = (string) $response->getBody();
            return hash('md5', $body);
        } catch (GuzzleException) {
        }

        return null;
    }

    private function scanStats(ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get($this->baseUrl . 'stats');
            $callback->onStatsResult(json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR));
        } catch (GuzzleException|JsonException $e) {
            $callback->onStatsException($e);
        }
    }

    private function scanHealth(ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get($this->baseUrl . 'health');
            $healthData = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
            $callback->onHealthResult($healthData);
            $this->versionRangeService->setHealthVersion($healthData['releaseId']);
        } catch (GuzzleException|JsonException $e) {
            $callback->onHealthException($e);
        }
    }

    private function doSocketWebsocket(
        int $socketIoVersion,
        string $cookieString,
        ScannerServiceCallbackInterface $callback,
        string $token
    ): void {
        $socketIoClient = new ElephantClient(ElephantClient::engine($socketIoVersion, $this->baseUrl . 'socket.io/', [
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

        $socketIoClient->connect();
        $socketIoClient->of('/');
        $socketIoClient->emit('message', [
            'component' => 'pad',
            'type' => 'CLIENT_READY',
            'padId' => $this->padId,
            'sessionID' => 'null',
            'token' => $token,
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

                $this->versionRangeService->setPackageVersion($version);
                $callback->onClientVars($version, $result->data);
                $callback->onScanPluginsList($onlyPlugins);
                $callback->onScanPadSuccess($engine->getTransport() === SocketIO::TRANSPORT_WEBSOCKET);
                break;
            }
        }

        $socketIoClient->disconnect();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
