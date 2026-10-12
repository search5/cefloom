<?php
// The library classes and functions that need no browser: encoding, URLs, MIME types, files and ZIP, the command line, streams,
// ZIP and XML readers, events, threads and task runners, post data and images. CEF runs (some need it) but no page is loaded.
require __DIR__ . '/support.php';

use Cefloom\{Cef, CommandLine, Image, PostData, PostDataElement, StreamReader, StreamWriter, Task, TaskRunner, Thread, URLParts,
    WaitableEvent, XmlReader, ZipReader};
use Cefloom\Types\{ColorType, AlphaType, PostDataElementType, UriUnescapeRule};

start_cef();
$dir = tmpdir('cefloom-pure');

// ---- Base64 and URI encoding
same('aGVsbG8/', Cef::base64Encode('hello?'), 'base64Encode');
$decoded = Cef::base64Decode('aGVsbG8/');
same([6, 'hello?'], [$decoded->getSize(), $decoded->getData(6, 0)], 'base64Decode gives a BinaryValue');
same('lo?', $decoded->getData(3, 3), 'and getData(size, offset) reads a part of it');
same(null, Cef::base64Decode('!!!'), 'not base64: null');
same('', Cef::base64Encode(''), 'an empty string encodes to an empty string');
same('a%20b%26c%2F%C3%BC', Cef::uRIEncode('a b&c/ü', false), 'uRIEncode');
same('a+b', Cef::uRIEncode('a b', true), 'uRIEncode with usePlus');
$all = UriUnescapeRule::NORMAL | UriUnescapeRule::SPACES | UriUnescapeRule::PATH_SEPARATORS | UriUnescapeRule::URL_SPECIAL_CHARS_EXCEPT_PATH_SEPARATORS;
same('a%20b%2Fc', Cef::uRIDecode('a%20b%2Fc', true, UriUnescapeRule::NONE), 'uRIDecode with the rule NONE leaves everything');
same('a b/c', Cef::uRIDecode('a%20b%2Fc', true, $all), 'uRIDecode with the rules that unescape spaces and path separators');
same('안', Cef::uRIDecode('%EC%95%88', true, $all), 'uRIDecode to UTF-8');

// ---- URLs
$parts = Cef::parseURL('https://user:pw@example.com:8080/a/b?x=1#frag', new URLParts());
same(['https', 'example.com', '8080', '/a/b', 'x=1', 'user', 'pw'], [$parts->scheme, $parts->host, $parts->port, $parts->path, $parts->query, $parts->username, $parts->password], 'parseURL splits a URL');
same('https://example.com:8080/', $parts->origin, 'and gives its origin');
same(null, Cef::parseURL('not a url', new URLParts()), 'a string that is not a URL: null');
$made = new URLParts();
$made->scheme = 'http';
$made->host = 'example.org';
$made->path = '/x';
$made->query = 'k=v';
same('http://example.org/x?k=v', Cef::createURL($made), 'createURL puts the parts together');
same('http://a.com/d', Cef::resolveURL('http://a.com/b/c', '../d'), 'resolveURL: relative');
same('http://z/', Cef::resolveURL('http://a.com/b/c', 'http://z/'), 'resolveURL: absolute');
same('https://www.example.com', Cef::formatUrlForSecurityDisplay('https://www.example.com/path?q=1'), 'formatUrlForSecurityDisplay drops the path');

// ---- MIME types
same(['text/html', 'image/png', ''], [Cef::getMimeType('html'), Cef::getMimeType('png'), Cef::getMimeType('no-such-extension')], 'getMimeType');
check(in_array('jpg', Cef::getExtensionsForMimeType('image/jpeg'), true), 'getExtensionsForMimeType lists jpg for image/jpeg');
same([], Cef::getExtensionsForMimeType('x-no/such-type'), 'and nothing for an unknown type');

// ---- files and ZIP (CEF refuses these on its own UI thread; the binding runs them on a helper thread)
same([true, false], [Cef::directoryExists($dir), Cef::directoryExists("$dir/none")], 'directoryExists');
same([true, true], [Cef::createDirectory("$dir/a/b"), is_dir("$dir/a/b")], 'createDirectory makes the parents too');
file_put_contents("$dir/a/f.txt", 'hello zip');
file_put_contents("$dir/a/.hidden", 'h');
same(true, Cef::zipDirectory("$dir/a", "$dir/plain.zip", false), 'zipDirectory');
same(true, Cef::zipDirectory("$dir/a", "$dir/hidden.zip", true), 'zipDirectory with hidden files');
$names = function (string $zip): array {
    $reader = ZipReader::create(StreamReader::createForFile($zip));
    $found = [];
    if ($reader->moveToFirstFile()) {
        do { $found[] = $reader->getFileName(); } while ($reader->moveToNextFile());
    }
    sort($found);
    return $found;
};
same(['b/', 'f.txt'], $names("$dir/plain.zip"), 'the ZIP without hidden files lists the file and the directory');
same(['.hidden', 'b/', 'f.txt'], $names("$dir/hidden.zip"), 'and with them also the hidden one');
$zip = ZipReader::create(StreamReader::createForFile("$dir/plain.zip"));
check($zip->moveToFile('f.txt', true), 'moveToFile finds a file by name');
same([9, true, 'hello zip', true], [$zip->getFileSize(), $zip->openFile(''), $zip->readFile(100), $zip->eof()], 'and reads it');
same(true, $zip->closeFile(), 'closeFile');
check(!$zip->moveToFile('nothing.txt', true), 'moveToFile on a missing name: false');
$sub = Cef::createTempDirectoryInDirectory($dir, 'p');
check($sub !== null && is_dir($sub) && str_starts_with(basename($sub), 'p'), 'createTempDirectoryInDirectory');
$temp = Cef::createNewTempDirectory('cl');
check($temp !== null && is_dir($temp), 'createNewTempDirectory');
check(Cef::getTempDirectory() !== null, 'getTempDirectory');
same([true, false], [Cef::deleteFile("$dir/a", true), is_dir("$dir/a")], 'deleteFile (recursive)');
if ($temp) {
    Cef::deleteFile($temp, true);
}
same(null, Cef::createTempDirectoryInDirectory("$dir/none/where", 'x'), 'a temporary directory in a missing directory: null');

// ---- the other global functions
same([true, true, true], [Cef::addCrossOriginWhitelistEntry('http://a.com', 'http', 'b.com', true),
    Cef::removeCrossOriginWhitelistEntry('http://a.com', 'http', 'b.com', true), Cef::clearCrossOriginWhitelist()], 'the cross-origin allow list: add, remove, clear');
Cef::setCrashKeyValue('cefloom-test', 'value');
check(true, 'setCrashKeyValue does not fail');
same([false, true], [Cef::isCertStatusError(0), Cef::isCertStatusError(1)], 'isCertStatusError');
same([null, 'EOF while parsing a value at line 1 column 5'], Cef::parseJSONAndReturnError('{"a":', 0), 'parseJSONAndReturnError names the error');
same(false, Cef::isRTL(), 'isRTL (an English locale)');
check(Cef::nowFromSystemTraceTime() > 0, 'nowFromSystemTraceTime');
check(Cef::getPath(0) !== null, 'getPath of a known key');
same(null, Cef::getPath(99999), 'and of an unknown key: null');

// ---- the command line
$cl = CommandLine::create();
$cl->setProgram('/usr/bin/prog');
$cl->appendSwitch('verbose');
$cl->appendSwitchWithValue('level', '3');
$cl->appendArgument('file1');
$cl->appendArgument('file two');
same('/usr/bin/prog', $cl->getProgram(), 'CommandLine: program');
same([true, false, true], [$cl->hasSwitch('verbose'), $cl->hasSwitch('quiet'), $cl->hasSwitches()], 'switches');
same(['3', ''], [$cl->getSwitchValue('level'), $cl->getSwitchValue('verbose')], 'switch values');
$all = $cl->getSwitches();
ksort($all);
same(['level' => '3', 'verbose' => ''], $all, 'all switches');
same([true, ['file1', 'file two']], [$cl->hasArguments(), $cl->getArguments()], 'arguments');
same('/usr/bin/prog', $cl->getArgv()[0], 'argv begins with the program');
check(in_array('--level=3', $cl->getArgv(), true) && in_array('file1', $cl->getArgv(), true), 'argv has the switches and the arguments');
check(str_contains($cl->getCommandLineString(), '--level=3'), 'getCommandLineString');
$cl->removeSwitch('verbose');
same(false, $cl->hasSwitch('verbose'), 'removeSwitch');
$copy = $cl->copy();
$copy->appendSwitch('only-in-copy');
same([true, false], [$copy->hasSwitch('only-in-copy'), $cl->hasSwitch('only-in-copy')], 'copy() is independent of the original');
$cl->prependWrapper('wrapper');
same('wrapper', $cl->getArgv()[0], 'prependWrapper');
$cl->reset();
same([false, []], [$cl->hasSwitches(), $cl->getArguments()], 'reset clears switches and arguments');
check(CommandLine::getGlobalCommandLine() !== null, 'the global command line exists');

// ---- streams
$reader = StreamReader::createForData('hello world');
same(['hello', 5], [$reader->read(1, 5), $reader->tell()], 'StreamReader: read and tell');
same([0, 'hel'], [$reader->seek(0, 0), $reader->read(1, 3)], 'seek to the start (SEEK_SET) and read');
$reader->seek(0, 2);
same([11, ''], [$reader->tell(), $reader->read(1, 1)], 'seek to the end (SEEK_END): nothing more to read');
check($reader->eof() !== 0, 'eof at the end');
same(false, $reader->mayBlock(), 'a memory stream does not block');
$writer = StreamWriter::createForFile("$dir/w.txt");
same([1, 3], [$writer->write('abc', 3), $writer->tell()], 'StreamWriter: write and tell');
$writer->write('def', 3);
$writer->flush();
unset($writer);
same('abcdef', file_get_contents("$dir/w.txt"), 'the file has what was written');
file_put_contents("$dir/r.txt", 'from a file');
same('from a file', StreamReader::createForFile("$dir/r.txt")->read(1, 11), 'createForFile reads a file');
same(null, StreamReader::createForFile("$dir/none.txt"), 'createForFile on a missing file: null');

// ---- XML
$xml = XmlReader::create(StreamReader::createForData('<?xml version="1.0"?><root a="1" b="2"><c>text</c><d/></root>'), 0, 'test.xml');
$seen = [];
while ($xml->moveToNextNode()) {
    $seen[] = [$xml->getType(), $xml->getDepth(), $xml->getQualifiedName(), $xml->hasValue() ? $xml->getValue() : null, $xml->isEmptyElement()];
}
same(false, $xml->hasError(), 'a well formed document reads without error');
same(['root', 'c', '#text', 'c', 'd', 'root'], array_column($seen, 2), 'XmlReader walks the nodes');
same([0, 1, 2, 1, 1, 0], array_column($seen, 1), 'with their depth');
same(['text'], array_values(array_filter(array_column($seen, 3))), 'the text node has its value');
same([false, false, false, false, true, false], array_column($seen, 4), 'and <d/> is an empty element');
$xml = XmlReader::create(StreamReader::createForData('<r a="1" b="2"/>'), 0, 'attrs.xml');
$xml->moveToNextNode();
same([2, '1', '2'], [$xml->getAttributeCount(), $xml->getAttribute(0), $xml->getAttribute(1)], 'attributes by index');
check($xml->moveToAttribute(1) && $xml->getValue() === '2', 'moveToAttribute');
check($xml->moveToCarryingElement() && $xml->moveToFirstAttribute() && $xml->moveToNextAttribute(), 'moveToCarryingElement, moveToFirstAttribute, moveToNextAttribute');
same('<r a="1" b="2"/>', $xml->getOuterXml(), 'getOuterXml');
$xml = XmlReader::create(StreamReader::createForData('<r><a></r>'), 0, 'bad.xml');
while ($xml->moveToNextNode()) {
}
check($xml->hasError() && str_contains($xml->getError(), 'mismatch'), 'a malformed document sets the error: ' . $xml->getError());

// ---- events (CEF refuses to wait on its UI thread: the binding waits on a helper thread)
$auto = WaitableEvent::create(true, false);
same(false, $auto->timedWait(10), 'a wait on an event that is not signaled runs out');
$auto->signal();
same([true, false], [$auto->timedWait(10), $auto->timedWait(10)], 'an automatic reset event releases one wait and is unsignaled again');
$manual = WaitableEvent::create(false, true);
same([true, true], [$manual->timedWait(1), $manual->timedWait(1)], 'a manual reset event that starts signaled stays signaled');
$manual->wait();
check(true, 'wait() on a signaled event returns');
$manual->reset();
same(false, $manual->timedWait(10), 'reset() unsignals it');
$manual->signal();
same(true, $manual->isSignaled(), 'signal() signals it (isSignaled does not consume a manual reset event)');

// ---- threads and task runners: a task posted to a thread runs (its PHP code on the PHP thread)
final class Mark extends Task
{
    public int $runs = 0;
    public function execute(): void { $this->runs++; }
}
$thread = Thread::create('cefloom-test-thread', 0, 0, true, 0);
same(true, $thread->isRunning(), 'a thread is running');
$runner = $thread->getTaskRunner();
same([false, true], [$runner->belongsToCurrentThread(), $runner->isSame($thread->getTaskRunner())], 'its task runner is not the one of this thread');
$mark = new Mark();
check($runner->postTask($mark), 'postTask');
wait_until(fn() => $mark->runs === 1, 'the task ran');
check($runner->postDelayedTask($mark, 50), 'postDelayedTask');
wait_until(fn() => $mark->runs === 2, 'the delayed task ran');
$here = TaskRunner::getForCurrentThread();
same([true, true], [$here->belongsToCurrentThread(), $here->belongsToThread(\Cefloom\Types\ThreadId::UI)], 'the task runner of this thread');
$thread->stop();
same(false, $thread->isRunning(), 'stop() stops the thread');

// ---- post data
$post = PostData::create();
$bytes = PostDataElement::create();
$bytes->setToBytes('body');
$file = PostDataElement::create();
$file->setToFile('/etc/hostname');
check($post->addElement($bytes) && $post->addElement($file), 'addElement');
same(2, $post->getElementCount(), 'two elements');
same([PostDataElementType::BYTES, 4, 'body'], [$bytes->getType(), $bytes->getBytesCount(), $bytes->getBytes(4)], 'a bytes element');
same([PostDataElementType::FILE, '/etc/hostname'], [$file->getType(), $file->getFile()], 'a file element');
same(2, count($post->getElements()), 'getElements');
check($post->removeElement($file), 'removeElement');
same(1, $post->getElementCount(), 'one left');
$post->removeElements();
same(0, $post->getElementCount(), 'removeElements');
$bytes->setToEmpty();
same(PostDataElementType::EMPTY, $bytes->getType(), 'setToEmpty');

// ---- images
$image = Image::create();
same([true, 0, 0], [$image->isEmpty(), $image->getWidth(), $image->getHeight()], 'a new image is empty');
$pixels = str_repeat("\x00\x00\xff\xff", 4 * 3);     // 4 x 3 pixels, BGRA
check($image->addBitmap(1.0, 4, 3, ColorType::BGRA_8888, AlphaType::OPAQUE, $pixels), 'addBitmap');
same([false, 4, 3, true], [$image->isEmpty(), $image->getWidth(), $image->getHeight(), $image->hasRepresentation(1.0)], 'an image with a bitmap');
$info = $image->getRepresentationInfo(1.0);
same([1.0, 4, 3], $info, 'getRepresentationInfo is [scale, width, height]');
[$png, $w, $h] = $image->getAsPNG(1.0, true);
$pngBytes = $png->getData($png->getSize(), 0);
same([true, 4, 3], [str_starts_with($pngBytes, "\x89PNG"), $w, $h], 'getAsPNG gives a BinaryValue with a PNG, and its size');
$back = Image::create();
check($back->addPNG(1.0, $pngBytes) && $back->getWidth() === 4 && $back->getHeight() === 3, 'and addPNG reads it back');
[$bitmap, $bw, $bh] = $image->getAsBitmap(1.0, ColorType::BGRA_8888, AlphaType::OPAQUE);
same([strlen($pixels), 4, 3], [$bitmap->getSize(), $bw, $bh], 'getAsBitmap gives the pixels back, and their size');
same($pixels, $bitmap->getData($bitmap->getSize(), 0), 'the same pixels');
[$jpeg] = $image->getAsJPEG(1.0, 90);
same("\xff\xd8", $jpeg->getData(2, 0), 'getAsJPEG gives a JPEG');
check($image->removeRepresentation(1.0) && !$image->hasRepresentation(1.0), 'removeRepresentation');
check($image->isSame($image) && !$image->isSame($back), 'isSame');

Cef::shutdown();
finish();
