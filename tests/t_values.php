<?php
require __DIR__ . '/support.php';

use Cefloom\{Cef, CommandLine, DictionaryValue, ListValue, Value, BinaryValue, Request, Response, PostData, PostDataElement};
use Cefloom\Types\ValueType;

// CefValue / CefDictionaryValue / CefListValue (vectors, nesting, reference semantics)
$d = DictionaryValue::create();
check($d->setString('name', 'cefloom'), 'setString');
check($d->setInt('n', 42), 'setInt');
check($d->setBool('b', true), 'setBool');
check($d->setDouble('f', 1.5), 'setDouble');
same('cefloom', $d->getString('name'), 'getString');
same(42, $d->getInt('n'), 'getInt');
same(true, $d->getBool('b'), 'getBool');
same(1.5, $d->getDouble('f'), 'getDouble');
same(4, $d->getSize(), 'getSize');
$keys = $d->getKeys();                      // a vector<CefString> output; the bool says whether it is valid
sort($keys);
same(['b', 'f', 'n', 'name'], $keys, 'getKeys returns a list of strings');
same(ValueType::INT, $d->getType('n'), 'getType returns an enum value');
check($d->hasKey('n') && !$d->hasKey('zz'), 'hasKey');

$l = ListValue::create();
$l->setSize(3);
$l->setInt(0, 10);
$l->setString(1, 'x');
$l->setNull(2);
same(3, $l->getSize(), 'list size');
same(10, $l->getInt(0), 'list int');
same('x', $l->getString(1), 'list string');
check($d->setList('list', $l), 'setList passes an object');
$back = $d->getList('list');
check($back instanceof ListValue, 'getList returns an object');
same('x', $back->getString(1), 'nested value is shared');

$copy = $d->copy(false);
$copy->setInt('n', 1);
same(42, $d->getInt('n'), 'copy is independent');

// JSON through the global functions (Cef::parseJSON / Cef::writeJSON)
$v = Cef::parseJSON('{"a":[1,2,{"b":"c"}],"t":true}', 0);
check($v instanceof Value, 'parseJSON returns a Value');
$json = Cef::writeJSON($v, 0);
same('{"a":[1,2,{"b":"c"}],"t":true}', $json, 'writeJSON round trip');

// binary values: bytes in, bytes out
$b = BinaryValue::create("hello\0world");
same(11, $b->getSize(), 'binary size');
same("hello\0world", $b->getData(11, 0), 'binary data (a size in, bytes out)');
same("world", $b->getData(5, 6), 'binary data with an offset');

// requests: strings, maps, enums, objects
$req = Request::create();
$req->setURL('https://example.com/a');
$req->setMethod('POST');
$req->setHeaderByName('X-Test', 'yes', true);
same('https://example.com/a', $req->getURL(), 'request url');
same('POST', $req->getMethod(), 'request method');
same('yes', $req->getHeaderByName('X-Test'), 'header by name');
$map = $req->getHeaderMap();                // multimap<CefString, CefString> as an output
same('yes', $map['X-Test'] ?? null, 'header map is a PHP array');
$req->setHeaderMap(['A' => '1', 'B' => '2']);
same(['A' => '1', 'B' => '2'], $req->getHeaderMap(), 'header map in and out');

$post = PostData::create();
$el = PostDataElement::create();
$el->setToBytes('abcde');                    // the size is the length of the string
check($post->addElement($el), 'addElement');
same(1, $post->getElementCount(), 'element count');
$req->setPostData($post);
$els = $req->getPostData()->getElements();    // vector<CefRefPtr<...>> as an output
same(1, count($els), 'elements list');
same('abcde', $els[0]->getBytes(5), 'bytes out');

$resp = Response::create();
$resp->setStatus(404);
$resp->setMimeType('text/plain');
same([404, 'text/plain'], [$resp->getStatus(), $resp->getMimeType()], 'response');

// the command line: a map with a custom type of key
$cl = CommandLine::create();
$cl->setProgram('prog');
$cl->appendSwitchWithValue('foo', 'bar');
$cl->appendSwitch('flag');
same('bar', $cl->getSwitchValue('foo'), 'switch value');
check($cl->hasSwitch('flag'), 'hasSwitch');
$sw = $cl->getSwitches();
same('bar', $sw['foo'] ?? null, 'switches map');

// errors: an object of the wrong class, a released object
throws(fn() => $d->setList('x', $d), TypeError::class, 'wrong object class');
throws(fn() => new Request(), Error::class, 'library classes cannot be constructed');
finish();
