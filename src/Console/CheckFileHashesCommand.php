<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Model\VersionRange;
use Gared\EtherScan\Service\FileHashLookupService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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

        $files = FileHashLookupService::getFileNames();

        $versionRanges = [];
        foreach  ($files as $file) {
            $fileHash = $this->getFileHash($url, $file);
            $versionRanges[] = $fileHashLookup->getEtherpadVersionRange($file, $fileHash);
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
     * @param VersionRange[] $versionRanges
     */
    private function calculateVersion(array $versionRanges): ?VersionRange
    {
        $versionRanges = array_filter($versionRanges);

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

    private function getFileHash(string $url, string $path): ?string
    {
        try {
            $client = new Client([
                'base_uri' => $url,
                'timeout' => 5.0,
            ]);
            $response = $client->get($path, [
                'headers' => ['Accept-Encoding' => 'gzip'],
            ]);

            $body = (string)$response->getBody();
            return hash('md5', $body);
        } catch (GuzzleException) {
        }

        return null;
    }
}