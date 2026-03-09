<?php
declare(strict_types=1);

namespace Gared\EtherScan\Tests\Unit\Changeset;

use Gared\EtherScan\Changeset\AttributePool;
use Gared\EtherScan\Changeset\Builder;
use Gared\EtherScan\Changeset\Changeset;
use Gared\EtherScan\Changeset\Op;
use PHPUnit\Framework\TestCase;

class ChangesetTest extends TestCase
{
    public function testNumToString(): void
    {
        self::assertSame('0', Changeset::numToString(0));
        self::assertSame('1', Changeset::numToString(1));
        self::assertSame('z', Changeset::numToString(35));
        self::assertSame('10', Changeset::numToString(36));
        self::assertSame('z0', Changeset::numToString(1260));
    }

    public function testParseNum(): void
    {
        self::assertSame(0, Changeset::parseNum('0'));
        self::assertSame(1, Changeset::parseNum('1'));
        self::assertSame(35, Changeset::parseNum('z'));
        self::assertSame(36, Changeset::parseNum('10'));
        self::assertSame(1260, Changeset::parseNum('z0'));
    }

    public function testPackUnpack(): void
    {
        $packed = Changeset::pack(10, 15, '+5', 'hello');
        self::assertSame('Z:a>5+5$hello', $packed);

        $unpacked = Changeset::unpack($packed);
        self::assertSame(10, $unpacked['oldLen']);
        self::assertSame(15, $unpacked['newLen']);
        self::assertSame('+5', $unpacked['ops']);
        self::assertSame('hello', $unpacked['charBank']);
    }

    public function testPackWithShrinkage(): void
    {
        $packed = Changeset::pack(15, 10, '-5', '');
        self::assertSame('Z:f<5-5$', $packed);

        $unpacked = Changeset::unpack($packed);
        self::assertSame(15, $unpacked['oldLen']);
        self::assertSame(10, $unpacked['newLen']);
    }

    public function testIdentity(): void
    {
        $identity = Changeset::identity(10);
        self::assertSame('Z:a>0$', $identity);
        self::assertTrue(Changeset::isIdentity($identity));
    }

    public function testIdentityWithNonIdentity(): void
    {
        $cs = Changeset::pack(10, 15, '+5', 'hello');
        self::assertFalse(Changeset::isIdentity($cs));
    }

    public function testOldLenNewLen(): void
    {
        $packed = Changeset::pack(10, 15, '+5', 'hello');
        self::assertSame(10, Changeset::oldLen($packed));
        self::assertSame(15, Changeset::newLen($packed));
    }

    public function testMakeSpliceInsert(): void
    {
        $orig = 'Hello\n';
        $cs = Changeset::makeSplice($orig, 5, 0, ' World');
        $result = Changeset::applyToText($cs, $orig);
        self::assertSame('Hello World\n', $result);
    }

    public function testMakeSpliceDelete(): void
    {
        $orig = 'Hello World\n';
        $cs = Changeset::makeSplice($orig, 5, 6, '');
        $result = Changeset::applyToText($cs, $orig);
        self::assertSame('Hello\n', $result);
    }

    public function testMakeSpliceReplace(): void
    {
        $orig = 'Hello World\n';
        $cs = Changeset::makeSplice($orig, 6, 5, 'PHP');
        $result = Changeset::applyToText($cs, $orig);
        self::assertSame('Hello PHP\n', $result);
    }

    public function testMakeSpliceInsertAtStart(): void
    {
        $orig = 'World\n';
        $cs = Changeset::makeSplice($orig, 0, 0, 'Hello ');
        $result = Changeset::applyToText($cs, $orig);
        self::assertSame('Hello World\n', $result);
    }

    public function testMakeSpliceInsertAtEnd(): void
    {
        $orig = "Hello\n";
        $cs = Changeset::makeSplice($orig, 6, 0, ' World\n');
        $result = Changeset::applyToText($cs, $orig);
        self::assertSame("Hello\n World\n", $result);
    }

    public function testMakeSpliceWithNewlines(): void
    {
        $orig = "Line1\nLine2\n";
        $cs = Changeset::makeSplice($orig, 6, 5, 'Replaced');
        $result = Changeset::applyToText($cs, $orig);
        self::assertSame("Line1\nReplaced\n", $result);
    }

    public function testApplyToTextKeep(): void
    {
        $cs = Changeset::identity(5);
        $result = Changeset::applyToText($cs, 'hello');
        self::assertSame('hello', $result);
    }

    public function testApplyToTextInsert(): void
    {
        // Insert 'XYZ' at the beginning of 'hello'
        $cs = Changeset::makeSplice('hello', 0, 0, 'XYZ');
        $result = Changeset::applyToText($cs, 'hello');
        self::assertSame('XYZhello', $result);
    }

    public function testApplyToTextDelete(): void
    {
        // Delete 2 characters at position 2 from 'hello'
        $cs = Changeset::makeSplice('hello', 2, 2, '');
        $result = Changeset::applyToText($cs, 'hello');
        self::assertSame('heo', $result);
    }

    public function testDeserializeOps(): void
    {
        $ops = '+5=3-2';
        $result = [];
        foreach (Changeset::deserializeOps($ops) as $op) {
            $result[] = ['opcode' => $op->opcode, 'chars' => $op->chars, 'lines' => $op->lines];
        }
        self::assertCount(3, $result);
        self::assertSame('+', $result[0]['opcode']);
        self::assertSame(5, $result[0]['chars']);
        self::assertSame('=', $result[1]['opcode']);
        self::assertSame(3, $result[1]['chars']);
        self::assertSame('-', $result[2]['opcode']);
        self::assertSame(2, $result[2]['chars']);
    }

    public function testDeserializeOpsWithNewlines(): void
    {
        $ops = '|2=c+5';
        $result = [];
        foreach (Changeset::deserializeOps($ops) as $op) {
            $result[] = ['opcode' => $op->opcode, 'chars' => $op->chars, 'lines' => $op->lines];
        }
        self::assertCount(2, $result);
        self::assertSame('=', $result[0]['opcode']);
        self::assertSame(12, $result[0]['chars']);
        self::assertSame(2, $result[0]['lines']);
    }

    public function testClearOp(): void
    {
        $op = new Op('+');
        $op->chars = 5;
        $op->lines = 1;
        $op->attribs = '*0';

        Changeset::clearOp($op);

        self::assertSame('', $op->opcode);
        self::assertSame(0, $op->chars);
        self::assertSame(0, $op->lines);
        self::assertSame('', $op->attribs);
    }

    public function testCopyOp(): void
    {
        $op1 = new Op('+');
        $op1->chars = 5;
        $op1->lines = 1;
        $op1->attribs = '*0';

        $op2 = Changeset::copyOp($op1);

        self::assertSame('+', $op2->opcode);
        self::assertSame(5, $op2->chars);
        self::assertSame(1, $op2->lines);
        self::assertSame('*0', $op2->attribs);
        self::assertNotSame($op1, $op2);
    }

    public function testCopyOpToExisting(): void
    {
        $op1 = new Op('+');
        $op1->chars = 5;
        $op2 = new Op();
        Changeset::copyOp($op1, $op2);
        self::assertSame('+', $op2->opcode);
        self::assertSame(5, $op2->chars);
    }

    public function testOpsFromTextSimple(): void
    {
        $ops = iterator_to_array(Changeset::opsFromText('+', 'hello'));
        self::assertCount(1, $ops);
        self::assertSame('+', $ops[0]->opcode);
        self::assertSame(5, $ops[0]->chars);
        self::assertSame(0, $ops[0]->lines);
    }

    public function testOpsFromTextWithNewlines(): void
    {
        $ops = iterator_to_array(Changeset::opsFromText('=', "hello\nworld"));
        // Should split into two ops: one for "hello\n" (with newline) and one for "world"
        self::assertCount(2, $ops);
        self::assertSame('=', $ops[0]->opcode);
        self::assertSame(6, $ops[0]->chars); // "hello\n"
        self::assertSame(1, $ops[0]->lines);
        self::assertSame('=', $ops[1]->opcode);
        self::assertSame(5, $ops[1]->chars); // "world"
        self::assertSame(0, $ops[1]->lines);
    }

    public function testMakeSpliceNegativeStartThrows(): void
    {
        $this->expectException(\RangeException::class);
        Changeset::makeSplice('hello', -1, 0, '');
    }

    public function testMakeSpliceNegativeNdelThrows(): void
    {
        $this->expectException(\RangeException::class);
        Changeset::makeSplice('hello', 0, -1, '');
    }

    public function testBuilderInsert(): void
    {
        $builder = new Builder(5);
        $builder->keepText('hello');
        $builder->insert(' world');
        $cs = (string) $builder;
        $result = Changeset::applyToText($cs, 'hello');
        self::assertSame('hello world', $result);
    }

    public function testBuilderRemove(): void
    {
        $builder = new Builder(5);
        $builder->remove(5);
        $cs = (string) $builder;
        $result = Changeset::applyToText($cs, 'hello');
        self::assertSame('', $result);
    }

    public function testBuilderKeep(): void
    {
        // Trailing keep ops are implicit - keeping 3 chars in a 5-char document
        // does not truncate unless you also explicitly remove the remaining 2 chars.
        $builder = new Builder(5);
        $builder->keep(3);
        $builder->remove(2);
        $cs = (string) $builder;
        $result = Changeset::applyToText($cs, 'hello');
        self::assertSame('hel', $result);
    }

    public function testAttributePool(): void
    {
        $pool = new AttributePool();
        $num = $pool->putAttrib(['bold', 'true']);
        self::assertSame(0, $num);

        // Re-adding same attribute should return same number
        $num2 = $pool->putAttrib(['bold', 'true']);
        self::assertSame(0, $num2);

        // Adding new attribute
        $num3 = $pool->putAttrib(['italic', 'true']);
        self::assertSame(1, $num3);

        self::assertSame(['bold', 'true'], $pool->getAttrib(0));
        self::assertSame(['italic', 'true'], $pool->getAttrib(1));
        self::assertNull($pool->getAttrib(5));

        self::assertSame('bold', $pool->getAttribKey(0));
        self::assertSame('true', $pool->getAttribValue(0));
    }

    public function testAttributePoolDontAdd(): void
    {
        $pool = new AttributePool();
        $num = $pool->putAttrib(['bold', 'true'], true);
        self::assertSame(-1, $num); // Not found and not added

        // Now add it
        $pool->putAttrib(['bold', 'true']);
        $num2 = $pool->putAttrib(['bold', 'true'], true);
        self::assertSame(0, $num2); // Found
    }

    public function testAttributePoolJsonable(): void
    {
        $pool = new AttributePool();
        $pool->putAttrib(['bold', 'true']);
        $pool->putAttrib(['italic', 'true']);

        $json = $pool->toJsonable();
        self::assertArrayHasKey('numToAttrib', $json);
        self::assertArrayHasKey('nextNum', $json);
        self::assertSame(2, $json['nextNum']);

        $pool2 = new AttributePool();
        $pool2->fromJsonable($json);
        self::assertSame(['bold', 'true'], $pool2->getAttrib(0));
        self::assertSame(['italic', 'true'], $pool2->getAttrib(1));
    }

    public function testAttributePoolEachAttrib(): void
    {
        $pool = new AttributePool();
        $pool->putAttrib(['bold', 'true']);
        $pool->putAttrib(['italic', 'false']);

        $attribs = [];
        $pool->eachAttrib(function (string $key, string $value) use (&$attribs): void {
            $attribs[$key] = $value;
        });

        self::assertSame(['bold' => 'true', 'italic' => 'false'], $attribs);
    }

    public function testAttributePoolClone(): void
    {
        $pool = new AttributePool();
        $pool->putAttrib(['bold', 'true']);

        $clone = $pool->clone();
        $clone->putAttrib(['italic', 'true']);

        // Original should not be modified
        self::assertNull($pool->getAttrib(1));
        // Clone should have both
        self::assertSame(['bold', 'true'], $clone->getAttrib(0));
        self::assertSame(['italic', 'true'], $clone->getAttrib(1));
    }

    public function testUnpackInvalidChangeset(): void
    {
        $this->expectException(\RuntimeException::class);
        Changeset::unpack('invalid');
    }

    public function testEachAttribNumber(): void
    {
        $cs = Changeset::pack(5, 8, '*0*1+3', 'abc');
        $attribNums = [];
        Changeset::eachAttribNumber($cs, function (int $num) use (&$attribNums): void {
            $attribNums[] = $num;
        });
        self::assertSame([0, 1], $attribNums);
    }

    public function testMoveOpsToNewPool(): void
    {
        $oldPool = new AttributePool();
        $oldPool->putAttrib(['bold', 'true']); // num 0
        $oldPool->putAttrib(['italic', 'true']); // num 1

        $newPool = new AttributePool();
        $newPool->putAttrib(['color', 'red']); // num 0 in new pool
        $newPool->putAttrib(['bold', 'true']); // num 1 in new pool

        $cs = Changeset::pack(5, 8, '*0*1+3', 'abc');
        $newCs = Changeset::moveOpsToNewPool($cs, $oldPool, $newPool);
        // bold was 0 in old pool -> 1 in new pool
        // italic was 1 in old pool -> 2 in new pool (new entry)
        self::assertStringContainsString('*1*2', $newCs);
    }

    /**
     * Tests writing text into a pad-like scenario: inserting text at different positions
     * and building up the document step by step.
     */
    public function testWritingTextIntoPad(): void
    {
        // Start with an empty pad (just a newline, as etherpad always has at least one newline)
        $padText = "\n";

        // Step 1: Insert "Hello World" before the trailing newline
        $cs1 = Changeset::makeSplice($padText, 0, 0, 'Hello World');
        $padText = Changeset::applyToText($cs1, $padText);
        self::assertSame("Hello World\n", $padText);

        // Step 2: Append a new line
        $cs2 = Changeset::makeSplice($padText, strlen($padText) - 1, 0, "\nSecond line");
        $padText = Changeset::applyToText($cs2, $padText);
        self::assertSame("Hello World\nSecond line\n", $padText);

        // Step 3: Modify text in the middle
        $cs3 = Changeset::makeSplice($padText, 6, 5, 'PHP');
        $padText = Changeset::applyToText($cs3, $padText);
        self::assertSame("Hello PHP\nSecond line\n", $padText);

        // Step 4: Delete the second line (12 chars: "Second line\n" starts at position 10)
        $cs4 = Changeset::makeSplice($padText, 10, 12, '');
        $padText = Changeset::applyToText($cs4, $padText);
        self::assertSame("Hello PHP\n", $padText);
    }
}
