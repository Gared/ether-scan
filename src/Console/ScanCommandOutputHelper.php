<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Gared\EtherScan\Service\ScannerServiceCallbackInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class ScanCommandOutputHelper implements ScannerServiceCallbackInterface
{
    public function __construct(
        private readonly SymfonyStyle $symfonyStyle,
        private readonly OutputInterface $output,
    ) {
    }

    public function onScanApiStart(string $baseUrl): void
    {
        $this->symfonyStyle->title('Starting scan of api: ' . $baseUrl);
    }

    public function onScanApiResponse(ResponseInterface $response): void
    {
        // TODO: Implement onScanApiResponse() method.
    }

    public function onScanApiException(GuzzleException|JsonException $e): void
    {
        $this->symfonyStyle->error($e->getMessage());
    }

    public function onScanApiRevision(?string $revision): void
    {
        if ($revision === null) {
            $this->symfonyStyle->info('No revision in server header');
            return;
        }

        $this->symfonyStyle->info('Revision in server header: ' . $revision);
    }

    public function onScanApiRevisionCommit(array $commit): void
    {
        $this->symfonyStyle->info('commit date: ' . $commit['commit']['committer']['date']);
    }

    public function onScanApiVersion(string $apiVersion): void
    {
        $text = 'api version: ' . $apiVersion;
        $this->symfonyStyle->info($text);
    }

    public function onScanPluginsList(array $plugins): void
    {
        if (count($plugins) === 0) {
            $this->symfonyStyle->info('No plugins found');
            return;
        }

        $this->symfonyStyle->writeln('Plugins:');

        $pluginData = [];
        foreach ($plugins as $pluginName => $plugin) {
            $pluginData[] = $pluginName . '@' . $plugin['package']['version'];
        }
        sort($pluginData);

        $this->symfonyStyle->listing($pluginData);
    }

    public function onStatsResult(array $data): void
    {
        if (isset($data['httpStartTime'])) {
            $startTime = new DateTimeImmutable('@' . ($data['httpStartTime'] / 1000));
            $this->symfonyStyle->info('Server running since: ' . $startTime->format(DateTimeInterface::RFC3339));
        }
        if (isset($data['ueberdb_writesFailed']) && $data['ueberdb_writesFailed'] > 0) {
            $this->symfonyStyle->error('Database writes failed: ' . $data['ueberdb_writesFailed']);
        }
        if (isset($data['ueberdb_readsFailed']) && $data['ueberdb_readsFailed'] > 0) {
            $this->symfonyStyle->error('Database reads failed: ' . $data['ueberdb_readsFailed']);
        }
        $this->output->writeln('Stats: ' . print_r($data, true), OutputInterface::VERBOSITY_DEBUG);
    }

    public function onStatsException(GuzzleException|JsonException $e): void
    {
        $this->symfonyStyle->error($e->getMessage());
    }

    public function onHealthResult(array $data): void
    {
        if (isset($data['status']) && $data['status'] === 'pass') {
            $this->symfonyStyle->success('Server is healthy');
            return;
        }
        $this->symfonyStyle->error('Health: ' . print_r($data, true));
    }

    public function onHealthException(GuzzleException|JsonException $e): void
    {
        $this->symfonyStyle->error($e->getMessage());
    }

    public function onScanAdminStart(): void
    {
        $this->symfonyStyle->title('Starting scan of admin area...');
    }

    public function onScanAdminResult(string $user, string $password, bool $result): void
    {
        if ($result) {
            $this->symfonyStyle->error('Admin area is accessible with ' . $user . ' / ' . $password);
            return;
        }

        $this->symfonyStyle->success('Admin area is not accessible with ' . $user . ' / ' . $password);
    }

    public function onScanPadStart(): void
    {
        $this->symfonyStyle->title('Starting scan of a pad...');
    }

    public function onScanPadException(Throwable $e): void
    {
        $this->symfonyStyle->error($e->getMessage());
    }

    public function onScanPadSuccess(): void
    {
        $this->symfonyStyle->success('Pads are publicly accessible');
    }

    public function onVersionResult(?string $minVersion, ?string $maxVersion): void
    {
        if ($maxVersion === null && $minVersion === null) {
            $this->symfonyStyle->info('Could not determine version');
            return;
        }

        if ($maxVersion === null) {
            $this->symfonyStyle->info('Version greater than ' . $minVersion);
            return;
        }

        if (version_compare($maxVersion, '2.0.0', '<')) {
            $this->symfonyStyle->error('You have an old version of etherpad! Please update to 2.0.0 or newer!');
        }

        if ($minVersion === null) {
            $this->symfonyStyle->info('Version less than ' . $maxVersion);
            return;
        }

        if ($minVersion === $maxVersion) {
            $this->symfonyStyle->info('Version is ' . $maxVersion);
            return;
        }

        $this->symfonyStyle->info('Version between ' . $minVersion . ' and ' . $maxVersion);
    }

    public function onClientVars(string $version, array $data): void
    {
        $this->symfonyStyle->info('Package version: ' . $version);
    }

    public function getConsoleLogger(): ?LoggerInterface
    {
        return new ConsoleLogger($this->output);
    }
}
