<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * Efficiently merges consecutive operations that are mergeable, ignores no-ops, and drops final
 * pure "keeps". It does not re-order operations.
 *
 * Port of MergingOpAssembler.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/MergingOpAssembler.ts
 */
class MergingOpAssembler
{
    private OpAssembler $assem;
    private Op $bufOp;
    private int $bufOpAdditionalCharsAfterNewline = 0;

    public function __construct()
    {
        $this->assem = new OpAssembler();
        $this->bufOp = new Op();
        // If we get, for example, insertions [xxx\n,yyy], those don't merge,
        // but if we get [xxx\n,yyy,zzz\n], that merges to [xxx\nyyyzzz\n].
        // This variable stores the length of yyy and any other newline-less
        // ops immediately after it.
        $this->bufOpAdditionalCharsAfterNewline = 0;
    }

    public function flush(bool $isEndDocument = false): void
    {
        if ($this->bufOp->opcode === '') {
            return;
        }
        if ($isEndDocument && $this->bufOp->opcode === '=' && $this->bufOp->attribs === '') {
            // final merged keep, leave it implicit
        } else {
            $this->assem->append($this->bufOp);
            if ($this->bufOpAdditionalCharsAfterNewline > 0) {
                $this->bufOp->chars = $this->bufOpAdditionalCharsAfterNewline;
                $this->bufOp->lines = 0;
                $this->assem->append($this->bufOp);
                $this->bufOpAdditionalCharsAfterNewline = 0;
            }
        }
        $this->bufOp->opcode = '';
    }

    public function append(Op $op): void
    {
        if ($op->chars <= 0) {
            return;
        }
        if ($this->bufOp->opcode === $op->opcode && $this->bufOp->attribs === $op->attribs) {
            if ($op->lines > 0) {
                // bufOp and additional chars are all mergeable into a multi-line op
                $this->bufOp->chars += $this->bufOpAdditionalCharsAfterNewline + $op->chars;
                $this->bufOp->lines += $op->lines;
                $this->bufOpAdditionalCharsAfterNewline = 0;
            } elseif ($this->bufOp->lines === 0) {
                // both bufOp and op are in-line
                $this->bufOp->chars += $op->chars;
            } else {
                // append in-line text to multi-line bufOp
                $this->bufOpAdditionalCharsAfterNewline += $op->chars;
            }
        } else {
            $this->flush();
            Changeset::copyOp($op, $this->bufOp);
        }
    }

    public function endDocument(): void
    {
        $this->flush(true);
    }

    public function __toString(): string
    {
        $this->flush();
        return (string) $this->assem;
    }

    public function clear(): void
    {
        $this->assem->clear();
        Changeset::clearOp($this->bufOp);
    }
}
