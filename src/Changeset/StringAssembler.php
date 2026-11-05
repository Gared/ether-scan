<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * Efficiently concatenates strings.
 *
 * Port of StringAssembler.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/StringAssembler.ts
 */
class StringAssembler
{
    private string $str = '';

    public function clear(): void
    {
        $this->str = '';
    }

    public function append(string $x): void
    {
        $this->str .= $x;
    }

    public function __toString(): string
    {
        return $this->str;
    }
}
