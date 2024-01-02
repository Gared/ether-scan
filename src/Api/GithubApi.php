<?php
declare(strict_types=1);

namespace Gared\EtherScan\Api;

use Exception;
use GuzzleHttp\Client;

class GithubApi
{
    private Client $client;

    public function __construct(
    ) {
        $this->client = new Client([
            'base_uri' => 'https://api.github.com',
        ]);
    }

    public function getCommit(string $commitHash): ?array
    {
        try {
            $response = $this->client->get('/repos/ether/etherpad-lite/commits/' . $commitHash);
            $body = (string)$response->getBody();
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            return null;
        }
    }

    public function getTags(): ?array
    {
        try {
            $response = $this->client->get('/repos/ether/etherpad-lite/tags?per_page=100');
            $body = (string)$response->getBody();
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            return null;
        }
    }
}