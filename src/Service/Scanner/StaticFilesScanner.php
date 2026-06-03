<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\FileHashLookupService;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use Gared\EtherScan\Service\StaticFileClient;
use Gared\EtherScan\Service\VersionRangeService;

readonly class StaticFilesScanner
{
    public function __construct(
        private StaticFileClient $staticFileClient,
        private FileHashLookupService $fileHashLookupService,
    ) {
    }

    public function scan(Config $config, VersionRangeService $versionRangeService, ScannerServiceCallbackInterface $callback): void
    {
        foreach (FileHashLookupService::getFileNames() as $file) {
            $hash = $this->staticFileClient->getFileHash($config->baseUrl, $file, $config->timeout, $callback);
            $versionRange = $this->fileHashLookupService->getEtherpadVersionRange($file, $hash);
            if ($versionRange !== null) {
                $callback->getConsoleLogger()?->info('File: ' . $file . ', hash: ' . $hash . ', version range: ' . $versionRange);
                $versionRangeService->addVersionRange($versionRange);
            }
        }
    }
}
