<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * Serializes a sequence of Ops into a string.
 *
 * Port of OpAssembler.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/OpAssembler.ts
 */
class OpAssembler
{
    private string $serialized = '';

    public function append(Op $op): void
    {
        $this->serialized .= (string) $op;
    }

    public function __toString(): string
    {
        return $this->serialized;
    }

    public function clear(): void
    {
        $this->serialized = '';
    }
}
