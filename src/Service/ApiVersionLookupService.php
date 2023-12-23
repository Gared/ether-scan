<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use Gared\EtherScan\Model\VersionRange;

class ApiVersionLookupService
{
    private const API_VERSIONS = [
        '1' => ['1', '1.1.1'],
        '1.1' => ['1.2', '1.2.1'],
        '1.2' => ['1.2.2', '1.2.4'],
        '1.2.1' => ['1.2.5', '1.2.6'],
        '1.2.7' => ['1.2.7', '1.3.0'],
        //'1.2.8' => '',
        '1.2.9' => ['1.4.0', '1.4.0'],
        '1.2.10' => ['1.4.1', '1.4.1'],
        '1.2.11' => ['1.5.0', '1.5.2'],
        '1.2.12' => ['1.5.3', '1.5.7'],
        '1.2.13' => ['1.6.0', '1.8.0'],
        '1.2.14' => ['1.8.1', '1.8.5'],
        '1.2.15' =>['1.8.6', '1.8.18'],
        '1.3.0' => ['1.9.0', '1.9.5'],
    ];

    public function getEtherpadVersionRange(string $apiVersion): ?VersionRange
    {
        $versionRange = self::API_VERSIONS[$apiVersion] ?? null;
        if ($versionRange !== null) {
            return new VersionRange(
                $versionRange[0],
                $versionRange[1],
            );
        }

        return null;
    }
}