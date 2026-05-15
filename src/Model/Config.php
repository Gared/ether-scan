<?php

namespace Gared\EtherScan\Model;

class Config
{
    public readonly string $padId;
    public ?string $pathPrefix = null;

    public function __construct(
        public string $baseUrl,
        public readonly float $timeout,
    ) {
        $domain = parse_url($this->baseUrl, PHP_URL_HOST);
        if (is_string($domain) === false) {
            $domain = $this->baseUrl;
        }
        $hash = $this->generateShortHash($domain);
        $this->padId = 'test' . $hash;
    }

    private function generateShortHash(string $domain): string
    {
        $base64 = base64_encode(hash('sha256', $domain, true));
        $hashStripped = str_replace(['+', '/'], '', $base64);
        return substr($hashStripped, 0, 5);
    }
}
