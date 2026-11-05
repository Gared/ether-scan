<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * Creates an object that allows you to append operations (type Op) and also compresses them if
 * possible. Like MergingOpAssembler, but able to produce conforming changesets from slightly looser
 * input, at the cost of speed. Specifically:
 *   - merges consecutive operations that can be merged
 *   - strips final "="
 *   - ignores 0-length changes
 *   - reorders consecutive + and - (which MergingOpAssembler doesn't do)
 *
 * Port of SmartOpAssembler.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/SmartOpAssembler.ts
 */
class SmartOpAssembler
{
    private MergingOpAssembler $minusAssem;
    private MergingOpAssembler $plusAssem;
    private MergingOpAssembler $keepAssem;
    private string $lastOpcode = '';
    private int $lengthChange = 0;
    private StringAssembler $assem;

    public function __construct()
    {
        $this->minusAssem = new MergingOpAssembler();
        $this->plusAssem = new MergingOpAssembler();
        $this->keepAssem = new MergingOpAssembler();
        $this->assem = new StringAssembler();
    }

    private function flushKeeps(): void
    {
        $this->assem->append((string) $this->keepAssem);
        $this->keepAssem->clear();
    }

    private function flushPlusMinus(): void
    {
        $this->assem->append((string) $this->minusAssem);
        $this->minusAssem->clear();
        $this->assem->append((string) $this->plusAssem);
        $this->plusAssem->clear();
    }

    public function append(Op $op): void
    {
        if ($op->opcode === '') {
            return;
        }
        if ($op->chars === 0) {
            return;
        }

        if ($op->opcode === '-') {
            if ($this->lastOpcode === '=') {
                $this->flushKeeps();
            }
            $this->minusAssem->append($op);
            $this->lengthChange -= $op->chars;
        } elseif ($op->opcode === '+') {
            if ($this->lastOpcode === '=') {
                $this->flushKeeps();
            }
            $this->plusAssem->append($op);
            $this->lengthChange += $op->chars;
        } elseif ($op->opcode === '=') {
            if ($this->lastOpcode !== '=') {
                $this->flushPlusMinus();
            }
            $this->keepAssem->append($op);
        }
        $this->lastOpcode = $op->opcode;
    }

    public function __toString(): string
    {
        $this->flushPlusMinus();
        $this->flushKeeps();
        return (string) $this->assem;
    }

    public function clear(): void
    {
        $this->minusAssem->clear();
        $this->plusAssem->clear();
        $this->keepAssem->clear();
        $this->assem->clear();
        $this->lengthChange = 0;
    }

    public function endDocument(): void
    {
        $this->keepAssem->endDocument();
    }

    public function getLengthChange(): int
    {
        return $this->lengthChange;
    }
}
