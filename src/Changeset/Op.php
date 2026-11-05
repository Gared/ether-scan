<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * An operation to apply to a shared document.
 *
 * Port of Op.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/Op.ts
 */
class Op
{
    /**
     * The operation's operator:
     *   - '=': Keep the next `chars` characters (containing `lines` newlines) from the base document.
     *   - '-': Remove the next `chars` characters (containing `lines` newlines) from the base document.
     *   - '+': Insert `chars` characters (containing `lines` newlines) at the current position in
     *     the document. The inserted characters come from the changeset's character bank.
     *   - '' (empty string): Invalid operator used in some contexts to signify the lack of an operation.
     */
    public string $opcode;

    /**
     * The number of characters to keep, insert, or delete.
     */
    public int $chars;

    /**
     * The number of characters among the `chars` characters that are newlines.
     * If non-zero, the last character must be a newline.
     */
    public int $lines;

    /**
     * Identifiers of attributes to apply to the text, represented as a repeated (zero or more)
     * sequence of asterisk followed by a non-negative base-36 (lower-case) integer.
     * For example, '*2*1o' indicates that attributes 2 and 60 apply to the text affected by the operation.
     */
    public string $attribs;

    public function __construct(string $opcode = '')
    {
        $this->opcode = $opcode;
        $this->chars = 0;
        $this->lines = 0;
        $this->attribs = '';
    }

    public function __toString(): string
    {
        if ($this->opcode === '') {
            throw new \RuntimeException('null op');
        }
        $l = $this->lines > 0 ? '|' . Changeset::numToString($this->lines) : '';
        return $this->attribs . $l . $this->opcode . Changeset::numToString($this->chars);
    }
}
