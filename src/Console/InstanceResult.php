<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

use Psr\Http\Message\ResponseInterface;

readonly class InstanceResult
{
    public function __construct(
        public string $name,
        public string $version,
        public ?string $fileHash = null,
        public ?ResponseInterface $response = null,
    ) {
    }
}
