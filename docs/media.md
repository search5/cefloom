# Sound, microphone and camera

Two separate jobs: receiving the sound a page plays, and deciding whether a page may use the microphone or the camera.

## The sound of a page

An `AudioHandler` ([Handlers](handlers.md)) receives what the page plays as PCM packets, and the browser can be muted. The `Client` returns the handler from `getAudioHandler()`.

* **Alloy style only.** The default (Chrome) runtime style never asks for the handler. Make the browser with `$windowInfo->runtimeStyle = 2`. The handler is asked when the page makes sound.
* **Autoplay.** The page of the example starts its tone without a click, so the program adds `Cef::addSwitch('autoplay-policy', 'no-user-gesture-required')` (as `tests/t_audio.php` does). Running without the switch was not tried.
* **No sound device.** `Cef::addSwitch('disable-audio-output')` makes CEF discard the output. The streams still run. Playing to a real sound device was not tried.

`docs/recipes/audio.php` is a complete program: a page plays a tone with the Web Audio API, and the program counts the packets, looks at them, mutes the browser and closes it. It was run under a virtual X server three times in a row (`xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/audio.php`) and printed each time:

```
started: channels=2 rate=48000 frames=480
packets >= 20: true
layout: 2 buffers of 1920 bytes
tone: true
muted: true
packets after mute: true
tone after mute: true
stopped
error: Socket closed unexpectedly
```

The handler in it:

```php
final class Sound extends AudioHandler
{
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
        foreach (unpack('g*', $data[0]->read(0, $data[0]->getSize())) as $sample) {
            $this->peak = max($this->peak, abs($sample));
        }
    }
    // onAudioStreamStopped() and onAudioStreamError() as well
}
```

* **The stream.** `getAudioParameters()` is asked before the stream starts. `onAudioStreamStarted()` comes once with the parameters given and the number of channels, `onAudioStreamPacket()` for each packet, and `onAudioStreamStopped()` when the browser closes. The program above got 2 buffers of 1920 bytes in a packet, which is 480 frames of 4 bytes for each of the 2 channels, and the largest sample was close to 1.0 for the tone of the page.
* **Threads.** CEF makes the stream callbacks on its audio threads. cefloom copies the packets and runs your methods on the PHP thread a little later; when PHP does not keep up, packets are dropped ([Handlers](handlers.md)). Do not rely on getting every packet.
* **Errors.** `onAudioStreamError($browser, $message)` is told of an error, and the CEF header says the stream stops at once. In all three runs above the closing of the browser ended with `Socket closed unexpectedly` here, after `onAudioStreamStopped()`.
* **Muting.** `$browser->getHost()->setAudioMuted(true)` mutes the browser, and `isAudioMuted()` reads the state (`tests/t_browser_host.php`). In the program above `isAudioMuted()` became `true` after the call, and the packets went on arriving with the tone in them. So the packets are taken before the mute. Whether the muted browser is silent on a real sound device was not tried, since the runs had none.
* **Playing the packets yourself.** Not tried: the program only inspects the samples. Whether CEF also plays the sound to the device while an `AudioHandler` receives it was not tried either.

## Microphone and camera

When a page calls `navigator.mediaDevices.getUserMedia()`, CEF asks the `PermissionHandler` that the `Client` returns from `getPermissionHandler()`:

```php
public function onRequestMediaAccessPermission(Browser $browser, Frame $frame, string $requestingOrigin, int $requestedPermissions, MediaAccessCallback $callback): bool
```

Return `true` and answer through `$callback`, now or later. Return `false` to leave the request to CEF. The answers are `$callback->continue($permissions)` and `$callback->cancel()`.

`$requestedPermissions` is a combination of `MediaAccessPermissionTypes`: `DEVICE_AUDIO_CAPTURE` (1), `DEVICE_VIDEO_CAPTURE` (2), `DESKTOP_AUDIO_CAPTURE` (4) and `DESKTOP_VIDEO_CAPTURE` (8). For a request from `getUserMedia()` the permissions given to `continue()` must be the ones asked for (CEF header).

`docs/recipes/media.php` is a complete program. The page asks for the microphone, then for the camera; the policy allows the first and refuses the second. It was run three times in a row (`xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/media.php`) and printed each time:

```
request: app://test/ microphone
access: video=false audio=true
page: audio ok, tracks=audio
request: app://test/ camera
page: video NotAllowedError
page: done
```

The policy:

```php
public function onRequestMediaAccessPermission(Browser $browser, Frame $frame, string $requestingOrigin, int $requestedPermissions, MediaAccessCallback $callback): bool
{
    $camera = ($requestedPermissions & MediaAccessPermissionTypes::DEVICE_VIDEO_CAPTURE) !== 0;
    if ($requestingOrigin === 'app://test/' && !$camera) {
        $callback->continue($requestedPermissions);     // for getUserMedia() the answer must be what was asked
    } else {
        $callback->cancel();
    }
    return true;
}
```

* **The origin** came as `app://test/` with the slash at the end, from a page that a custom scheme served ([Handlers](handlers.md#custom-schemes-and-resources)). `getUserMedia()` needs a secure origin, so the scheme was registered as `SchemeOptions::SECURE`; other origins were not tried.
* **Fake devices.** The program adds `Cef::addSwitch('use-fake-device-for-media-stream')`, so it runs without a microphone or a camera. Without that switch, on a machine that has a microphone, the page got an audio track from that device. A machine with no device at all, and a real camera, were not tried.
* **Refused** with `cancel()`: the promise of the page was rejected with `NotAllowedError`.
* **Later.** Answering from a task after 1.5 seconds (`Cef::postDelayedTask()`) instead of inside the method worked for both requests: the page got its tracks after the delay. The method returned `true` without calling the callback.
* **`onMediaAccessChange()`.** A `DisplayHandler` is told when the access of the browser to a source changes: `onMediaAccessChange($browser, $hasVideoAccess, $hasAudioAccess)`. The program printed `video=false audio=true` after the microphone was allowed. With the camera also allowed (in the run that answered later) it was `video=true audio=true`. What it reports after the tracks are stopped was not tried.

### Without a decision

What happens when `onRequestMediaAccessPermission()` returns `false` (or there is no `PermissionHandler`) depends on the runtime style:

| Style | Result |
| --- | --- |
| Alloy (`runtimeStyle = 2`) | The request is denied: both requests got `NotAllowedError`. The CEF header says the same. |
| Chrome (the default) | CEF shows Chrome's permission request UI (CEF header). In a run on a virtual display the promise of the page was still pending after 15 seconds. How Chrome's prompt looks and whether it can be answered there were not tried. |

So a program that shows no window of its own should answer in the handler.

The switches `use-fake-ui-for-media-stream` and `enable-media-stream` grant the request without the handler. In one run with `use-fake-ui-for-media-stream` the handler was not called and the page got both tracks. The CEF header says the same of `enable-media-stream` ("will not be called"); that switch was not tried.

### Other permissions

Requests that are not microphone or camera access (geolocation and others) go to `onShowPermissionPrompt()` with the `PermissionRequestTypes` constants and are answered with `PermissionPromptCallback::continue()` and a `PermissionRequestResult` (`ACCEPT`, `DENY`, `DISMISS`, `IGNORE`). `tests/t_dialogs.php` accepts and denies a geolocation prompt. The constants `PermissionRequestTypes::MIC_STREAM` and `CAMERA_STREAM` exist; whether a `getUserMedia()` call also reaches `onShowPermissionPrompt()` was not tried (in the runs above only `onRequestMediaAccessPermission()` was asked).

### Known limits

* The page gets sound and video from the system devices. Supplying the program's own sound or video (a file, a virtual device) in place of a device was not tried.
* A microphone that is muted in the system was not tried.
* Screen capture (`DESKTOP_AUDIO_CAPTURE`, `DESKTOP_VIDEO_CAPTURE`) was not tried.
* [Cast](cast.md) is a different feature: the media router, which finds Cast and DIAL devices on the network.
