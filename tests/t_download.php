<?php
// Downloads: DownloadHandler (canDownload, onBeforeDownload with BeforeDownloadCallback, onDownloadUpdated with DownloadItemCallback)
// and DownloadItem, against the /download route of the local server; also a download that is canceled and one that is refused.
require __DIR__ . '/support.php';

use Cefloom\{BeforeDownloadCallback, Browser, BrowserHost, BrowserSettings, Cef, Client, DownloadHandler, DownloadItem, DownloadItemCallback, LifeSpanHandler, WindowInfo};

$base = start_http_server();
$dir = tmpdir('cefloom-download');

final class Downloads extends DownloadHandler
{
    public array $asked = [];            // [url, method]
    public array $before = [];           // [suggested name, mime type, total bytes, url]
    public array $updates = [];          // snapshots of the item
    public string $mode = 'save';        // save | refuse | cancel | pause
    public string $target = '';
    public ?DownloadItemCallback $control = null;
    public function canDownload(Browser $browser, string $url, string $requestMethod): bool
    {
        $this->asked[] = [$url, $requestMethod];
        return $this->mode !== 'refuse';
    }
    public function onBeforeDownload(Browser $browser, DownloadItem $downloadItem, string $suggestedName, BeforeDownloadCallback $callback): bool
    {
        $this->before[] = [$suggestedName, $downloadItem->getMimeType(), $downloadItem->getTotalBytes(), $downloadItem->getURL(), $downloadItem->getId()];
        $callback->continue($this->target, false);
        return true;
    }
    public function onDownloadUpdated(Browser $browser, DownloadItem $downloadItem, DownloadItemCallback $callback): void
    {
        $this->control = $callback;
        $this->updates[] = [
            'valid' => $downloadItem->isValid(), 'inProgress' => $downloadItem->isInProgress(), 'complete' => $downloadItem->isComplete(),
            'canceled' => $downloadItem->isCanceled(), 'interrupted' => $downloadItem->isInterrupted(), 'paused' => $downloadItem->isPaused(),
            'received' => $downloadItem->getReceivedBytes(), 'total' => $downloadItem->getTotalBytes(), 'percent' => $downloadItem->getPercentComplete(),
            'path' => $downloadItem->getFullPath(), 'id' => $downloadItem->getId(), 'start' => $downloadItem->getStartTime(), 'end' => $downloadItem->getEndTime(),
            'disposition' => $downloadItem->getContentDisposition(), 'original' => $downloadItem->getOriginalUrl(), 'speed' => $downloadItem->getCurrentSpeed(),
            'reason' => $downloadItem->getInterruptReason(),
        ];
        if ($this->mode === 'cancel' && count($this->updates) === 1) {
            $callback->cancel();
        }
    }
    public function last(): array { return end($this->updates) ?: []; }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
}
final class App extends Client
{
    public Downloads $downloads;
    public Life $life;
    public function __construct() { $this->downloads = new Downloads(); $this->life = new Life(); }
    public function getDownloadHandler(): ?DownloadHandler { return $this->downloads; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}

start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 300;
$info->bounds->height = 200;
BrowserHost::createBrowser($info, $app, 'about:blank', new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null, 'the browser exists');
$browser = $app->life->browser;
$d = $app->downloads;

// ---- a download that is saved
$d->target = "$dir/saved.bin";
$browser->getMainFrame()->loadURL("$base/download?size=250000");
wait_until(fn() => ($d->last()['complete'] ?? false), 'the download completed', 30000);
same([["$base/download?size=250000", 'GET']], $d->asked, 'canDownload is asked with the URL and the method');
same('file.bin', $d->before[0][0] ?? null, 'onBeforeDownload gets the suggested name from the server (Content-Disposition)');
check(($d->before[0][2] ?? -1) >= 0, 'and the item (its size may still be unknown there: ' . ($d->before[0][2] ?? '?') . ')');
check(($d->before[0][1] ?? '') !== '', 'and the MIME type: ' . ($d->before[0][1] ?? ''));
same("$base/download?size=250000", $d->before[0][3] ?? null, 'and the URL');
check(is_file("$dir/saved.bin") && filesize("$dir/saved.bin") === 250000, 'the file is where the callback said, with all its bytes');
$last = $d->last();
same([true, true, false, false, false, 250000, 250000, 100], [$last['valid'], $last['complete'], $last['inProgress'], $last['canceled'], $last['interrupted'], $last['received'], $last['total'], $last['percent']], 'the final update: complete, all bytes, 100 percent');
same("$dir/saved.bin", $last['path'], 'getFullPath is the saved file');
check($last['start'] instanceof DateTimeImmutable && $last['end'] instanceof DateTimeImmutable && $last['end'] >= $last['start'], 'start and end times');
check(str_contains((string) $last['disposition'], 'attachment'), 'getContentDisposition: ' . $last['disposition']);
same($d->before[0][4], $last['id'], 'the id is the same in every call');
check(count($d->updates) >= 1 && $d->updates[0]['percent'] <= 100, 'there was at least one update');

// ---- a download that canDownload refuses
$d->mode = 'refuse';
$before = count($d->before);
$browser->getMainFrame()->loadURL("$base/download?size=1000");
pump(1.5);
same($before, count($d->before), 'when canDownload says false there is no onBeforeDownload');
same("$base/download?size=1000", end($d->asked)[0], 'but it was asked');

// ---- a download canceled from onDownloadUpdated
$d->mode = 'cancel';
$d->updates = [];
$d->target = "$dir/canceled.bin";
$browser->getMainFrame()->loadURL("$base/download?size=3000000");
wait_until(fn() => ($d->last()['canceled'] ?? false), 'the download was canceled', 30000);
$last = $d->last();
same([true, false, false], [$last['canceled'], $last['complete'], $last['inProgress']], 'DownloadItemCallback::cancel() cancels it');
check(!is_file("$dir/canceled.bin") || filesize("$dir/canceled.bin") < 3000000, 'and the file is not complete');

// ---- a second complete download after a canceled one
$d->mode = 'save';
$d->updates = [];
$d->target = "$dir/again.bin";
$browser->getMainFrame()->loadURL("$base/download?size=5000");
wait_until(fn() => ($d->last()['complete'] ?? false), 'the second download completed', 30000);
check(filesize("$dir/again.bin") === 5000, 'the next download works');
check($d->last()['id'] !== $d->before[0][4], 'and has an id of its own');

Cef::shutdown();
finish();
