<?php
// The example of docs/media.md: decide which getUserMedia() requests of a page are allowed, and watch what the page then holds.
// The page asks for the microphone, then for the camera; the program allows the first and refuses the second.
// CEF is told to offer fake devices, so the program runs on a machine without a microphone or a camera.
//
//   xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/media.php

use Cefloom\{Browser, BrowserHost, BrowserSettings, Buffer, Callback, Cef, Client, DisplayHandler, Frame, MediaAccessCallback, PermissionHandler};
use Cefloom\{Request, ResourceHandler, ResourceReadCallback, Response, SchemeHandlerFactory, WindowInfo};
use Cefloom\Types\{MediaAccessPermissionTypes, SchemeOptions};

// getUserMedia() works only on a secure origin, so the page comes from a secure custom scheme
const PAGE = '<!doctype html><title>media</title><script>
async function ask(what) {
    try {
        const stream = await navigator.mediaDevices.getUserMedia(what);
        console.log("R:" + Object.keys(what)[0] + " ok, tracks=" + stream.getTracks().map(t => t.kind).join(","));
    } catch (e) {
        console.log("R:" + Object.keys(what)[0] + " " + e.name);
    }
}
(async () => { await ask({audio: true}); await ask({video: true}); console.log("R:done"); })();
</script>';

/** Answer every request of the scheme with one string. */
final class Doc extends ResourceHandler
{
    private int $position = 0;

    public function __construct(private string $body, private string $mime) {}

    public function open(Request $request, Callback $callback): array { return [true, true]; }

    public function getResponseHeaders(Response $response): array
    {
        $response->setStatus(200);
        $response->setMimeType($this->mime);
        return [strlen($this->body), ''];
    }

    public function read(Buffer $dataOut, ResourceReadCallback $callback): array
    {
        if ($this->position >= strlen($this->body)) {
            return [false, 0];
        }
        $written = $dataOut->write(substr($this->body, $this->position, $dataOut->getSize()));
        $this->position += $written;
        return [true, $written];
    }

    public function cancel(): void {}
}

final class Factory extends SchemeHandlerFactory
{
    public function create(?Browser $browser, ?Frame $frame, string $schemeName, Request $request): ?ResourceHandler
    {
        return new Doc(PAGE, 'text/html');
    }
}

final class Policy extends PermissionHandler
{
    // Return true and answer through $callback, now or later; return false to leave the request to CEF.
    public function onRequestMediaAccessPermission(Browser $browser, Frame $frame, string $requestingOrigin, int $requestedPermissions, MediaAccessCallback $callback): bool
    {
        $camera = ($requestedPermissions & MediaAccessPermissionTypes::DEVICE_VIDEO_CAPTURE) !== 0;
        echo "request: $requestingOrigin ", $camera ? 'camera' : 'microphone', "\n";
        if ($requestingOrigin === 'app://test/' && !$camera) {
            $callback->continue($requestedPermissions);     // for getUserMedia() the answer must be what was asked
        } else {
            $callback->cancel();
        }
        return true;
    }
}

final class Watch extends DisplayHandler
{
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool
    {
        if (str_starts_with($message, 'R:')) {
            echo 'page: ', substr($message, 2), "\n";
            if ($message === 'R:done') {
                Cef::quit();
            }
        }
        return false;
    }

    public function onMediaAccessChange(Browser $browser, bool $hasVideoAccess, bool $hasAudioAccess): void
    {
        echo 'access: video=', var_export($hasVideoAccess, true), ' audio=', var_export($hasAudioAccess, true), "\n";
    }
}

final class App extends Client
{
    private PermissionHandler $policy;
    private DisplayHandler $display;

    public function __construct()
    {
        $this->policy = new Policy();
        $this->display = new Watch();
    }

    public function getPermissionHandler(): ?PermissionHandler { return $this->policy; }

    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::addSwitch('disable-gpu');
Cef::addSwitch('use-fake-device-for-media-stream');
Cef::registerCustomScheme('app', SchemeOptions::STANDARD | SchemeOptions::SECURE | SchemeOptions::CORS_ENABLED);
Cef::initialize();
Cef::registerSchemeHandlerFactory('app', 'test', new Factory());

$info = new WindowInfo();
$info->bounds->width = 400;
$info->bounds->height = 300;
BrowserHost::createBrowser($info, new App(), 'app://test/index.html', new BrowserSettings(), null, null);
Cef::run();
Cef::shutdown();
