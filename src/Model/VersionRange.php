<?php
declare(strict_types=1);

namespace Gared\EtherScan\Model;

use InvalidArgumentException;

readonly class VersionRange
{
    public function __construct(
        private ?string $minVersion,
        private ?string $maxVersion,
    ) {
        if ($minVersion !== null && $maxVersion !== null && version_compare($minVersion, $maxVersion, '>')) {
            throw new InvalidArgumentException('minVersion must be less than or equal to maxVersion');
        }
    }

    public function getMinVersion(): ?string
    {
        return $this->minVersion;
    }

    public function getMaxVersion(): ?string
    {
        return $this->maxVersion;
    }

    public function __toString(): string
    {
        return 'min: ' . $this->minVersion . ', max: ' . $this->maxVersion;
    }
}
