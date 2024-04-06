<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

interface ScannerServiceCallbackInterface
{
    public function onScanApiStart(): void;

    public function onScanApiResponse(ResponseInterface $response): void;

    public function onScanApiException(GuzzleException|JsonException $e): void;

    public function onScanApiRevision(?string $revision): void;

    public function onScanApiRevisionCommit(array $commit): void;

    public function onScanApiVersion(string $apiVersion): void;

    public function onScanPluginsList(array $plugins): void;

    public function onStatsResult(array $data): void;

    public function onStatsException(GuzzleException|JsonException $e): void;

    public function onHealthResult(array $data): void;

    public function onHealthException(GuzzleException|JsonException $e): void;

    public function onScanAdminStart(): void;

    public function onScanAdminResult(string $user, string $password, bool $result): void;

    public function onScanPadStart(): void;

    public function onScanPadException(Throwable $e): void;

    public function onScanPadSuccess(): void;

    public function onVersionResult(?string $minVersion, ?string $maxVersion): void;

    public function onClientVars(string $version, array $data): void;

    public function getConsoleLogger(): ?LoggerInterface;
}