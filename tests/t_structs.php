<?php
require __DIR__ . '/support.php';

use Cefloom\{Rect, Point, Size, Settings, BrowserSettings, WindowInfo, Cookie, MouseEvent, KeyEvent};
use Cefloom\Types\{MouseButtonType, LogSeverity, TransitionType};

// constructors: every argument is optional, named arguments work
$r = new Rect(1, 2, 3, 4);
same([1, 2, 3, 4], [$r->x, $r->y, $r->width, $r->height], 'Rect positional');
$r = new Rect(width: 10);
same([0, 0, 10, 0], [$r->x, $r->y, $r->width, $r->height], 'Rect named');
$r->x = 7;
same(7, $r->x, 'property assignment');
throws(fn() => $r->x = 'abc', TypeError::class, 'typed property');
throws(fn() => $r->nope = 1, Error::class, 'no dynamic properties');

// nested structs get defaults
$w = new WindowInfo();
check($w->bounds instanceof Rect, 'nested struct default');
same('', $w->windowName, 'string default');
$w2 = new WindowInfo(windowName: 'x', bounds: new Rect(1, 2, 3, 4));
same(3, $w2->bounds->width, 'nested struct argument');

// settings carry many fields of every kind
$s = new Settings();
same(0, $s->logSeverity, 'enum field default');
$s->logSeverity = LogSeverity::WARNING;
same(LogSeverity::WARNING, $s->logSeverity, 'enum field set');
$s->cachePath = '/tmp/x';
same('/tmp/x', $s->cachePath, 'string field');
check($s->backgroundColor === 0, 'color field');

// a struct with a time
$c = new Cookie(name: 'a', value: 'b');
same(null, $c->expires, 'time field default is null');

// the constants of the enumerations
same(0, MouseButtonType::LEFT, 'enum constant');
check(MouseButtonType::RIGHT !== MouseButtonType::LEFT, 'enum constants differ');
check(defined(TransitionType::class . '::LINK'), 'TransitionType::LINK');
finish();
