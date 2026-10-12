<?php
// The example of docs/media.md: receive the sound of a page as PCM packets, then mute it.
// The page plays a tone with the Web Audio API; CEF is told to discard the output, so the program needs no sound device.
//
//   xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/audio.php

use Cefloom\{AudioHandler, AudioParameters, Browser, BrowserHost, BrowserSettings, Cef, Client, WindowInfo};

final class Sound extends AudioHandler
{
    public int $packets = 0;
    public float $peak = 0.0;
    public string $layout = '';
    public bool $stopped = false;

    // [true, parameters]: the object is a new one, so every value is given here
    public function getAudioParameters(Browser $browser): array
    {
        return [true, new AudioParameters(3, 48000, 480)];                // stereo, 48 kHz, 480 frames a buffer
    }

    public function onAudioStreamStarted(Browser $browser, AudioParameters $params, int $channels): void
    {
        echo "started: channels=$channels rate=$params->sampleRate frames=$params->framesPerBuffer\n";
    }

    // $data has one Buffer for each channel, holding 32-bit little-endian floats
    public function onAudioStreamPacket(Browser $browser, array $data, int $pts): void
    {
        $this->packets++;
        $this->layout = count($data) . ' buffers of ' . $data[0]->getSize() . ' bytes';
        foreach (unpack('g*', $data[0]->read(0, $data[0]->getSize())) as $sample) {
            $this->peak = max($this->peak, abs($sample));
        }
    }

    public function onAudioStreamStopped(Browser $browser): void
    {
        echo "stopped\n";
        $this->stopped = true;
    }

    public function onAudioStreamError(Browser $browser, string $message): void
    {
        echo "error: $message\n";
    }
}

final class App extends Client
{
    public Sound $sound;

    public function __construct()
    {
        $this->sound = new Sound();
    }

    public function getAudioHandler(): ?AudioHandler { return $this->sound; }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::addSwitch('disable-gpu');
Cef::addSwitch('autoplay-policy', 'no-user-gesture-required');     // let the page start the sound without a click
Cef::addSwitch('disable-audio-output');                            // no sound device is needed
Cef::initialize();

$app = new App();
$info = new WindowInfo();
$info->bounds->width = 320;
$info->bounds->height = 240;
$info->runtimeStyle = 2;                                           // Alloy: the Chrome style never asks for the audio handler
$page = 'data:text/html,<script>var c=new AudioContext();var o=c.createOscillator();o.connect(c.destination);o.start();c.resume()</script>';
$browser = BrowserHost::createBrowserSync($info, $app, $page, new BrowserSettings(), null, null);
$host = $browser->getHost();

function wait_for(callable $done, float $seconds = 20): void
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end && !$done()) {
        Cef::doMessageLoopWork();
        Cef::wait(5);
    }
}

$sound = $app->sound;
wait_for(fn() => $sound->packets >= 20);
echo 'packets >= 20: ', var_export($sound->packets >= 20, true), "\n";
echo "layout: $sound->layout\n";
echo 'tone: ', var_export($sound->peak > 0.1 && $sound->peak <= 1.01, true), "\n";

// the packets go on after the mute; what they hold is checked below
$host->setAudioMuted(true);
wait_for(fn() => $host->isAudioMuted(), 5);
echo 'muted: ', var_export($host->isAudioMuted(), true), "\n";
wait_for(fn() => false, 2);                  // packets made before the mute are still on their way
$sound->peak = 0.0;
$before = $sound->packets;
wait_for(fn() => $sound->packets >= $before + 20);
echo 'packets after mute: ', var_export($sound->packets >= $before + 20, true), "\n";
echo 'tone after mute: ', var_export($sound->peak > 0.1, true), "\n";

$host->closeBrowser(true);
wait_for(fn() => $sound->stopped, 10);
Cef::shutdown();
