<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * The Changeset library for building and applying changesets to Etherpad documents.
 *
 * A Changeset is a compact description of a transformation to be applied to a text document.
 * The format is: Z:<oldLen><sign><magnitudeOfChange><ops>$<charBank>
 * where:
 *   - <oldLen> is the length of the original text in base 36
 *   - <sign> is '>' for growth or '<' for shrinkage
 *   - <magnitudeOfChange> is the magnitude of the length change in base 36
 *   - <ops> is a sequence of operations
 *   - <charBank> contains inserted characters
 *
 * Port of Changeset.ts and ChangesetUtils.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/Changeset.ts
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/ChangesetUtils.ts
 */
class Changeset
{
    /**
     * Parses a number from string base 36.
     *
     * @param string $str - string of the number in base 36
     * @return int number
     */
    public static function parseNum(string $str): int
    {
        return (int) base_convert($str, 36, 10);
    }

    /**
     * Writes a number in base 36 and puts it in a string.
     *
     * @param int $num - number
     * @return string string
     */
    public static function numToString(int $num): string
    {
        return strtolower(base_convert((string) $num, 10, 36));
    }

    /**
     * Throw an error with an easysync flag.
     *
     * @param string $msg - error message
     * @throws \RuntimeException
     */
    public static function error(string $msg): never
    {
        throw new \RuntimeException($msg);
    }

    /**
     * Assert that a condition is truthy. Throws if falsy.
     *
     * @param bool $b - assertion condition
     * @param string $msg - error message to include in the exception
     * @throws \RuntimeException
     */
    public static function assert(bool $b, string $msg): void
    {
        if (!$b) {
            self::error("Failed assertion: {$msg}");
        }
    }

    /**
     * Cleans an Op object.
     *
     * @param Op $op - object to clear
     */
    public static function clearOp(Op $op): void
    {
        $op->opcode = '';
        $op->chars = 0;
        $op->lines = 0;
        $op->attribs = '';
    }

    /**
     * Copies op1 to op2.
     *
     * @param Op $op1 - source Op
     * @param Op|null $op2 - destination Op. If not given, a new Op is used.
     * @return Op $op2
     */
    public static function copyOp(Op $op1, ?Op $op2 = null): Op
    {
        if ($op2 === null) {
            $op2 = new Op();
        }
        $op2->opcode = $op1->opcode;
        $op2->chars = $op1->chars;
        $op2->lines = $op1->lines;
        $op2->attribs = $op1->attribs;
        return $op2;
    }

    /**
     * Parses a string of serialized changeset operations.
     *
     * @param string $ops - Serialized changeset operations.
     * @return \Generator<Op>
     */
    public static function deserializeOps(string $ops): \Generator
    {
        $regex = '/((?:\*[0-9a-z]+)*)(?:\|([0-9a-z]+))?([-+=])([0-9a-z]+)|(.)/';
        $offset = 0;
        while (preg_match($regex, $ops, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $match[0][1] + strlen($match[0][0]);
            if (isset($match[5][0])) {
                if ($match[5][0] === '$') {
                    return; // Start of the insert operation character bank.
                }
                self::error('invalid operation: ' . substr($ops, $offset - 1));
            }
            $op = new Op($match[3][0]);
            $op->lines = self::parseNum($match[2][0] !== '' ? $match[2][0] : '0');
            $op->chars = self::parseNum($match[4][0]);
            $op->attribs = $match[1][0];
            yield $op;
        }
    }

    /**
     * Generates operations from the given text and attributes.
     *
     * @param string $opcode - The operator to use ('=', '+', '-', '').
     * @param string $text - The text to remove/add/keep.
     * @param string $attribs - The attributes to apply to the operations.
     * @return \Generator<Op>
     */
    public static function opsFromText(string $opcode, string $text, string $attribs = ''): \Generator
    {
        $op = new Op($opcode);
        $op->attribs = $attribs;
        $lastNewlinePos = strrpos($text, "\n");
        if ($lastNewlinePos === false) {
            $op->chars = strlen($text);
            $op->lines = 0;
            if ($op->chars > 0) {
                yield $op;
            }
        } else {
            $op->chars = $lastNewlinePos + 1;
            $op->lines = substr_count($text, "\n");
            if ($op->chars > 0) {
                yield $op;
            }
            $op2 = self::copyOp($op);
            $op2->chars = strlen($text) - ($lastNewlinePos + 1);
            $op2->lines = 0;
            if ($op2->chars > 0) {
                yield $op2;
            }
        }
    }

    /**
     * Unpacks a string encoded changeset into a structured object.
     *
     * @param string $cs - String representation of the Changeset
     * @return array{oldLen: int, newLen: int, ops: string, charBank: string}
     * @throws \RuntimeException
     */
    public static function unpack(string $cs): array
    {
        $headerRegex = '/^Z:([0-9a-z]+)([><])([0-9a-z]+)/';
        if (preg_match($headerRegex, $cs, $headerMatch) !== 1 || $headerMatch[0] === '') {
            self::error("Not a changeset: {$cs}");
        }
        $oldLen = self::parseNum($headerMatch[1]);
        $changeSign = ($headerMatch[2] === '>') ? 1 : -1;
        $changeMag = self::parseNum($headerMatch[3]);
        $newLen = $oldLen + $changeSign * $changeMag;
        $opsStart = strlen($headerMatch[0]);
        $opsEnd = strpos($cs, '$');
        if ($opsEnd === false) {
            $opsEnd = strlen($cs);
        }
        return [
            'oldLen' => $oldLen,
            'newLen' => $newLen,
            'ops' => substr($cs, $opsStart, $opsEnd - $opsStart),
            'charBank' => substr($cs, $opsEnd + 1),
        ];
    }

    /**
     * Creates an encoded changeset.
     *
     * @param int $oldLen - The length of the document before applying the changeset.
     * @param int $newLen - The length of the document after applying the changeset.
     * @param string $opsStr - Encoded operations to apply to the document.
     * @param string $bank - Characters for insert operations.
     * @return string The encoded changeset.
     */
    public static function pack(int $oldLen, int $newLen, string $opsStr, string $bank): string
    {
        $lenDiff = $newLen - $oldLen;
        $lenDiffStr = ($lenDiff >= 0 ? '>' . self::numToString($lenDiff) : '<' . self::numToString(-$lenDiff));
        return 'Z:' . self::numToString($oldLen) . $lenDiffStr . $opsStr . '$' . $bank;
    }

    /**
     * Returns the required length of the text before changeset can be applied.
     *
     * @param string $cs - String representation of the Changeset
     * @return int oldLen property
     */
    public static function oldLen(string $cs): int
    {
        return self::unpack($cs)['oldLen'];
    }

    /**
     * Returns the length of the text after changeset is applied.
     *
     * @param string $cs - String representation of the Changeset
     * @return int newLen property
     */
    public static function newLen(string $cs): int
    {
        return self::unpack($cs)['newLen'];
    }

    /**
     * Applies a Changeset to a string.
     *
     * @param string $cs - String encoded Changeset
     * @param string $str - String to which a Changeset should be applied
     * @return string The resulting string
     * @throws \RuntimeException
     */
    public static function applyToText(string $cs, string $str): string
    {
        $unpacked = self::unpack($cs);
        self::assert(
            strlen($str) === $unpacked['oldLen'],
            'mismatched apply: ' . strlen($str) . ' / ' . $unpacked['oldLen']
        );
        $bankIter = new StringIterator($unpacked['charBank']);
        $strIter = new StringIterator($str);
        $assem = new StringAssembler();
        foreach (self::deserializeOps($unpacked['ops']) as $op) {
            switch ($op->opcode) {
                case '+':
                    if ($op->lines !== substr_count($bankIter->peek($op->chars), "\n")) {
                        throw new \RuntimeException("newline count is wrong in op +; cs:{$cs} and text:{$str}");
                    }
                    $assem->append($bankIter->take($op->chars));
                    break;
                case '-':
                    if ($op->lines !== substr_count($strIter->peek($op->chars), "\n")) {
                        throw new \RuntimeException("newline count is wrong in op -; cs:{$cs} and text:{$str}");
                    }
                    $strIter->skip($op->chars);
                    break;
                case '=':
                    if ($op->lines !== substr_count($strIter->peek($op->chars), "\n")) {
                        throw new \RuntimeException("newline count is wrong in op =; cs:{$cs} and text:{$str}");
                    }
                    $assem->append($strIter->take($op->chars));
                    break;
            }
        }
        $assem->append($strIter->take($strIter->remaining()));
        return (string) $assem;
    }

    /**
     * Creates a Changeset which works on originalText and removes text from start to
     * start+numRemoved and inserts newText instead.
     *
     * @param string $orig - Original text.
     * @param int $start - Index into $orig where characters should be removed and inserted.
     * @param int $ndel - Number of characters to delete at $start.
     * @param string $ins - Text to insert at $start (after deleting $ndel characters).
     * @param string $attribs - Optional attributes to apply to the inserted text.
     * @return string The encoded changeset.
     * @throws \RuntimeException
     */
    public static function makeSplice(
        string $orig,
        int $start,
        int $ndel,
        string $ins,
        string $attribs = ''
    ): string {
        if ($start < 0) {
            throw new \RangeException("start index must be non-negative (is {$start})");
        }
        if ($ndel < 0) {
            throw new \RangeException("characters to delete must be non-negative (is {$ndel})");
        }
        if ($start > strlen($orig)) {
            $start = strlen($orig);
        }
        if ($ndel > strlen($orig) - $start) {
            $ndel = strlen($orig) - $start;
        }
        $deleted = substr($orig, $start, $ndel);
        $assem = new SmartOpAssembler();
        foreach (self::opsFromText('=', substr($orig, 0, $start)) as $op) {
            $assem->append($op);
        }
        foreach (self::opsFromText('-', $deleted) as $op) {
            $assem->append($op);
        }
        foreach (self::opsFromText('+', $ins, $attribs) as $op) {
            $assem->append($op);
        }
        $assem->endDocument();
        return self::pack(strlen($orig), strlen($orig) + strlen($ins) - $ndel, (string) $assem, $ins);
    }

    /**
     * Returns a changeset that is the identity for documents of length N.
     * Applying this changeset to a document leaves it unchanged.
     *
     * @param int $n - Length of the document.
     * @return string The identity changeset.
     */
    public static function identity(int $n): string
    {
        return self::pack($n, $n, '', '');
    }

    /**
     * Checks if a changeset is the identity changeset.
     *
     * @param string $cs - The changeset to check.
     * @return bool True if the changeset is the identity.
     */
    public static function isIdentity(string $cs): bool
    {
        $unpacked = self::unpack($cs);
        return $unpacked['ops'] === '' && $unpacked['oldLen'] === $unpacked['newLen'];
    }

    /**
     * Compose two changesets together.
     * Changeset cs1 is applied first, then cs2.
     *
     * @param string $cs1 - First changeset.
     * @param string $cs2 - Second changeset.
     * @param AttributePool $pool - Attribute pool.
     * @return string The composed changeset.
     */
    public static function compose(string $cs1, string $cs2, AttributePool $pool): string
    {
        $unpacked1 = self::unpack($cs1);
        $unpacked2 = self::unpack($cs2);
        self::assert(
            $unpacked1['newLen'] === $unpacked2['oldLen'],
            'mismatched composition'
        );
        $len1 = $unpacked1['oldLen'];
        $len2 = $unpacked2['newLen'];
        if (self::isIdentity($cs2)) {
            return $cs1;
        }
        if (self::isIdentity($cs1)) {
            return $cs2;
        }

        $assem = new SmartOpAssembler();
        $bankIter1 = new StringIterator($unpacked1['charBank']);
        $bankIter2 = new StringIterator($unpacked2['charBank']);
        $bankAssem = new StringAssembler();

        $ops1 = self::deserializeOps($unpacked1['ops']);
        $ops2 = self::deserializeOps($unpacked2['ops']);
        $ops1->current(); // initialize
        $ops2->current(); // initialize

        $op1 = $ops1->valid() ? self::copyOp($ops1->current()) : null;
        $op2 = $ops2->valid() ? self::copyOp($ops2->current()) : null;

        while ($op1 !== null || $op2 !== null) {
            if ($op1 !== null && $op1->opcode === '-') {
                $assem->append($op1);
                $ops1->next();
                $op1 = $ops1->valid() ? self::copyOp($ops1->current()) : null;
            } elseif ($op2 !== null && $op2->opcode === '+') {
                $assem->append($op2);
                $bankAssem->append($bankIter2->take($op2->chars));
                $ops2->next();
                $op2 = $ops2->valid() ? self::copyOp($ops2->current()) : null;
            } else {
                if ($op1 === null || $op2 === null) {
                    break;
                }
                self::slicerZipperFunc($op1, $op2, $pool, $bankIter1, $bankIter2, $bankAssem, $assem);
                if ($op1->chars === 0) {
                    $ops1->next();
                    $op1 = $ops1->valid() ? self::copyOp($ops1->current()) : null;
                }
                if ($op2->chars === 0) {
                    $ops2->next();
                    $op2 = $ops2->valid() ? self::copyOp($ops2->current()) : null;
                }
            }
        }

        $assem->endDocument();
        return self::pack($len1, $len2, (string) $assem, (string) $bankAssem);
    }

    /**
     * @internal Used by compose() to combine pairs of operations
     */
    private static function slicerZipperFunc(
        Op $op1,
        Op $op2,
        AttributePool $pool,
        StringIterator $bankIter1,
        StringIterator $bankIter2,
        StringAssembler $bankAssem,
        SmartOpAssembler $assem
    ): void {
        if ($op1->opcode === '+') {
            if ($op2->opcode === '-') {
                if ($op1->chars <= $op2->chars) {
                    $bankIter1->skip($op1->chars);
                    $op2->chars -= $op1->chars;
                    $op2->lines -= $op1->lines;
                    $op1->chars = 0;
                    $op1->lines = 0;
                } else {
                    $bankIter1->skip($op2->chars);
                    $op1->chars -= $op2->chars;
                    $op1->lines -= $op2->lines;
                    $op2->chars = 0;
                    $op2->lines = 0;
                }
            } elseif ($op2->opcode === '=') {
                if ($op1->chars <= $op2->chars) {
                    $newOp = self::copyOp($op1);
                    $bankAssem->append($bankIter1->take($op1->chars));
                    $assem->append($newOp);
                    $op2->chars -= $op1->chars;
                    $op2->lines -= $op1->lines;
                    $op1->chars = 0;
                    $op1->lines = 0;
                } else {
                    $newOp = self::copyOp($op2);
                    $newOp->opcode = '+';
                    $newOp->chars = $op2->chars;
                    $newOp->lines = $op2->lines;
                    $bankAssem->append($bankIter1->take($op2->chars));
                    $assem->append($newOp);
                    $op1->chars -= $op2->chars;
                    $op1->lines -= $op2->lines;
                    $op2->chars = 0;
                    $op2->lines = 0;
                }
            }
        } elseif ($op1->opcode === '=') {
            if ($op2->opcode === '-') {
                if ($op1->chars <= $op2->chars) {
                    $newOp = self::copyOp($op1);
                    $newOp->opcode = '-';
                    $assem->append($newOp);
                    $op2->chars -= $op1->chars;
                    $op2->lines -= $op1->lines;
                    $op1->chars = 0;
                    $op1->lines = 0;
                } else {
                    $newOp = self::copyOp($op2);
                    $assem->append($newOp);
                    $op1->chars -= $op2->chars;
                    $op1->lines -= $op2->lines;
                    $op2->chars = 0;
                    $op2->lines = 0;
                }
            } elseif ($op2->opcode === '=') {
                if ($op1->chars <= $op2->chars) {
                    $newOp = self::copyOp($op1);
                    $assem->append($newOp);
                    $op2->chars -= $op1->chars;
                    $op2->lines -= $op1->lines;
                    $op1->chars = 0;
                    $op1->lines = 0;
                } else {
                    $newOp = self::copyOp($op2);
                    $assem->append($newOp);
                    $op1->chars -= $op2->chars;
                    $op1->lines -= $op2->lines;
                    $op2->chars = 0;
                    $op2->lines = 0;
                }
            }
        }
    }

    /**
     * Iterate over attribute numbers in a changeset and call func with each one.
     *
     * @param string $cs - Changeset/attribution string to iterate over
     * @param callable(int): void $func - Callback called with each attribute number.
     */
    public static function eachAttribNumber(string $cs, callable $func): void
    {
        preg_match_all('/\*([0-9a-z]+)/', $cs, $matches);
        foreach ($matches[1] as $match) {
            $func(self::parseNum($match));
        }
    }

    /**
     * Iterate over attributes in a changeset and move them from oldPool to newPool.
     *
     * @param string $cs - Changeset/attribution string to iterate over
     * @param AttributePool $oldPool - old attributes pool
     * @param AttributePool $newPool - new attributes pool
     * @return string the new Changeset
     */
    public static function moveOpsToNewPool(string $cs, AttributePool $oldPool, AttributePool $newPool): string
    {
        $dollarPos = strpos($cs, '$');
        if ($dollarPos === false) {
            $dollarPos = strlen($cs);
        }
        $upToDollar = substr($cs, 0, $dollarPos);
        $fromDollar = substr($cs, $dollarPos);

        return preg_replace_callback(
            '/\*([0-9a-z]+)/',
            function (array $match) use ($oldPool, $newPool): string {
                $oldNum = self::parseNum($match[1]);
                $attrib = $oldPool->getAttrib($oldNum);
                if ($attrib === null) {
                    return $match[0];
                }
                $newNum = $newPool->putAttrib($attrib);
                return '*' . self::numToString($newNum);
            },
            $upToDollar
        ) . $fromDollar;
    }
}
