<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * A custom String Iterator that iterates over a string character by character.
 *
 * Port of StringIterator.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/StringIterator.ts
 */
class StringIterator
{
    private int $curIndex = 0;
    private int $newLines;
    private string $str;

    public function __construct(string $str)
    {
        $this->str = $str;
        $this->newLines = substr_count($str, "\n");
    }

    public function remaining(): int
    {
        return strlen($this->str) - $this->curIndex;
    }

    public function getNewLines(): int
    {
        return $this->newLines;
    }

    private function assertRemaining(int $n): void
    {
        if ($n > $this->remaining()) {
            throw new \RuntimeException("!({$n} <= {$this->remaining()})");
        }
    }

    public function take(int $n): string
    {
        $this->assertRemaining($n);
        $s = substr($this->str, $this->curIndex, $n);
        $this->newLines -= substr_count($s, "\n");
        $this->curIndex += $n;
        return $s;
    }

    public function peek(int $n): string
    {
        $this->assertRemaining($n);
        return substr($this->str, $this->curIndex, $n);
    }

    public function skip(int $n): void
    {
        $this->assertRemaining($n);
        $skipped = substr($this->str, $this->curIndex, $n);
        $this->newLines -= substr_count($skipped, "\n");
        $this->curIndex += $n;
    }
}
