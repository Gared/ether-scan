<?php
declare(strict_types=1);

namespace Gared\EtherScan\Console;

class InstanceResults
{
    /**
     * @var list<InstanceResult>
     */
    private array $instances = [];

    public function add(InstanceResult $instanceResult): void
    {
        $this->instances[] = $instanceResult;
    }

    /**
     * @return array<string, list<InstanceResult>>
     */
    public function getInstancesByVersion(): array
    {
        $result = [];
        foreach ($this->instances as $instance) {
            $version = $instance->version;
            if (!isset($result[$version])) {
                $result[$version] = [];
            }
            $result[$version][] = $instance;
        }
        return $result;
    }

    /**
     * @return list<InstanceResult>
     */
    public function getInstancesForVersion(string $version): array
    {
        $result = [];
        foreach ($this->instances as $instance) {
            if ($instance->version === $version) {
                $result[] = $instance;
            }
        }
        return $result;
    }
}
