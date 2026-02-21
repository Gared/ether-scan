<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

class InstanceResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $fileHash = null,
        public readonly ?string $fileContent = null,
    )
    {
    }
}
