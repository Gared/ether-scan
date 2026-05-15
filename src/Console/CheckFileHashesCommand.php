<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Model\VersionRange;
use Gared\EtherScan\Service\FileHashLookupService;
use Gared\EtherScan\Service\StaticFileClient;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ether:check-file-hashes',
    description: 'Check file hashes of given instance'
)]
class CheckFileHashesCommand extends Command
{
    protected function configure()
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'Url to etherpad instance')
            ->addArgument('version', InputArgument::REQUIRED, 'Etherpad version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getArgument('url');
        $version = $input->getArgument('version');

        $fileHashLookup = new FileHashLookupService();
        $staticFileClient = new StaticFileClient(new Client());

        $files = FileHashLookupService::getFileNames();

        $versionRanges = [];
        foreach  ($files as $file) {
            $fileHash = $staticFileClient->getFileHash($url, $file);
            $versionRange = $fileHashLookup->getEtherpadVersionRange($file, $fileHash);
            if ($versionRange !== null) {
                $versionRanges[] = $versionRange;
            }
        }

        $versionRange = $this->calculateVersion($versionRanges);

        $output->writeln('Calculated version range: ' . $versionRange->__toString());

        if (
            (
                ($versionRange->getMinVersion() === null || version_compare($versionRange->getMinVersion(), $version, '<=')) &&
                ($versionRange->getMaxVersion() === null || version_compare($versionRange->getMaxVersion(), $version, '>='))
            ) === false
        ) {
            $output->writeln('Version mismatch');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<VersionRange> $versionRanges
     */
    private function calculateVersion(array $versionRanges): VersionRange
    {
        if (count($versionRanges) === 0) {
            throw new \Exception('No version ranges found');
        }

        $maxVersion = null;
        $minVersion = null;
        foreach ($versionRanges as $version) {
            if ($maxVersion === null || version_compare($version->getMaxVersion() ?? '', $maxVersion, '<')) {
                $maxVersion = $version->getMaxVersion();
            }

            if ($minVersion === null || version_compare($version->getMinVersion() ?? '', $minVersion, '>')) {
                $minVersion = $version->getMinVersion();
            }
        }

        return new VersionRange($minVersion, $maxVersion);
    }
}
