<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

use RuntimeException;

class RevisionLookupService
{
    private const REVISION_LOOKUP_FILE = __DIR__ . '/../../data/revision_lookup.json';

    public function getVersion(string $commitHash): ?string
    {
        $shortHash = substr($commitHash, 0, 7);

        $content = file_get_contents(self::REVISION_LOOKUP_FILE);
        if ($content === false) {
            throw new RuntimeException('Could not read revision lookup file');
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($data) === false) {
            throw new RuntimeException('Could not decode revision lookup file');
        }

        return $data[$shortHash] ?? null;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public function save(array $data): void
    {
        $data = json_encode($data, JSON_THROW_ON_ERROR);

        file_put_contents(self::REVISION_LOOKUP_FILE, $data);
    }
}
