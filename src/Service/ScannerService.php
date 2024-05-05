<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use ElephantIO\Client as ElephantClient;
use ElephantIO\Yeast;
use Exception;
use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Model\VersionRange;
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
    private string $baseUrl;
    private ?string $pathPrefix = null;
    private array $versionRanges;
    private ?string $apiVersion = null;
    private ?string $packageVersion = null;
    private ?string $revisionVersion = null;
    private ?string $healthVersion = null;
    private string $padId;

    public function __construct(
        string $url
    ) {
        $stack = new HandlerStack(Utils::chooseHandler());
        $stack->push(Middleware::httpErrors(), 'http_errors');
        $stack->push(Middleware::cookies(), 'cookies');

        $this->baseUrl = $url;

        $this->client = new Client([
            'timeout' => 2.0,
            'connect_timeout' => 2.0,
            RequestOptions::HEADERS => [
                'User-Agent' => 'EtherpadScanner/3.1.1',
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
            $isEtherpad = $response->getStatusCode() === 200 && str_contains($body, 'ep_etherpad-lite');
            if ($isEtherpad) {
                return true;
            }
        } catch (GuzzleException) {
        }

        return false;
    }

    private function scanApi(ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanApiStart();
        try {
            $response = $this->client->get($this->baseUrl . 'api');
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
        $callback->onScanPadStart();
        $cookies = new CookieJar();
        try {
            $this->client->get($this->baseUrl . $this->pathPrefix . $this->padId, [
                'cookies' => $cookies,
            ]);
        } catch (GuzzleException $e) {
            $callback->onScanPadException($e);
        }

        $versionRange = $this->calculateVersion();

        $socketIoVersion = ElephantClient::CLIENT_2X;
        if (version_compare($this->apiVersion ?? '999', '1.2.13', '<=')) {
            $socketIoVersion = ElephantClient::CLIENT_1X;
        } else if (version_compare($versionRange->getMinVersion() ?? '0.1', '2.0.0', '>=')) {
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

            try {
                if ($socketIoVersion === ElephantClient::CLIENT_4X) {
                    $this->doSocketPolling4($cookies, $token, $callback);
                    return;
                }

                $this->doSocketPolling($socketIoVersion, $cookies, $token, $callback);
            } catch (Exception $e) {
                $callback->onScanPadException($e);
            }
        }
    }

    private function getAdmin(string $user, string $password, ScannerServiceCallbackInterface $callback): void
    {
        try {
            $response = $this->client->get($this->baseUrl . 'admin', [
                'auth' => [$user, $password],
            ]);
            if ($response->getStatusCode() === 301) {
                $response = $this->client->post($this->baseUrl . 'admin-auth/', [
                    'auth' => [$user, $password],
                ]);
            }

            $callback->onScanAdminResult($user, $password, $response->getStatusCode() === 200);
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

    private function progressVersionRanges(ScannerServiceCallbackInterface $callback): void
    {
        $versionRange = $this->calculateVersion();

        if ($versionRange === null) {
            throw new EtherpadServiceNotFoundException('No version information found');
        }

        $callback->onVersionResult($versionRange->getMinVersion(), $versionRange->getMaxVersion());
    }

    private function calculateVersion(): ?VersionRange
    {
        if ($this->packageVersion !== null) {
            return new VersionRange($this->packageVersion, $this->packageVersion);
        }

        if ($this->healthVersion !== null) {
            return new VersionRange($this->healthVersion, $this->healthVersion);
        }

        if ($this->revisionVersion !== null) {
            return new VersionRange($this->revisionVersion, $this->revisionVersion);
        }

        $this->versionRanges = array_filter($this->versionRanges);

        if (count($this->versionRanges) === 0) {
            return null;
        }

        $maxVersion = null;
        $minVersion = null;
        foreach ($this->versionRanges as $version) {
            if ($maxVersion === null || version_compare($version->getMaxVersion() ?? '', $maxVersion, '<')) {
                $maxVersion = $version->getMaxVersion();
            }

            if ($minVersion === null || version_compare($version->getMinVersion() ?? '', $minVersion, '>')) {
                $minVersion = $version->getMinVersion();
            }
        }

        return new VersionRange($minVersion, $maxVersion);
    }

    private function getFileHash(string $path): ?string
    {
        try {
            $response = $this->client->get($this->baseUrl . $path);
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
            $this->healthVersion = $healthData['releaseId'];
        } catch (GuzzleException|JsonException $e) {
            $callback->onHealthException($e);
        }
    }

    private function doSocketPolling(
        int $socketIoVersion,
        CookieJar $cookies,
        string $token,
        ScannerServiceCallbackInterface $callback
    ): void {
        $engine = ElephantClient::engine($socketIoVersion, '');

        $queryParameters = [
            'padId' => $this->padId,
            'EIO' => $engine->getOptions()['version'],
            'transport' => 'polling',
            't' => Yeast::yeast(),
            'b64' => 1,
        ];

        $response = $this->client->get($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        if ($body === 'Welcome to socket.io.') {
            $this->packageVersion = '1.4.0';
            throw new Exception('Socket.io 1 not supported');
        }
        $curlyBracketPos = strpos($body, '{');
        if ($curlyBracketPos === false) {
            throw new Exception('No JSON response: ' . $body);
        }
        $body = substr($body, $curlyBracketPos);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $sid = $data['sid'];

        $queryParameters['sid'] = $sid;
        $queryParameters['t'] = Yeast::yeast();

        $response = $this->client->get($this->baseUrl . 'socket.io/', [
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
                'padId' => $this->padId,
                'sessionID' => 'null',
                'token' => $token,
                'password' => null,
                'protocolVersion' => 2,
            ]
        ]);

        $queryParameters['t'] = Yeast::yeast();
        $response = $this->client->post($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'body' => (mb_strlen($postData) + 2) . ':42' . $postData,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        if ($body !== 'ok') {
            throw new Exception('Invalid response: ' . $body);
        }

        $queryParameters['t'] = Yeast::yeast();
        $response = $this->client->get($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $this->handleClientVarsResponse($response, $callback);
    }

    private function doSocketPolling4(
        CookieJar $cookies,
        string $token,
        ScannerServiceCallbackInterface $callback
    ): void {
        $queryParameters = [
            'padId' => $this->padId,
            'EIO' => 4,
            'transport' => 'polling',
            't' => Yeast::yeast(),
            'b64' => 1,
        ];

        $response = $this->client->get($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        $curlyBracketPos = strpos($body, '{');
        if ($curlyBracketPos === false) {
            throw new Exception('No JSON response: ' . $body);
        }
        $body = substr($body, $curlyBracketPos);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $sid = $data['sid'];

        $queryParameters['sid'] = $sid;
        $queryParameters['t'] = Yeast::yeast();

        $response = $this->client->post($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
            'body' => '40',
        ]);
        $body = (string)$response->getBody();
        if ($body !== 'ok') {
            throw new Exception('Invalid response: ' . $body);
        }

        $queryParameters['t'] = Yeast::yeast();

        $response = $this->client->get($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();

        if (str_starts_with($body, '40') === false) {
            throw new Exception('Invalid response: ' . $body);
        }

        $postData = json_encode([
            'message',
            [
                'component' => 'pad',
                'type' => 'CLIENT_READY',
                'padId' => $this->padId,
                'sessionID' => 'null',
                'token' => $token,
                'password' => null,
                'protocolVersion' => 2,
            ]
        ]);

        $queryParameters['t'] = Yeast::yeast();
        $response = $this->client->post($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'body' => '42' . $postData,
            'cookies' => $cookies,
        ]);
        $body = (string)$response->getBody();
        if ($body !== 'ok') {
            throw new Exception('Invalid response: ' . $body);
        }

        $queryParameters['t'] = Yeast::yeast();
        $response = $this->client->get($this->baseUrl . 'socket.io/', [
            'query' => $queryParameters,
            'cookies' => $cookies,
        ]);
        $this->handleClientVarsResponse($response, $callback);
    }

    private function handleClientVarsResponse(
        ResponseInterface $response,
        ScannerServiceCallbackInterface $callback,
    ): void
    {
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
        $onlyPlugins = $data['data']['plugins']['plugins'];
        unset($onlyPlugins['ep_etherpad-lite']);

        $this->packageVersion = $version;
        $callback->onClientVars($version, $data);
        $callback->onScanPluginsList($onlyPlugins);
        $callback->onScanPadSuccess();
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

        $socketIoClient->initialize();
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
                $onlyPlugins = $data['data']['plugins']['plugins'];
                unset($onlyPlugins['ep_etherpad-lite']);

                $this->packageVersion = $version;
                $callback->onClientVars($version, $result->data);
                $callback->onScanPluginsList($onlyPlugins);
                $callback->onScanPadSuccess();
                break;
            }
        }

        $socketIoClient->close();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}