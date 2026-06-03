<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Gared\EtherScan\Service\FileHashLookupService;
use Gared\EtherScan\Service\StaticFileClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Utils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
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
            ->addOption('matches-count', 'm', InputArgument::OPTIONAL, 'Minimum count of matches for version to be considered valid', 4)
            ->addArgument('file', InputArgument::REQUIRED, 'File path to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        $countVersionsMatch = (int) $input->getOption('matches-count');

        $allInstances = $this->getInstances();
        $instanceResults = new InstanceResults();
        $fileHashLookupService = new FileHashLookupService();

        $stack = new HandlerStack(Utils::chooseHandler());
        $stack->push(Middleware::httpErrors(), 'http_errors');

        $client = new Client([
            RequestOptions::CONNECT_TIMEOUT => 1.0,
            'verify' => false,
            'handler' => $stack,
        ]);
        $staticFileClient = new StaticFileClient($client);

        $allVersions = $this->getAllVersions($allInstances);
        $versionProgressBar = new ProgressBar($output, count($allVersions));
        $versionProgressBar->setFormat(' %current%/%max% - %message% [%bar%] %percent:3s%%');

        foreach ($versionProgressBar->iterate($allVersions) as $version) {
            $versionProgressBar->setMessage($version);
            $this->scanVersionInstances($staticFileClient, $allInstances, $version, $filePath, $instanceResults, $output, $countVersionsMatch);
        }

        $output->writeln('');

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

            $mappedFileHashesWithCount = array_map(
                function ($fileHash) use ($fileHashesWithCount, $instances, $output) {
                    $lineCount = null;
                    $fileContent = null;
                    $length = null;
                    foreach ($instances as $instance) {
                        if ($instance->fileHash === $fileHash) {
                            $responseBody = (string) $instance->response?->getBody();
                            $lineCount = mb_substr_count($responseBody, "\n");
                            $fileContent = mb_substr($responseBody, 0, 100);
                            $length = mb_strlen($responseBody);
                        }
                    }

                    $info = $lineCount . ' lines (' . $length . ' chars) ' . $fileHash . ' x ' . $fileHashesWithCount[$fileHash];
                    if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        return $info . PHP_EOL . $fileContent;
                    }
                    return $info;
                },
                array_keys($fileHashesWithCount),
                $fileHashesWithCount
            );
            $table->addRow([$versionString, count($instances), ...$mappedFileHashesWithCount]);
        }

        $table->render();


        $table = new Table($output);
        $table->setHeaders(['File Hash', 'Min (calculated)', 'Max (calculated)', 'Lookup Min', 'Lookup Max', 'Status']);

        foreach ($versionRanges as $fileHash => $versions) {
            usort($versions, function ($a, $b) {
                return version_compare($a, $b);
            });

            $minimumVersion = $versions[array_key_first($versions)];
            $maximumVersion = $versions[array_key_last($versions)];

            $lookupRange = $fileHashLookupService->getEtherpadVersionRange($filePath, $fileHash);

            if ($lookupRange === null) {
                $lookupMin = '<comment>unknown</comment>';
                $lookupMax = '<comment>unknown</comment>';
                $status = '<comment>NOT IN LOOKUP</comment>';
            } else {
                $lookupMin = $lookupRange->getMinVersion() ?? '*';
                $lookupMax = $lookupRange->getMaxVersion() ?? '*';

                $minMatches = $lookupRange->getMinVersion() === $minimumVersion;
                $maxMatches = $lookupRange->getMaxVersion() === $maximumVersion;

                if ($minMatches && $maxMatches) {
                    $status = '<info>✓ MATCH</info>';
                } else {
                    $status = '<error>✗ MISMATCH</error>';
                    $lookupMin = $minMatches ? $lookupMin : '<error>' . $lookupMin . '</error>';
                    $lookupMax = $maxMatches ? $lookupMax : '<error>' . $lookupMax . '</error>';
                }
            }

            $table->addRow([$fileHash, $minimumVersion, $maximumVersion, $lookupMin, $lookupMax, $status]);
        }

        $table->render();

        return self::SUCCESS;
    }

    /**
     * @param list<array{name: string, scan: array<mixed>}> $allInstances
     */
    private function scanVersionInstances(StaticFileClient $staticFileClient, array $allInstances, string $version, string $file, InstanceResults $instanceResults, OutputInterface $output, int $countVersionsMatchNeeded): void
    {
        $foundMatchesForHash = [];
        $scannedInstances = 0;

        foreach ($this->getInstancesByVersion($allInstances, $version) as $instance) {
            $fileHash = $staticFileClient->getFileHash($instance['name'], $file);
            $scannedInstances++;

            $instanceResult = new InstanceResult($instance['name'], $version, $fileHash, $staticFileClient->getLastResponse());

            if ($instanceResult->fileHash === null) {
                $output->writeln('<error>Could not get hash for instance ' . $instance['name'] . '</error>', OutputInterface::VERBOSITY_VERY_VERBOSE);

                if ($scannedInstances > 4 && count($foundMatchesForHash) === 0) {
                    break;
                }

                continue;
            }

            if ($this->matches($instanceResults, $instanceResult, $version)) {
                $output->writeln('Match found for version ' . $version . ' and hash ' . $instanceResult->fileHash, OutputInterface::VERBOSITY_VERBOSE);

                if (!isset($foundMatchesForHash[$instanceResult->fileHash])) {
                    $foundMatchesForHash[$instanceResult->fileHash] = 0;
                }

                $foundMatchesForHash[$instanceResult->fileHash]++;
                if ($foundMatchesForHash[$instanceResult->fileHash] === $countVersionsMatchNeeded) {
                    break;
                }
            }
            $instanceResults->add($instanceResult);
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
        $response = $client->get('https://ether-scan.stefans-entwicklerecke.de/api/instances?filterPackageVersion=1');

        $body = (string)$response->getBody();
        $data = json_decode($body, true);
        return $data['instances'] ?? [];
    }
}
