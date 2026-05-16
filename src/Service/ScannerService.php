<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use Gared\EtherScan\Api\GithubApi;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\Scanner\AdminScanner;
use Gared\EtherScan\Service\Scanner\ApiEndpointScanner;
use Gared\EtherScan\Service\Scanner\BaseUrlScanner;
use Gared\EtherScan\Service\Scanner\Health\HealthScanner;
use Gared\EtherScan\Service\Scanner\PluginDefinitionScanner;
use Gared\EtherScan\Service\Scanner\StaticFilesScanner;
use Gared\EtherScan\Service\Scanner\StatsScanner;
use Gared\EtherScan\Service\Scanner\PadSocketIoScanner;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;

class ScannerService
{
    private readonly Client $client;
    private readonly FileHashLookupService $fileHashLookup;
    private readonly VersionRangeService $versionRangeService;
    private readonly ApiEndpointScanner $apiEndpointScanner;
    private HealthScanner $healthScanner;
    private PluginDefinitionScanner $pluginDefinitionScanner;
    private BaseUrlScanner $baseUrlScanner;
    private StaticFilesScanner $staticFilesScanner;
    private AdminScanner $adminScanner;
    private PadSocketIoScanner $padSocketIoScanner;
    private StatsScanner $statsScanner;

    public function __construct()
    {
        $stack = new HandlerStack(Utils::chooseHandler());
        $stack->push(Middleware::httpErrors(), 'http_errors');
        $stack->push(Middleware::cookies(), 'cookies');

        $this->client = new Client([
            'timeout' => 10.0,
            'connect_timeout' => 2.0,
            RequestOptions::HEADERS => [
                'User-Agent' => 'EtherpadScanner/4.0.0',
            ],
            'handler' => $stack,
            'verify' => false,
        ]);

        $versionLookup = new ApiVersionLookupService();
        $this->fileHashLookup = new FileHashLookupService();
        $revisionLookup = new RevisionLookupService();
        $this->versionRangeService = new VersionRangeService();
        $githubApi = new GithubApi();
        $this->apiEndpointScanner = new ApiEndpointScanner(
            $this->client,
            $this->versionRangeService,
            $revisionLookup,
            $versionLookup,
            $githubApi,
        );
        $this->healthScanner = new HealthScanner($this->client, $this->versionRangeService);
        $this->pluginDefinitionScanner = new PluginDefinitionScanner($this->client);
        $this->baseUrlScanner = new BaseUrlScanner($this->client);
        $this->staticFilesScanner = new StaticFilesScanner(
            new StaticFileClient($this->client),
            $this->fileHashLookup,
            $this->versionRangeService
        );
        $this->adminScanner = new AdminScanner($this->client);
        $this->padSocketIoScanner = new PadSocketIoScanner(
            $this->client,
            $this->versionRangeService,
        );
        $this->statsScanner = new StatsScanner($this->client);
    }

    /**
     * @throws EtherpadServiceNotFoundException
     */
    public function scan(
        string $url,
        ScannerServiceCallbackInterface $callback,
        float $timeout = 2.0,
    ): Config {
        $config = new Config($url, $timeout);

        $this->baseUrlScanner->scan($config);
        $this->apiEndpointScanner->scan($config, $callback);
        $this->staticFilesScanner->scan($config);
        $this->padSocketIoScanner->scan($config, $callback);
        if ($this->versionRangeService->getPackageVersion() === null) {
            $this->pluginDefinitionScanner->scan($config, $callback);
        }
        $this->healthScanner->scan($config, $callback);
        $this->progressVersionRanges($callback);
        $this->statsScanner->scan($config, $callback);
        $this->adminScanner->scan($config, $callback);

        return $config;
    }

    private function progressVersionRanges(ScannerServiceCallbackInterface $callback): void
    {
        $versionRange = $this->versionRangeService->calculateVersion();

        if ($versionRange === null) {
            throw new EtherpadServiceNotFoundException('No version information found');
        }

        $callback->onVersionResult($versionRange->getMinVersion(), $versionRange->getMaxVersion());
    }
}
