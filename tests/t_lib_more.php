<?php
// More of the library classes that need no browser: Request, Response, MenuModel (the index-based half), DragData and Display.
require __DIR__ . '/support.php';

use Cefloom\{Cef, Display, DragData, MenuModel, MenuModelDelegate, Point, Rect, Request, Response};
use Cefloom\Types\{ErrorCode, MenuColorType, ReferrerPolicy, URLRequestFlags};

start_cef();

// ---- Request
$r = Request::create();
$r->setURL('http://example.com/a?b=1');
$r->setReferrer('http://ref.example/', ReferrerPolicy::ORIGIN);
same(['http://ref.example/', ReferrerPolicy::ORIGIN], [$r->getReferrerURL(), $r->getReferrerPolicy()], 'referrer and policy');
$r->setFlags(URLRequestFlags::SKIP_CACHE | URLRequestFlags::ALLOW_STORED_CREDENTIALS);
same(URLRequestFlags::SKIP_CACHE | URLRequestFlags::ALLOW_STORED_CREDENTIALS, $r->getFlags(), 'flags');
$r->setFirstPartyForCookies('http://example.com/');
same('http://example.com/', $r->getFirstPartyForCookies(), 'first party for cookies');
$r->setHeaderByName('X-One', 'a', true);
$r->setHeaderByName('X-One', 'b', false);
same('a', $r->getHeaderByName('X-One'), 'setHeaderByName with overwrite false keeps the first value');
$r->setHeaderByName('X-One', 'c', true);
same('c', $r->getHeaderByName('x-one'), 'and with overwrite true replaces it');
same(false, $r->isReadOnly(), 'a new request is writable');
check(is_int($r->getIdentifier()) && is_int($r->getResourceType()) && is_int($r->getTransitionType()), 'identifier, resource type and transition type are ints');
$r2 = Request::create();
$r2->set('http://set.example/', 'POST', null, ['X-S' => '1']);
same(['http://set.example/', 'POST', '1'], [$r2->getURL(), $r2->getMethod(), $r2->getHeaderByName('X-S')], 'Request::set sets URL, method and headers together');

// ---- Response
$s = Response::create();
$s->setStatus(404);
$s->setStatusText('Nope');
$s->setCharset('utf-8');
$s->setMimeType('text/plain');
$s->setError(ErrorCode::ABORTED);
$s->setURL('http://example.com/missing');
$s->setHeaderMap(['A' => '1']);
$s->setHeaderByName('B', '2', false);
same([404, 'Nope', 'utf-8', 'text/plain', ErrorCode::ABORTED, 'http://example.com/missing'], [$s->getStatus(), $s->getStatusText(), $s->getCharset(), $s->getMimeType(), $s->getError(), $s->getURL()], 'status, status text, charset, MIME type, error and URL');
same(['1', '2', false], [$s->getHeaderByName('A'), $s->getHeaderByName('B'), $s->isReadOnly()], 'headers by name');
same(['A' => '1', 'B' => '2'], array_intersect_key($s->getHeaderMap(), ['A' => 1, 'B' => 1]), 'and the header map');

// ---- MenuModel, by index
final class Cmd extends MenuModelDelegate
{
    public function executeCommand(MenuModel $menuModel, int $commandId, int $eventFlags): void {}
}
$m = MenuModel::create(new Cmd());
$m->addItem(10, 'Ten');
$m->insertCheckItemAt(1, 11, 'Check');
$m->insertRadioItemAt(2, 12, 'Radio', 3);
$m->insertSeparatorAt(3);
$sub = $m->insertSubMenuAt(4, 13, 'Sub');
check($sub !== null && $m->getSubMenuAt(4) !== null && $m->getSubMenuAt(0) === null, 'insertSubMenuAt / getSubMenuAt');
same(5, $m->getCount(), 'five entries');
same([10, 11, 12, 13], [$m->getCommandIdAt(0), $m->getCommandIdAt(1), $m->getCommandIdAt(2), $m->getCommandIdAt(4)], 'command ids by index');
$m->setCommandIdAt(0, 20);
same(20, $m->getCommandIdAt(0), 'setCommandIdAt');
$m->setCheckedAt(1, true);
$m->setEnabledAt(1, false);
$m->setVisibleAt(1, false);
same([true, false, false], [$m->isCheckedAt(1), $m->isEnabledAt(1), $m->isVisibleAt(1)], 'checked, enabled and visible by index');
$m->setGroupIdAt(2, 9);
same([9, 9], [$m->getGroupIdAt(2), $m->getGroupId(12)], 'group id by index');
$m->setGroupId(12, 4);
same(4, $m->getGroupIdAt(2), 'setGroupId');
$m->setAcceleratorAt(0, 65, true, true, false);
same(true, $m->hasAcceleratorAt(0), 'hasAcceleratorAt');
$acc = $m->getAcceleratorAt(0);
check(is_array($acc) && $acc[0] === 65, 'getAcceleratorAt: ' . json_encode($acc));
$m->removeAcceleratorAt(0);
same(false, $m->hasAcceleratorAt(0), 'removeAcceleratorAt');
$m->setColor(20, MenuColorType::TEXT, 0xFF112233);
same(0xFF112233, $m->getColor(20, MenuColorType::TEXT), 'setColor / getColor');
$m->setColorAt(2, MenuColorType::BACKGROUND, 0xFF445566);
same(0xFF445566, $m->getColorAt(2, MenuColorType::BACKGROUND), 'setColorAt / getColorAt');
check($m->setFontList(20, 'Arial, 12px') && $m->setFontListAt(2, 'Arial, 10px'), 'setFontList / setFontListAt');

// ---- DragData: the rest
$d = DragData::create();
$d->setLinkURL('http://example.com/');
$d->setLinkMetadata('meta');
check(is_string($d->getLinkMetadata()), 'getLinkMetadata answers (CEF keeps the metadata apart from the link: it does not read back what setLinkMetadata set)');
$d->addFile('/etc/hostname', 'host.txt');
same(['host.txt'], $d->getFileNames(), 'getFileNames');
check(is_string($d->getFileName()), 'getFileName (the name of dragged file contents) answers');
same(false, $d->hasImage(), 'no image');
check($d->getImage() === null, 'getImage is null without one');
check($d->getImageHotspot() instanceof Point, 'getImageHotspot');
$d->resetFileContents();
same(false, $d->isReadOnly(), 'a new DragData is writable');
check($d->getFileContents(null) >= 0, 'getFileContents without a writer answers');

// ---- Display
$p = Display::getPrimaryDisplay();
check($p->getRotation() >= 0, 'getRotation');
$pt = $p->convertPointToPixels(new Point(10, 10));
$back = $p->convertPointFromPixels($pt);
same([10, 10], [$back->x, $back->y], 'a point converted to pixels and back');
$sp = $p->convertScreenPointToPixels(new Point(20, 20));
$sb = $p->convertScreenPointFromPixels($sp);
same([20, 20], [$sb->x, $sb->y], 'a screen point converted to pixels and back');
$rect = $p->convertScreenRectToPixels(new Rect(0, 0, 100, 50));
$rb = $p->convertScreenRectFromPixels($rect);
same([100, 50], [$rb->width, $rb->height], 'a screen rectangle converted to pixels and back');
check(Display::getDisplayMatchingBounds(new Rect(0, 0, 10, 10), false)->getID() === $p->getID(), 'the display matching a rectangle at the origin is the primary one');

Cef::shutdown();
finish();
