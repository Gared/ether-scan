<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\ApiVersionLookupService;
use Gared\EtherScan\Service\RevisionLookupService;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use Gared\EtherScan\Service\VersionRangeService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

readonly class ApiEndpointScanner
{
    public function __construct(
        private Client $client,
        private VersionRangeService $versionRangeService,
        private RevisionLookupService $revisionLookupService,
        private ApiVersionLookupService $apiVersionLookupService,
        private GithubApi $githubApi,
    ) {
    }

    public function scan(Config $config, ScannerServiceCallbackInterface $callback): void
    {
        $callback->onScanApiStart($config->baseUrl);
        try {
            $response = $this->client->get($config->baseUrl . 'api', ['timeout' => $config->timeout]);
            $callback->onScanApiResponse($response);

            $revision = $this->getRevisionFromHeaders($response);

            $callback->onScanApiRevision($revision);
            if ($revision !== null) {

                try {
                    $commit = $this->githubApi->getCommit($revision);
                } catch (RuntimeException) {
                    return;
                }
                $this->versionRangeService->setRevisionVersion($this->revisionLookupService->getVersion($commit['sha']));
                $callback->onScanApiRevisionCommit($commit);
            }

            try {
                $body = (string)$response->getBody();
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($data) === false || array_key_exists('currentVersion', $data) === false) {
                    throw new EtherpadServiceNotFoundException('No Etherpad service found');
                }

                $apiVersion = $data['currentVersion'];

                $versionRange = $this->apiVersionLookupService->getEtherpadVersionRange($apiVersion);
                $callback->onScanApiVersion($apiVersion);
                $this->versionRangeService->addVersionRange($versionRange);
            } catch (JsonException $e) {
                $callback->onScanApiException($e);
            }
        } catch (GuzzleException $e) {
            $callback->onScanApiException($e);
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
}
