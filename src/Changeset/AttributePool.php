<?php
declare(strict_types=1);

namespace Gared\EtherScan\Changeset;

/**
 * Represents an attribute pool, which is a collection of attributes (pairs of key and value
 * strings) along with their identifiers (non-negative integers).
 *
 * The attribute pool enables attribute interning: rather than including the key and value strings
 * in changesets, changesets reference attributes by their identifiers.
 *
 * Port of AttributePool.ts from etherpad-lite:
 * https://github.com/ether/etherpad-lite/blob/master/src/static/js/AttributePool.ts
 */
class AttributePool
{
    /**
     * Maps an attribute identifier to the attribute's [key, value] string pair.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public array $numToAttrib = [];

    /**
     * Maps the string representation of an attribute to its non-negative identifier.
     *
     * @var array<string, int>
     */
    private array $attribToNum = [];

    /**
     * The attribute ID to assign to the next new attribute.
     */
    private int $nextNum = 0;

    /**
     * Add an attribute to the attribute set, or query for an existing attribute identifier.
     *
     * @param array{0: string, 1: string} $attrib - The attribute's [key, value] pair of strings.
     * @param bool $dontAddIfAbsent - If true, do not insert the attribute into the pool if absent.
     * @return int The attribute's identifier, or -1 if the attribute is not in the pool.
     */
    public function putAttrib(array $attrib, bool $dontAddIfAbsent = false): int
    {
        $str = $attrib[0] . ',' . $attrib[1];
        if (isset($this->attribToNum[$str])) {
            return $this->attribToNum[$str];
        }
        if ($dontAddIfAbsent) {
            return -1;
        }
        $num = $this->nextNum++;
        $this->attribToNum[$str] = $num;
        $this->numToAttrib[$num] = [$attrib[0], $attrib[1]];
        return $num;
    }

    /**
     * @param int $num - The identifier of the attribute to fetch.
     * @return array{0: string, 1: string}|null The attribute with the given identifier.
     */
    public function getAttrib(int $num): ?array
    {
        if (!isset($this->numToAttrib[$num])) {
            return null;
        }
        return [$this->numToAttrib[$num][0], $this->numToAttrib[$num][1]];
    }

    /**
     * @param int $num - The identifier of the attribute to fetch.
     * @return string Equivalent to getAttrib(num)[0] if the attribute exists, otherwise empty string.
     */
    public function getAttribKey(int $num): string
    {
        if (!isset($this->numToAttrib[$num])) {
            return '';
        }
        return $this->numToAttrib[$num][0];
    }

    /**
     * @param int $num - The identifier of the attribute to fetch.
     * @return string Equivalent to getAttrib(num)[1] if the attribute exists, otherwise empty string.
     */
    public function getAttribValue(int $num): string
    {
        if (!isset($this->numToAttrib[$num])) {
            return '';
        }
        return $this->numToAttrib[$num][1];
    }

    /**
     * Executes a callback for each attribute in the pool.
     *
     * @param callable(string, string): void $func - Callback called with key and value arguments.
     */
    public function eachAttrib(callable $func): void
    {
        foreach ($this->numToAttrib as $pair) {
            $func($pair[0], $pair[1]);
        }
    }

    /**
     * @return array{numToAttrib: array<int, array{0: string, 1: string}>, nextNum: int}
     *     An object suitable for serialization that can be passed to fromJsonable to reconstruct the pool.
     */
    public function toJsonable(): array
    {
        return [
            'numToAttrib' => $this->numToAttrib,
            'nextNum' => $this->nextNum,
        ];
    }

    /**
     * Replace the contents of this attribute pool with values from a previous call to toJsonable.
     *
     * @param array{numToAttrib: array<int, array{0: string, 1: string}>, nextNum: int} $obj
     */
    public function fromJsonable(array $obj): self
    {
        $this->numToAttrib = $obj['numToAttrib'];
        $this->nextNum = $obj['nextNum'];
        $this->attribToNum = [];
        foreach ($this->numToAttrib as $n => $attrib) {
            $this->attribToNum[$attrib[0] . ',' . $attrib[1]] = $n;
        }
        return $this;
    }

    /**
     * @return AttributePool A deep copy of this attribute pool.
     */
    public function clone(): AttributePool
    {
        $c = new AttributePool();
        foreach ($this->numToAttrib as $n => $a) {
            $c->numToAttrib[$n] = [$a[0], $a[1]];
        }
        $c->attribToNum = $this->attribToNum;
        $c->nextNum = $this->nextNum;
        return $c;
    }
}
