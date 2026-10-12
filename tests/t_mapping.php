<?php
declare(strict_types=1);
// The contract of each kind of argument and result that the generator maps, one representative method for each, and what PHP does
// with a value of the wrong type (a TypeError, never a crash). A call on an object after Cef::shutdown() is an Error.
require __DIR__ . '/support.php';

use Cefloom\{Cef, CommandLine, DictionaryValue, Image, ListValue, PdfPrintSettings, Point, Rect, Request, Task, URLParts, Value, BinaryValue, WaitableEvent};
use Cefloom\Types\{ColorType, AlphaType, ValueType};

start_cef();

// ---- primitives: int, bool, double in and out
$list = ListValue::create();
$list->setSize(8);
$list->setInt(0, PHP_INT_MAX >> 32);          // CEF stores an int in 32 bits: what does not fit is cut, not a crash
check(is_int($list->getInt(0)), 'an int that is too big for 32 bits is stored without a crash');
$list->setInt(1, -2147483648);
same(-2147483648, $list->getInt(1), 'the smallest 32 bit int');
$list->setInt(2, 2147483647);
same(2147483647, $list->getInt(2), 'the biggest 32 bit int');
$list->setBool(3, true);
same(true, $list->getBool(3), 'bool in and out');
$list->setDouble(4, 1.0e300);
same(1.0e300, $list->getDouble(4), 'a very big double');
$list->setDouble(5, -0.5);
same(-0.5, $list->getDouble(5), 'a negative double');
same(0, $list->getInt(6), 'an index that was never set gives the default (0)');
same('', $list->getString(7), 'and an empty string');

// ---- strings: unicode, empty, a NUL byte, long
foreach (['' => 'empty', '한글 ✓ 😀' => 'unicode', str_repeat('x', 200000) => '200 KB'] as $text => $what) {
    $list->setString(0, (string) $text);
    same((string) $text, $list->getString(0), "a string: $what");
}
$list->setString(0, "a\0b");
check(in_array($list->getString(0), ["a\0b", 'a'], true), 'a NUL byte in a string is kept or cuts the string, and does not crash');

// ---- enums
same(ValueType::INT, (function () { $v = Value::create(); $v->setInt(1); return $v->getType(); })(), 'an enum result is an int');
$image = Image::create();
check($image->addBitmap(1.0, 1, 1, ColorType::BGRA_8888, AlphaType::OPAQUE, "\0\0\0\xff"), 'an enum argument is an int constant');

// ---- structs: in, out, in and out
$parts = new URLParts();
$out = Cef::parseURL('http://a.b:81/p?q=1', $parts);
same(['a.b', '81'], [$out->host, $out->port], 'a struct argument comes back filled (the same fields)');
$rect = new Rect(1, 2, 3, 4);
same([1, 2, 3, 4], [$rect->x, $rect->y, $rect->width, $rect->height], 'a struct made with new keeps its constructor arguments');
$rect->width = 30;
same(30, $rect->width, 'and its fields can be changed');
$pdf = new PdfPrintSettings();
$pdf->landscape = 1;
$pdf->scale = 0.5;
$pdf->headerTemplate = 'head';
same([1, 0.5, 'head'], [$pdf->landscape, $pdf->scale, $pdf->headerTemplate], 'struct fields of int, float and string');

// ---- vectors: out and in
$ext = Cef::getExtensionsForMimeType('image/png');
check(is_array($ext) && in_array('png', $ext, true), 'a vector of strings comes out as an array');
$cl = CommandLine::create();
$cl->setProgram('p');
$cl->appendArgument('a');
$cl->appendArgument('b');
same(['a', 'b'], $cl->getArguments(), 'getArguments is a list of strings');
same(['p', 'a', 'b'], $cl->getArgv(), 'getArgv is the program and the arguments');

// ---- maps
$request = Request::create();
$request->setHeaderMap(['A' => '1', 'B' => '2']);
same(['A' => '1', 'B' => '2'], $request->getHeaderMap(), 'a string map goes in and comes out as an associative array');
throws(fn() => $request->setHeaderMap('not an array'), TypeError::class, 'a map argument that is not an array');

// ---- a library object returned, a client object passed
$dict = DictionaryValue::create();
$dict->setList('items', $list);
check($dict->getList('items') instanceof ListValue, 'a library object comes out as its class');
same(null, $dict->getList('missing'), 'and a missing one as null');
final class Noop extends Task { public function execute(): void {} }
check(Cef::postTask(\Cefloom\Types\ThreadId::UI, new Noop()), 'a client object (a Task) goes in');
throws(fn() => Cef::postTask(\Cefloom\Types\ThreadId::UI, new stdClass()), TypeError::class, 'an object of another class where a Task is expected');
throws(fn() => Cef::postTask(\Cefloom\Types\ThreadId::UI, null), TypeError::class, 'null where a Task is required');

// ---- out arguments come back as an array with the result
$info = Image::create();
$info->addBitmap(1.0, 2, 3, ColorType::BGRA_8888, AlphaType::OPAQUE, str_repeat("\0\0\0\xff", 6));
$bitmap = $info->getAsBitmap(1.0, ColorType::BGRA_8888, AlphaType::OPAQUE);
check(is_array($bitmap) && count($bitmap) === 3 && $bitmap[1] === 2 && $bitmap[2] === 3, 'a method with out arguments returns [result, out1, out2]');

// ---- wrong types are a TypeError (strict_types is on in this file)
throws(fn() => $list->setInt(0, '5'), TypeError::class, 'a string where an int is expected');
throws(fn() => $list->setInt(0, 5.5), TypeError::class, 'a float where an int is expected');
throws(fn() => $list->setString(0, 5), TypeError::class, 'an int where a string is expected');
throws(fn() => $list->setString(0, null), TypeError::class, 'null where a string is expected');
throws(fn() => $list->setBool(0, 1), TypeError::class, 'an int where a bool is expected');
throws(fn() => $list->setList(0, new stdClass()), TypeError::class, 'an object of another class where a ListValue is expected');
throws(fn() => $list->setInt(), ArgumentCountError::class, 'too few arguments');
throws(fn() => $list->setInt(0, 1, 2), ArgumentCountError::class, 'too many arguments');
throws(fn() => $rect->width = 'wide', TypeError::class, 'a struct field of the wrong type');
throws(fn() => new Rect('x'), TypeError::class, 'a struct constructor with the wrong type');
throws(fn() => new Point(1.5, 2), TypeError::class, 'a float where the struct wants an int');
throws(fn() => new ListValue(), Error::class, 'a library class cannot be made with new');

// ---- objects after shutdown
$keep = ListValue::create();
$keep->setInt(0, 1);
$event = WaitableEvent::create(true, false);
Cef::shutdown();
throws(fn() => $event->signal(), Error::class, 'a CEF object used after shutdown is an Error, not a crash');
throws(fn() => $event->isSignaled(), Error::class, 'also for a method that answers');
check(true, 'and the process is still alive');
finish();
