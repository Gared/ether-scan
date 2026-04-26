<?php
declare(strict_types=1);

namespace Gared\EtherScan\Api;

use Exception;
use GuzzleHttp\Client;
use RuntimeException;

class GithubApi
{
    private Client $client;

    public function __construct(
    ) {
        $this->client = new Client([
            'base_uri' => 'https://api.github.com',
        ]);
    }

    /**
     * @return array{sha: string}
     */
    public function getCommit(string $commitHash): array
    {
        $response = $this->client->get('/repos/ether/etherpad/commits/' . $commitHash);
        $body = (string)$response->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($data) === false || array_key_exists('sha', $data) === false) {
            throw new RuntimeException('Unexpected response from GitHub API: ' . $body);
        }

        return $data;
    }

    /**
     * @return list<array{commit: array{sha: string}, name: string}>
     */
    public function getTags(): array
    {
        $response = $this->client->get('/repos/ether/etherpad/tags?per_page=100');
        $body = (string)$response->getBody();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($data) === false) {
            throw new RuntimeException('Unexpected response from GitHub API: ' . $body);
        }

        foreach ($data as $tag) {
            if (is_array($tag) === false || array_key_exists('commit', $tag) === false || array_key_exists('sha', $tag['commit']) === false || array_key_exists('name', $tag) === false) {
                throw new RuntimeException('Unexpected response from GitHub API: ' . $body);
            }
        }

        /** @phpstan-ignore return.type */
        return $data;
    }
}
