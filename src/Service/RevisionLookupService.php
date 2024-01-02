<?php
declare(strict_types=1);

namespace Gared\EtherScan\Service;

class RevisionLookupService
{
    private const REVISION_LOOKUP_FILE = __DIR__ . '/../../data/revision_lookup.json';

    public function getVersion(string $commitHash): ?string
    {
        $shortHash = substr($commitHash, 0, 7);
        $data = json_decode(file_get_contents(self::REVISION_LOOKUP_FILE), true, 512, JSON_THROW_ON_ERROR);

        return $data[$shortHash] ?? null;
    }

    public function save(array $data): void
    {
        $data = json_encode($data, JSON_THROW_ON_ERROR);

        file_put_contents(self::REVISION_LOOKUP_FILE, $data);
    }
}