<?php
declare(strict_types=1);
// Value / ListValue / DictionaryValue / BinaryValue: every getter and setter, copy/equal/same, ownership, removal.
require __DIR__ . '/support.php';

use Cefloom\{Cef, Value, ListValue, DictionaryValue, BinaryValue};
use Cefloom\Types\ValueType;

// ---- Value
$v = Value::create();
check($v->isValid(), 'Value valid');
same(ValueType::NULL, $v->getType(), 'a new Value is null');
check(!$v->isOwned() && !$v->isReadOnly(), 'new Value not owned, writable');
check($v->setBool(true), 'setBool');
same(ValueType::BOOL, $v->getType(), 'type BOOL');
same(true, $v->getBool(), 'getBool');
check($v->setInt(-7), 'setInt');
same(ValueType::INT, $v->getType(), 'type INT');
same(-7, $v->getInt(), 'getInt');
same(true, $v->getBool() === false || $v->getBool() === true, 'getBool on int does not throw');
check($v->setInt(PHP_INT_MAX >> 33), 'setInt big');
same(PHP_INT_MAX >> 33, $v->getInt(), 'int within 32 bits... (cef ints are 32 bit)');
check($v->setDouble(2.25), 'setDouble');
same(ValueType::DOUBLE, $v->getType(), 'type DOUBLE');
same(2.25, $v->getDouble(), 'getDouble');
check($v->setString("h\u{e9}llo \u{4e16}\u{754c}"), 'setString');
same(ValueType::STRING, $v->getType(), 'type STRING');
same("h\u{e9}llo \u{4e16}\u{754c}", $v->getString(), 'utf-8 string round trip');
check($v->setString(''), 'setString empty');
same('', $v->getString(), 'empty string');
same(ValueType::STRING, $v->getType(), 'empty string still STRING');
$bin = BinaryValue::create("ab\0cd");
check($v->setBinary($bin), 'setBinary');
same(ValueType::BINARY, $v->getType(), 'type BINARY');
same("ab\0cd", $v->getBinary()->getData(5, 0), 'binary through Value');
$dict = DictionaryValue::create();
$dict->setInt('a', 1);
check($v->setDictionary($dict), 'Value::setDictionary');
same(ValueType::DICTIONARY, $v->getType(), 'type DICTIONARY');
same(1, $v->getDictionary()->getInt('a'), 'dictionary through Value');
$list = ListValue::create();
$list->setString(0, 'z');
check($v->setList($list), 'Value::setList');
same(ValueType::LIST, $v->getType(), 'type LIST');
same('z', $v->getList()->getString(0), 'list through Value');
check($v->setNull(), 'setNull');
same(ValueType::NULL, $v->getType(), 'back to null');

// mismatched getters return defaults, objects are null
$v->setInt(5);
same('', $v->getString(), 'getString on an int');
same(0.0, $v->getDouble() === 5.0 ? 0.0 : $v->getDouble(), 'getDouble on an int is a defined value');
same(null, $v->getBinary(), 'getBinary on int is null');
same(null, $v->getDictionary(), 'getDictionary on int is null');
same(null, $v->getList(), 'getList on int is null');

// copy / equal / same
$a = Value::create();
$a->setString('x');
$b = $a->copy();
check($b instanceof Value, 'Value copy');
check($a->isEqual($b), 'copy is equal');
check(!$a->isSame($b), 'copy is not the same');
check($a->isSame($a), 'same as itself');
$b->setString('y');
check(!$a->isEqual($b), 'modified copy not equal');
same('x', $a->getString(), 'original unaffected by copy change');

// ---- BinaryValue
$bv = BinaryValue::create("\x00\x01\x02\xff");
check($bv->isValid(), 'binary valid');
check(!$bv->isOwned(), 'new binary not owned');
same(4, $bv->getSize(), 'binary size with nul bytes');
same("\x00\x01\x02\xff", $bv->getData(4, 0), 'binary bytes');
same("\x02\xff", $bv->getData(2, 2), 'binary offset');
same("\x01", $bv->getData(1, 1), 'binary one byte');
same('', $bv->getData(0, 0), 'binary zero bytes');
same("\xff", $bv->getData(10, 3), 'binary read clipped at the end');
same('', $bv->getData(4, 4), 'binary read at end');
same('', $bv->getData(4, 100), 'binary read past the end');
$bc = $bv->copy();
check($bc->isEqual($bv) && !$bc->isSame($bv) && $bv->isSame($bv), 'binary copy/equal/same');
check(!BinaryValue::create('abc')->isEqual(BinaryValue::create('abd')), 'binary not equal');
$big = str_repeat('0123456789', 10000);
same(100000, BinaryValue::create($big)->getSize(), 'big binary size');
same($big, BinaryValue::create($big)->getData(100000, 0), 'big binary roundtrip');
$empty = BinaryValue::create('');
check($empty === null || $empty->getSize() === 0, 'empty binary is null (CEF refuses) or empty');

// ---- ListValue
$l = ListValue::create();
check($l->isValid() && !$l->isOwned() && !$l->isReadOnly(), 'list flags');
same(0, $l->getSize(), 'new list is empty');
check($l->setSize(5), 'setSize');
same(5, $l->getSize(), 'size 5');
for ($i = 0; $i < 5; $i++) {
    same(ValueType::NULL, $l->getType($i), "slot $i is null");
}
check($l->setBool(0, true) && $l->setInt(1, -3) && $l->setDouble(2, 0.5) && $l->setString(3, 'str') && $l->setNull(4), 'list setters');
same([ValueType::BOOL, ValueType::INT, ValueType::DOUBLE, ValueType::STRING, ValueType::NULL],
    array_map(fn($i) => $l->getType($i), range(0, 4)), 'list types');
same(true, $l->getBool(0), 'list getBool');
same(-3, $l->getInt(1), 'list getInt');
same(0.5, $l->getDouble(2), 'list getDouble');
same('str', $l->getString(3), 'list getString');
$bvl = BinaryValue::create('bin');
check($l->setBinary(4, $bvl), 'list setBinary');
same(ValueType::BINARY, $l->getType(4), 'list slot is binary');
same('bin', $l->getBinary(4)->getData(3, 0), 'list getBinary');
$sub = ListValue::create();
$sub->setInt(0, 99);
check($l->setList(0, $sub), 'list setList');
same(99, $l->getList(0)->getInt(0), 'list getList');
$subd = DictionaryValue::create();
$subd->setString('k', 'v');
check($l->setDictionary(1, $subd), 'list setDictionary');
same('v', $l->getDictionary(1)->getString('k'), 'list getDictionary');
$val = Value::create();
$val->setDouble(8.5);
check($l->setValue(2, $val), 'list setValue');
same(8.5, $l->getValue(2)->getDouble(), 'list getValue');
same(ValueType::DOUBLE, $l->getValue(2)->getType(), 'list getValue type');

// out of range
same(0, $l->getInt(50), 'getInt out of range');
same('', $l->getString(50), 'getString out of range');
same(false, $l->getBool(50), 'getBool out of range');
same(0.0, $l->getDouble(50), 'getDouble out of range');
same(null, $l->getValue(50), 'getValue out of range');
same(null, $l->getBinary(50), 'getBinary out of range');
same(null, $l->getList(50), 'getList out of range');
same(null, $l->getDictionary(50), 'getDictionary out of range');
same(ValueType::INVALID, $l->getType(50), 'getType out of range');
same(false, $l->remove(50), 'remove out of range');
same(0, $l->getInt(-1), 'negative list index is out of range, not an error');
same(null, $l->getValue(-1), 'negative index getValue');
same(false, $l->remove(-1), 'negative index remove');

// remove shifts
$r = ListValue::create();
foreach ([10, 20, 30, 40] as $i => $x) {
    $r->setInt($i, $x);
}
same(4, $r->getSize(), 'setInt grows the list');
check($r->remove(1), 'remove');
same(3, $r->getSize(), 'size after remove');
same([10, 30, 40], [$r->getInt(0), $r->getInt(1), $r->getInt(2)], 'items shifted');
check($r->setSize(1), 'shrink');
same(1, $r->getSize(), 'shrunk');
check($r->clear(), 'clear');
same(0, $r->getSize(), 'cleared');
check($r->setSize(0), 'setSize 0');

// copy / equal / same / ownership
$c1 = ListValue::create();
$c1->setInt(0, 1);
$c1->setString(1, 'a');
$c2 = $c1->copy();
check($c1->isEqual($c2) && !$c1->isSame($c2), 'list copy equal not same');
$c2->setInt(0, 2);
same(1, $c1->getInt(0), 'list copy independent');
check(!$c1->isEqual($c2), 'list no longer equal');
$c3 = $c1;                       // same PHP object
check($c1->isSame($c3), 'list same');

$parent = ListValue::create();
$child = ListValue::create();
$child->setInt(0, 5);
check(!$child->isOwned(), 'child not owned before');
$parent->setList(0, $child);
check($parent->getList(0)->isOwned(), 'child owned by parent (via getList)');
$g = $parent->getList(0);
$g->setInt(0, 6);
same(6, $parent->getList(0)->getInt(0), 'modification through child visible in parent');
check($parent->getList(0)->isSame($g), 'getList twice isSame');
// a list that is already owned is copied on a second setList
$parent2 = ListValue::create();
$parent2->setList(0, $g);
$g->setInt(0, 7);
same(6, $parent2->getList(0)->getInt(0), 'owned list is copied when set into another parent');
same(7, $parent->getList(0)->getInt(0), 'and the original parent keeps seeing changes');

// ---- DictionaryValue
$d = DictionaryValue::create();
check($d->isValid() && !$d->isOwned() && !$d->isReadOnly(), 'dict flags');
same(0, $d->getSize(), 'empty dict');
same([], $d->getKeys(), 'empty getKeys is an empty array (the C++ bool is true)');
check($d->setNull('n') && $d->setBool('b', false) && $d->setInt('i', 12) && $d->setDouble('d', -1.5) && $d->setString('s', "t\u{e9}st"), 'dict setters');
same(5, $d->getSize(), 'dict size');
$keys = $d->getKeys();
sort($keys);
same(['b', 'd', 'i', 'n', 's'], $keys, 'dict keys');
same([ValueType::NULL, ValueType::BOOL, ValueType::INT, ValueType::DOUBLE, ValueType::STRING],
    array_map(fn($k) => $d->getType($k), ['n', 'b', 'i', 'd', 's']), 'dict types');
same(false, $d->getBool('b'), 'dict bool');
same(12, $d->getInt('i'), 'dict int');
same(-1.5, $d->getDouble('d'), 'dict double');
same("t\u{e9}st", $d->getString('s'), 'dict string');
check($d->hasKey('n') && !$d->hasKey('nope'), 'hasKey incl. null value');
same(ValueType::INVALID, $d->getType('nope'), 'type of a missing key');
same(null, $d->getValue('nope'), 'getValue missing');
same(null, $d->getBinary('nope'), 'getBinary missing');
same(null, $d->getList('nope'), 'getList missing');
same(null, $d->getDictionary('nope'), 'getDictionary missing');
same(0, $d->getInt('nope'), 'getInt missing');
same('', $d->getString('nope'), 'getString missing');
same(false, $d->getBool('nope'), 'getBool missing');
same(0.0, $d->getDouble('nope'), 'getDouble missing');
check($d->setBinary('bin', BinaryValue::create("\0\1")), 'dict setBinary');
same("\0\1", $d->getBinary('bin')->getData(2, 0), 'dict getBinary');
$sd = DictionaryValue::create();
$sd->setInt('x', 1);
check($d->setDictionary('sub', $sd), 'dict setDictionary');
$gsd = $d->getDictionary('sub');
check($gsd->isOwned(), 'sub dict owned');
$gsd->setInt('x', 2);
same(2, $d->getDictionary('sub')->getInt('x'), 'sub dict shared');
$val = Value::create();
$val->setString('V');
check($d->setValue('val', $val), 'dict setValue');
same('V', $d->getValue('val')->getString(), 'dict getValue');
same('V', $d->getString('val'), 'getString sees setValue');
check($d->setInt('i', 13), 'overwrite existing key');
same(13, $d->getInt('i'), 'overwritten');
check($d->setString('i', 'now a string'), 'change type of key');
same(ValueType::STRING, $d->getType('i'), 'type changed');
// keys: empty, unicode, with special chars
check(!$d->setInt('', 1), 'an empty key is refused by the CEF wrapper (required string parameter)');
check(!$d->hasKey(''), 'no empty key stored');
check($d->setInt("k\u{e9}y.with.dots", 3), 'unicode key');
same(3, $d->getInt("k\u{e9}y.with.dots"), 'unicode key value (dots are not paths)');
check(in_array("k\u{e9}y.with.dots", $d->getKeys(), true), 'unicode key listed');
// remove / clear
$before = $d->getSize();
check($d->remove('val'), 'remove');
check(!$d->remove('val'), 'remove twice fails');
same($before - 1, $d->getSize(), 'size after remove');
check(!$d->hasKey('val'), 'removed key gone');
check($d->clear(), 'clear');
same(0, $d->getSize(), 'cleared dict');
same([], $d->getKeys(), 'no keys after clear');
// copy
$o = DictionaryValue::create();
$o->setInt('a', 1);
$o->setDictionary('emptyd', DictionaryValue::create());
$o->setList('emptyl', ListValue::create());
$cp = $o->copy(false);
check($cp->isEqual($o) && !$cp->isSame($o), 'dict copy(false)');
same(3, $cp->getSize(), 'copy(false) keeps empty children');
$cp2 = $o->copy(true);
same(2, $cp2->getSize(), 'copy(true) drops the empty dictionary (CEF only removes dictionaries, not lists)');
check(!$cp2->hasKey('emptyd') && $cp2->hasKey('emptyl'), 'empty dictionary excluded, empty list kept');
$cp->setInt('a', 5);
same(1, $o->getInt('a'), 'dict copy independent');
// isEqual deep
$e1 = Cef::parseJSON('{"a":[1,2,{"b":null}]}', 0)->getDictionary();
$e2 = Cef::parseJSON('{"a":[1,2,{"b":null}]}', 0)->getDictionary();
$e3 = Cef::parseJSON('{"a":[1,2,{"b":0}]}', 0)->getDictionary();
check($e1->isEqual($e2) && !$e1->isSame($e2), 'deep equal');
check(!$e1->isEqual($e3), 'deep not equal');

// read only: the dictionary of a parsed Value is writable; none of the library API gives a read only one
// without a browser, so only the flag is checked
check(!$e1->isReadOnly(), 'parsed dict is writable');

// ---- cross type
throws(fn() => $d->setList('x', $d), TypeError::class, 'dict where list expected');
throws(fn() => $l->setDictionary(0, $l), TypeError::class, 'list where dict expected');
throws(fn() => $l->setBinary(0, $v), TypeError::class, 'value where binary expected');
throws(fn() => $l->setValue(0, $l), TypeError::class, 'list where value expected');
throws(fn() => $d->setInt('k', 'abc'), TypeError::class, 'string where int expected');
throws(fn() => $d->setInt('k', 1.5), TypeError::class, 'fractional float where int expected');
throws(fn() => $d->setString('k', []), TypeError::class, 'array where string expected');
throws(fn() => $d->setString('k', new stdClass()), TypeError::class, 'object where string expected');
throws(fn() => $l->getInt('x'), TypeError::class, 'string where index expected');
throws(fn() => $l->setInt(0), ArgumentCountError::class, 'too few args');
throws(fn() => $d->getInt(), ArgumentCountError::class, 'no key');
throws(fn() => $d->setBool('k', null), TypeError::class, 'null where bool expected');
throws(fn() => $v->setDouble('1.5x'), TypeError::class, 'non numeric string for float');
throws(fn() => $bv->getData('a', 0), TypeError::class, 'binary getData size type');
throws(fn() => BinaryValue::create(5.5), TypeError::class, 'binary create from float');

throws(fn() => $d->setInt('conv', '42'), TypeError::class, 'strict types: numeric string for int');
check($d->setDouble('f', 3), 'int is accepted for a float parameter even in strict mode');
same(3.0, $d->getDouble('f'), 'int widened to float');

finish();
