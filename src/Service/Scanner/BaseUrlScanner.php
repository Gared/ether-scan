<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service\Scanner;

use Exception;
use Gared\EtherScan\Exception\EtherpadServiceNotFoundException;
use Gared\EtherScan\Model\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

readonly class BaseUrlScanner
{
    public function __construct(
        private Client $client,
    ) {
    }

    public function scan(Config $config): void
    {
        $uri = (new Uri($config->baseUrl))
            ->withFragment('')
            ->withQuery('');

        if ($uri->getScheme() === '') {
            $uri = $uri->withScheme('http');
        }

        while (true) {
            $uriWithPad = $uri->withPath($uri->getPath() . '/p/' . $config->padId);
            $result = $this->scanForPath($uriWithPad, $config);
            if ($result) {
                $config->baseUrl = $uri->__toString() . '/';
                $config->pathPrefix = 'p/';
                return;
            }

            $uriWithPad = $uri->withPath($uri->getPath() . '/' . $config->padId);
            $result = $this->scanForPath($uriWithPad, $config);
            if ($result) {
                $config->baseUrl = $uri->__toString() . '/';
                return;
            }

            $pathParts = explode('/', $uri->getPath());
            if (count($pathParts) === 1) {
                try {
                    $uriApi = $uri->withPath($uri->getPath() . '/api');
                    $response = $this->client->get($uriApi->__toString(), ['timeout' => $config->timeout]);
                    $body = (string)$response->getBody();
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($data) && array_key_exists('currentVersion', $data) === false) {
                        throw new EtherpadServiceNotFoundException('No Etherpad service found');
                    }
                    $config->baseUrl = $uri->__toString() . '/';
                    return;
                } catch (Exception) {
                    throw new EtherpadServiceNotFoundException('No Etherpad service found');
                }
            }
            unset($pathParts[count($pathParts) - 1]);
            $uri = $uri->withPath(implode('/', $pathParts));
        }
    }

    private function scanForPath(UriInterface $uri, Config $config): bool
    {
        try {
            $response = $this->client->get($uri->__toString(), ['timeout' => $config->timeout]);
            $body = (string) $response->getBody();
            $isEtherpad = $response->getStatusCode() === 200 && str_contains($body, '"editorcontainer"');
            if ($isEtherpad) {
                return true;
            }
        } catch (GuzzleException) {
        }

        return false;
    }
}
