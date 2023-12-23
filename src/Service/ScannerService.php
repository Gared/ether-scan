<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use Exception;
use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\ResponseInterface;

class ScannerService
{
    private readonly ApiVersionLookupService $versionLookup;
    private readonly GithubApi $githubApi;
    private readonly Client $client;
    private readonly FileHashLookupService $fileHashLookup;
    private array $versionRanges;

    public function __construct(
        string $url,
    ) {
        $stack = new HandlerStack();
        $stack->push(Middleware::httpErrors(), 'http_errors');

        $this->client = new Client([
            'base_uri' => $url,
            'timeout' => 2.0,
            'connect_timeout' => 2.0,
            RequestOptions::HEADERS => [
                'User-Agent' => 'EtherpadScanner/1.0.0',
            ],
            'verify' => false,
        ]);

        $this->versionLookup = new ApiVersionLookupService();
        $this->fileHashLookup = new FileHashLookupService();
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
                    $callback->onScanApiRevisionCommit($commit);
                }
            }

            try {
                $body = (string)$response->getBody();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $apiVersion = $data['currentVersion'];
                $versionRange = $this->versionLookup->getEtherpadVersionRange($apiVersion);
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
        $callback->onScanPadStart();
        try {
            $this->client->get('/p/test');
            $callback->onScanPadSuccess();
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                throw new EtherpadServiceNotFoundException('Etherpad service not found');
            }
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

        $callback->onScanStaticFilesResult($minVersion, $maxVersion);
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
}