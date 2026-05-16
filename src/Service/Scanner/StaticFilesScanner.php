<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use Gared\EtherScan\Model\Config;
use Gared\EtherScan\Service\FileHashLookupService;
use Gared\EtherScan\Service\StaticFileClient;
use Gared\EtherScan\Service\VersionRangeService;

readonly class StaticFilesScanner
{
    public function __construct(
        private StaticFileClient $staticFileClient,
        private FileHashLookupService $fileHashLookupService,
        private VersionRangeService $versionRangeService,
    ) {
    }

    public function scan(Config $config): void
    {
        foreach (FileHashLookupService::getFileNames() as $file) {
            $hash = $this->staticFileClient->getFileHash($config->baseUrl, $file, $config->timeout);
            $versionRange = $this->fileHashLookupService->getEtherpadVersionRange($file, $hash);
            $this->versionRangeService->addVersionRange($versionRange);
        }
    }
}
