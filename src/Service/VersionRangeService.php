<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use Gared\EtherScan\Model\VersionRange;

class VersionRangeService
{
    /**
     * @var list<VersionRange>
     */
    private array $versionRanges = [];
    private ?string $packageVersion = null;
    private ?string $revisionVersion = null;
    private ?string $healthVersion = null;

    public function calculateVersion(): ?VersionRange
    {
        if ($this->packageVersion !== null) {
            return new VersionRange($this->packageVersion, $this->packageVersion);
        }

        if ($this->healthVersion !== null) {
            return new VersionRange($this->healthVersion, $this->healthVersion);
        }

        if ($this->revisionVersion !== null) {
            return new VersionRange($this->revisionVersion, $this->revisionVersion);
        }

        if (count($this->versionRanges) === 0) {
            return null;
        }

        $maxVersion = null;
        $minVersion = null;
        foreach ($this->versionRanges as $version) {
            if ($maxVersion === null || version_compare($version->getMaxVersion() ?? '', $maxVersion, '<')) {
                $maxVersion = $version->getMaxVersion();
            }

            if ($minVersion === null || version_compare($version->getMinVersion() ?? '', $minVersion, '>')) {
                $minVersion = $version->getMinVersion();
            }
        }

        return new VersionRange($minVersion, $maxVersion);
    }

    public function setPackageVersion(?string $packageVersion): void
    {
        $this->packageVersion = $packageVersion;
    }

    public function setRevisionVersion(?string $revisionVersion): void
    {
        $this->revisionVersion = $revisionVersion;
    }

    public function setHealthVersion(?string $healthVersion): void
    {
        $this->healthVersion = $healthVersion;
    }

    public function addVersionRange(?VersionRange $versionRange): void
    {
        if ($versionRange === null) {
            return;
        }

        $this->versionRanges[] = $versionRange;
    }
}
