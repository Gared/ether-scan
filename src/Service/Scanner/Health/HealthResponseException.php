<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner\Health;

use Exception;

class HealthResponseException extends Exception
{
    public function __construct(
        string $message,
    ) {
        parent::__construct('Health check was not successful: ' . $message);
    }
}
