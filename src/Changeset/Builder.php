<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * Incrementally builds a Changeset.
 *
 * Port of Builder.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/Builder.ts
 */
class Builder
{
    private int $oldLen;
    private SmartOpAssembler $assem;
    private Op $o;
    private StringAssembler $charBank;

    public function __construct(int $oldLen)
    {
        $this->oldLen = $oldLen;
        $this->assem = new SmartOpAssembler();
        $this->o = new Op();
        $this->charBank = new StringAssembler();
    }

    /**
     * @param int $n - Number of characters to keep.
     * @param int $l - Number of newlines among the N characters. If positive, the last character must be a newline.
     * @param string $attribs - Either '*0*1...' attribute string.
     * @return Builder this
     */
    public function keep(int $n, int $l = 0, string $attribs = ''): Builder
    {
        $this->o->opcode = '=';
        $this->o->attribs = $attribs;
        $this->o->chars = $n;
        $this->o->lines = $l;
        $this->assem->append($this->o);
        return $this;
    }

    /**
     * @param string $text - Text to keep.
     * @param string $attribs - Attribute string.
     * @return Builder this
     */
    public function keepText(string $text, string $attribs = ''): Builder
    {
        foreach (Changeset::opsFromText('=', $text, $attribs) as $op) {
            $this->assem->append($op);
        }
        return $this;
    }

    /**
     * @param string $text - Text to insert.
     * @param string $attribs - Attribute string.
     * @return Builder this
     */
    public function insert(string $text, string $attribs = ''): Builder
    {
        foreach (Changeset::opsFromText('+', $text, $attribs) as $op) {
            $this->assem->append($op);
        }
        $this->charBank->append($text);
        return $this;
    }

    /**
     * @param int $n - Number of characters to remove.
     * @param int $l - Number of newlines among the N characters. If positive, the last character must be a newline.
     * @return Builder this
     */
    public function remove(int $n, int $l = 0): Builder
    {
        $this->o->opcode = '-';
        $this->o->attribs = '';
        $this->o->chars = $n;
        $this->o->lines = $l;
        $this->assem->append($this->o);
        return $this;
    }

    public function __toString(): string
    {
        $this->assem->endDocument();
        $newLen = $this->oldLen + $this->assem->getLengthChange();
        return Changeset::pack($this->oldLen, $newLen, (string) $this->assem, (string) $this->charBank);
    }
}
