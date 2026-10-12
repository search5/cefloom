<?php
// The AudioHandler: the audio of a page reaches PHP as PCM packets. It needs the Alloy runtime style (WindowInfo::runtimeStyle = 2: the
// Chrome style never asks for the handler, K37) and a page that is audible, and there is no sound device here, so CEF is told to
// discard the output (disable-audio-output): the streams still run. CEF calls the stream callbacks on its audio threads; the proxy hands
// them to the PHP thread without waiting there (K37: waiting for PHP from those threads never ended).
require __DIR__ . '/support.php';

use Cefloom\{AudioHandler, AudioParameters, Browser, BrowserHost, BrowserSettings, Cef, Client, LifeSpanHandler, LoadHandler, WindowInfo};

final class Audio extends AudioHandler
{
    public array $events = [];
    public ?AudioParameters $started = null;
    public int $channels = 0;
    public int $packets = 0;
    public int $bytesPerChannel = 0;
    public int $bufferCount = 0;
    public float $peak = 0.0;
    public function getAudioParameters(Browser $browser): array
    {
        $this->events[] = 'params';
        return [true, new AudioParameters(3, 48000, 480)];    // stereo, 48 kHz, 10 ms
    }
    public function onAudioStreamStarted(Browser $browser, AudioParameters $params, int $channels): void
    {
        $this->events[] = 'started';
        $this->started = $params;
        $this->channels = $channels;
    }
    public function onAudioStreamPacket(Browser $browser, array $data, int $pts): void
    {
        ++$this->packets;
        $this->bufferCount = count($data);
        $this->bytesPerChannel = $data[0]->getSize();
        foreach (unpack('g*', $data[0]->read(0, $data[0]->getSize())) as $v) {
            $this->peak = max($this->peak, abs($v));
        }
    }
    public function onAudioStreamStopped(Browser $browser): void { $this->events[] = 'stopped'; }
    public function onAudioStreamError(Browser $browser, string $message): void { $this->events[] = "error:$message"; }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
}
final class Load extends LoadHandler
{
    public int $ends = 0;
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->ends++; } }
}
final class App extends Client
{
    public Audio $audio;
    public Life $life;
    public Load $load;
    public function __construct() { $this->audio = new Audio(); $this->life = new Life(); $this->load = new Load(); }
    public function getAudioHandler(): ?AudioHandler { return $this->audio; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

Cef::addSwitch('autoplay-policy', 'no-user-gesture-required');
Cef::addSwitch('disable-audio-output');
start_cef();
$app = new App();
$audio = $app->audio;
$info = new WindowInfo();
$info->bounds->width = 320;
$info->bounds->height = 240;
$info->runtimeStyle = 2;
$page = 'data:text/html,<script>var c=new AudioContext();var o=c.createOscillator();o.connect(c.destination);o.start();c.resume()</script>';
BrowserHost::createBrowser($info, $app, $page, new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the page');
wait_until(fn() => $audio->packets >= 20, 'packets of audio arrive', 20000);

check(in_array('params', $audio->events, true), 'getAudioParameters is asked before the stream starts');
same(['started'], array_values(array_filter($audio->events, fn($e) => $e === 'started')), 'onAudioStreamStarted comes once');
same([3, 48000, 480], [$audio->started->channelLayout, $audio->started->sampleRate, $audio->started->framesPerBuffer], 'with the parameters that getAudioParameters gave');
same(2, $audio->channels, 'for two channels');
check($audio->packets >= 20, 'onAudioStreamPacket is called for each packet: ' . $audio->packets);
same(2, $audio->bufferCount, 'a packet has one buffer for each channel');
same(480 * 4, $audio->bytesPerChannel, 'of frames * 4 bytes (floats)');
check($audio->peak > 0.1 && $audio->peak <= 1.01, 'and the samples are the tone of the page: peak ' . round($audio->peak, 3));

if (getenv('CEFLOOM_TEST_EXTERNAL_PUMP')) {
    // K43: with the external message pump an Alloy style browser does not finish closing (no onBeforeClose), so no stop comes
    // either; Cef::shutdown() ends it.
    Cef::shutdown();
    finish();
}
$app->life->browser->getHost()->closeBrowser(true);
wait_until(fn() => in_array('stopped', $audio->events, true), 'the stream stops with the browser', 10000);
check(in_array('stopped', $audio->events, true), 'onAudioStreamStopped follows when the browser closes');
Cef::shutdown();
finish();
