<?php
// Streams with a PHP handler (ReadHandler, WriteHandler), the parts of ZipReader and XmlReader that t_lib_pure leaves out, and process
// messages (ProcessMessage, SharedProcessMessageBuilder, SharedMemoryRegion).
require __DIR__ . '/support.php';

use Cefloom\{Buffer, Cef, ProcessMessage, ReadHandler, SharedProcessMessageBuilder, StreamReader, StreamWriter, WriteHandler, XmlReader, ZipReader};

final class Memory extends ReadHandler
{
    public int $pos = 0;
    public array $calls = [];
    public function __construct(public string $data) {}
    public function read(Buffer $ptr, int $size): int
    {
        $this->calls[] = "read:$size";
        $chunk = substr($this->data, $this->pos, $size);
        $ptr->write($chunk, 0);
        $this->pos += strlen($chunk);
        return strlen($chunk);
    }
    public function seek(int $offset, int $whence): int
    {
        $this->calls[] = "seek:$offset:$whence";
        $this->pos = match ($whence) { 0 => $offset, 1 => $this->pos + $offset, default => strlen($this->data) + $offset };
        return 0;
    }
    public function tell(): int { return $this->pos; }
    public function eof(): int { return (int) ($this->pos >= strlen($this->data)); }
    public function mayBlock(): bool { return true; }
}
final class Sink extends WriteHandler
{
    public string $data = '';
    public int $flushes = 0;
    public function write(Buffer $ptr, int $size): int { $this->data .= $ptr->read(0, $size); return $size; }
    public function seek(int $offset, int $whence): int { return 0; }
    public function tell(): int { return strlen($this->data); }
    public function flush(): int { $this->flushes++; return 0; }
    public function mayBlock(): bool { return false; }
}

start_cef();

// ---- a StreamReader on a PHP ReadHandler
$handler = new Memory('0123456789abcdef');
$reader = StreamReader::createForHandler($handler);
check($reader !== null, 'createForHandler gives a StreamReader');
same('0123', $reader->read(1, 4), 'read() calls the handler for the bytes');
same(4, $reader->tell(), 'tell() asks the handler');
same(0, $reader->seek(8, 0), 'seek() goes to the handler');
same('89ab', $reader->read(1, 4), 'and reading goes on from there');
same(true, $reader->mayBlock(), 'mayBlock() is the answer of the handler');
$reader->seek(0, 2);
check($reader->eof() !== 0, 'eof() at the end');
check(in_array('seek:8:0', $handler->calls, true), 'the handler saw the seek');

// ---- a StreamWriter on a PHP WriteHandler
$sink = new Sink();
$writer = StreamWriter::createForHandler($sink);
check($writer !== null, 'createForHandler gives a StreamWriter');
same(1, $writer->write('hello ', 6), 'write() returns the number of items');
$writer->write('world', 5);
same('hello world', $sink->data, 'the handler got what was written');
same(11, $writer->tell(), 'tell()');
$writer->flush();
same(1, $sink->flushes, 'flush() goes to the handler');
same([0, false], [$writer->seek(0, 0), $writer->mayBlock()], 'seek() and mayBlock()');

// ---- a StreamWriter on a file: seek
$dir = tmpdir('cefloom-streams');
$fileWriter = StreamWriter::createForFile("$dir/w.bin");
$fileWriter->write('abcdef', 6);
$fileWriter->seek(2, 0);
$fileWriter->write('XY', 2);
$fileWriter->flush();
unset($fileWriter);
same('abXYef', file_get_contents("$dir/w.bin"), 'StreamWriter::seek() moves the write position');
same(true, StreamWriter::createForFile("$dir/w2.bin")->mayBlock(), 'a file stream may block (file I/O can wait)');

// ---- ZipReader: close, tell, times
Cef::zipDirectory($dir, "$dir/z.zip", false);
$zip = ZipReader::create(StreamReader::createForFile("$dir/z.zip"));
check($zip->moveToFirstFile(), 'the first file of a ZIP');
$name = $zip->getFileName();
$zip->openFile('');
check($zip->tell() >= 0, 'tell() while a file is open');
$modified = $zip->getFileLastModified();
check($modified instanceof DateTimeImmutable || is_int($modified), 'getFileLastModified: ' . var_export($modified instanceof DateTimeImmutable ? $modified->format('c') : $modified, true));
$zip->closeFile();
check($zip->close(), 'close() closes the reader');

// ---- XmlReader: the rest of the node accessors
$xml = XmlReader::create(StreamReader::createForData('<?xml version="1.0"?><p:root xmlns:p="urn:test" xml:lang="ko" a="1"><child>text <b>bold</b></child></p:root>'), 0, 'x.xml');
check($xml->moveToNextNode(), 'the root element');
same(['p:root', 'root', 'p', 'urn:test'], [$xml->getQualifiedName(), $xml->getLocalName(), $xml->getPrefix(), $xml->getNamespaceURI()], 'qualified name, local name, prefix and namespace');
same('ko', $xml->getXmlLang(), 'xml:lang');
check($xml->hasAttributes(), 'hasAttributes');
same('x.xml', $xml->getBaseURI(), 'getBaseURI is the URI given to create()');
same(1, $xml->getLineNumber(), 'getLineNumber');
$xml->moveToNextNode();
same('child', $xml->getLocalName(), 'the child element');
check(str_contains($xml->getInnerXml(), '<b>bold</b>'), 'getInnerXml: ' . $xml->getInnerXml());
check($xml->close(), 'close()');

// ---- process messages
$message = ProcessMessage::create('my-message');
check($message !== null && $message->isValid() && !$message->isReadOnly(), 'a process message is made');
same('my-message', $message->getName(), 'its name');
$args = $message->getArgumentList();
$args->setString(0, 'text');
$args->setInt(1, 42);
$copy = $message->copy();
$copy->getArgumentList()->setInt(1, 7);
same([42, 7], [$message->getArgumentList()->getInt(1), $copy->getArgumentList()->getInt(1)], 'copy() is independent of the original');
same(null, $message->getSharedMemoryRegion(), 'an ordinary message has no shared memory');

// ---- shared memory messages
$builder = SharedProcessMessageBuilder::create('shared', 64);
check($builder !== null && $builder->isValid() && $builder->size() === 64, 'a builder for 64 bytes of shared memory');
$built = $builder->build();
check($built !== null && $built->getName() === 'shared', 'build() gives the message');
$region = $built->getSharedMemoryRegion();
check($region !== null && $region->isValid() && $region->size() === 64, 'with a shared memory region of that size');
check(!$builder->isValid(), 'and the builder is spent after build()');

Cef::shutdown();
finish();
