<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ether:generate-file-hashes-all-versions',
    description: 'Generate file hashes for specific file on all versions of etherpad'
)]
class GenerateFileHashesAllVersionsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('matches-count', null, InputArgument::OPTIONAL, 'Minimum count of matches for version to be considered valid', 3)
            ->addArgument('file', InputArgument::REQUIRED, 'File path to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        $countVersionsMatch = $input->getOption('matches-count');

        $allInstances = $this->getInstances();
        $instanceResults = new InstanceResults();

        foreach ($this->getAllVersions($allInstances) as $version) {
            $this->scanVersionInstances($allInstances, $version, $filePath, $instanceResults, $output, $countVersionsMatch);
        }

        $listInstances = $instanceResults->getInstancesByVersion();
        uksort($listInstances, function ($a, $b) {
            return version_compare($a, $b);
        });

        $versionRanges = [];

        $table = new Table($output);
        $table->setHeaders(['Version', 'Count Instances', 'File Hashes']);
        foreach ($listInstances as $version => $instances) {
            $fileHashes = [];
            $fileHashForVersion = null;
            foreach ($instances as $instance) {
                if ($instance->fileHash !== null) {
                    $fileHashes[] = $instance->fileHash;
                }
            }

            $fileHashesWithCount = array_count_values($fileHashes);
            foreach ($fileHashesWithCount as $fileHash => $count) {
                if ($count >= $countVersionsMatch) {
                    $fileHashForVersion = $fileHash;
                }
            }

            if ($fileHashForVersion !== null) {
                $versionRanges[$fileHashForVersion][] = $version;
            }


            $versionString = '<info>' . $version . '</info>';
            if (count($instances) < $countVersionsMatch) {
                $versionString = '<comment>' . $version . '</comment>';
            } else if ($fileHashForVersion === null) {
                $versionString = '<error>' . $version . '</error>';
            }

            $table->addRow([$versionString, count($instances), ...$fileHashes]);
        }

        $table->render();


        $table = new Table($output);
        $table->setHeaders(['File Hash', 'Minimum Version', 'Maximum Version']);

        foreach ($versionRanges as $fileHash => $versions) {
            usort($versions, function ($a, $b) {
                return version_compare($a, $b);
            });

            $minimumVersion = $versions[array_key_first($versions)];
            $maximumVersion = $versions[array_key_last($versions)];

            $table->addRow([$fileHash, $minimumVersion, $maximumVersion]);
        }

        $table->render();

        return self::SUCCESS;
    }

    /**
     * @param list<array{name: string, scan: array<mixed>}> $allInstances
     */
    private function scanVersionInstances(array $allInstances, string $version, string $file, InstanceResults $instanceResults, OutputInterface $output, int $countVersionsMatchNeeded): void
    {
        $foundMatchesForHash = [];
        $scannedInstances = 0;

        foreach ($this->getInstancesByVersion($allInstances, $version) as $instance) {
            $fileContent = $this->getFile($instance['name'], $file);
            $fileHash = $fileContent !== null ? hash('md5', $fileContent) : null;
            $scannedInstances++;

            $instanceResult = new InstanceResult($instance['name'], $version, $fileHash, $fileContent);
            $instanceResults->add($instanceResult);

            if ($fileHash === null) {
                $output->writeln('<error>Could not get hash for instance ' . $instance['name'] . '</error>', OutputInterface::VERBOSITY_VERY_VERBOSE);

                if ($scannedInstances > 4 && count($foundMatchesForHash) === 0) {
                    break;
                }

                continue;
            }

            if ($this->matches($instanceResults, $instanceResult, $version)) {
                $output->writeln('Match found for version ' . $version . ' and hash ' . $instanceResult->fileHash);

                if (!isset($foundMatchesForHash[$instanceResult->fileHash])) {
                    $foundMatchesForHash[$instanceResult->fileHash] = 0;
                }

                $foundMatchesForHash[$instanceResult->fileHash]++;
                if ($foundMatchesForHash[$instanceResult->fileHash] === $countVersionsMatchNeeded) {
                    break;
                }
            } else {
//                $output->writeln('<error>Mismatch found for version ' . $version . ' and hashes ' . $fileHash . ' != ' . $lastInstanceResult->fileHash . ' for servers: ' . $instanceResult->name . ', ' . $lastInstanceResult->name . '</error>');
//                $output->writeln($lastInstanceResult->name . ': (' . mb_strlen($lastInstanceResult->fileContent) . ') ' . mb_substr($lastInstanceResult->fileContent, 0, 500), OutputInterface::VERBOSITY_DEBUG);
//                $output->writeln($instanceResult->name . ': (' . mb_strlen($instanceResult->fileContent) . ') ' . mb_substr($instanceResult->fileContent, 0, 500), OutputInterface::VERBOSITY_DEBUG);
            }
        }
    }

    private function matches(InstanceResults $instanceResults, InstanceResult $instanceResult, string $version): bool
    {
        foreach ($instanceResults->getInstancesForVersion($version) as $instance) {
            if ($instance->fileHash === $instanceResult->fileHash) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{name: string, scan: array<mixed>}> $instances
     * @param string $version
     * @return list<array{name: string, scan: array<mixed>}>
     */
    private function getInstancesByVersion(array $instances, string $version): array
    {
        $filteredInstances = [];
        foreach ($instances as $instance) {
            if ($instance['scan']['version'] === $version) {
                $filteredInstances[] = $instance;
            }
        }
        shuffle($filteredInstances);
        return $filteredInstances;
    }

    /**
     * @param list<array{name: string, scan: array<mixed>}> $instances
     * @return list<string>
     */
    private function getAllVersions(array $instances): array
    {
        $versions = [];
        foreach ($instances as $instance) {
            $version = $instance['scan']['version'];
            if (!in_array($version, $versions, true)) {
                $versions[] = $version;
            }
        }
        shuffle($versions);
        return $versions;
    }

    /**
     * @return list<array{name: string, scan: array<mixed>}>
     */
    private function getInstances(): array
    {
        $client = new Client();
        $response = $client->get('https://ether-scan.stefans-entwicklerecke.de/api/instances');

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        return $data['instances'] ?? [];
    }

    private function getFile(string $url, string $path): ?string
    {
        try {
            $client = new Client([
                'base_uri' => $url,
                RequestOptions::TIMEOUT => 3.0,
                RequestOptions::CONNECT_TIMEOUT => 1.0,
                'verify' => false,
//                'debug' => true,
            ]);
            $response = $client->get($path, [
                'headers' => ['Accept-Encoding' => 'gzip'],
            ]);

            return (string)$response->getBody();
        } catch (GuzzleException) {
        }

        return null;
    }
}
