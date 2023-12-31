<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

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

    public function onScanApiStart(): void
    {
        $this->symfonyStyle->title('Starting scan of api...');
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

    public function onScanPluginsStart(): void
    {
        $this->symfonyStyle->title('Starting scan of plugins...');
    }

    public function onScanPluginsList(array $plugins): void
    {
        if (count($plugins) === 0) {
            $this->symfonyStyle->info('No plugins found');
            return;
        }

        $this->symfonyStyle->listing(array_keys($plugins));
    }

    public function onScanPluginsException(Exception $e): void
    {
        $this->symfonyStyle->error($e->getMessage());
    }

    public function onScanAdminStart(): void
    {
        $this->symfonyStyle->title('Starting scan of admina area...');
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

        if (version_compare($maxVersion, '1.9.0', '<')) {
            $this->symfonyStyle->error('You have an old version of etherpad! Please update to 1.9.0 or newer!');
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