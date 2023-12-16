<?php
declare(strict_types=1);

namespace Gared\EtherpadScanner\Model;

class VersionRange
{
    public function __construct(
        private readonly ?string $minVersion,
        private readonly ?string $maxVersion,
    ) {
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