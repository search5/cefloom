# Cast

CEF has a **media router** that finds Cast and DIAL devices on the network and starts sessions on them. `examples/cast.php` shows the sources, and with `--discover` the devices:

```sh
php -d extension=build/dist/cefloom.so examples/cast.php
php -d extension=build/dist/cefloom.so examples/cast.php --discover
```

```php
use Cefloom\{MediaObserver, MediaRouter};

$router = MediaRouter::getGlobalMediaRouter(null);

$cast = $router->getSource('cast:CC1AD845');          // a Cast receiver application: "cast:<application id>"
$dial = $router->getSource('cast-dial:YouTube');      // a DIAL application:           "cast-dial:<application name>"
$cast->isCastSource();   // true
$dial->isDialSource();   // true
$router->getSource('https://example.com');            // null: a web page is not a source

final class Devices extends MediaObserver
{
    public function onSinks(array $sinks): void { foreach ($sinks as $sink) echo $sink->getName(), "\n"; }
    public function onRoutes(array $routes): void {}
    public function onRouteStateChanged(\Cefloom\MediaRoute $route, int $state): void {}
    public function onRouteMessageReceived(\Cefloom\MediaRoute $route, \Cefloom\Buffer $message): void {}
}
$registration = $router->addObserver(new Devices());   // keep it while you want events
$router->notifyCurrentSinks();
```

`createRoute($source, $sink, $callback)` starts a session on a device. Devices are `MediaSink` objects (`getName()`, `isCastSink()`, `isDialSink()`, `isCompatibleWith($source)`).

> **Known problem.** Once the media router has been asked for devices (`addObserver()` or `notifyCurrentSinks()`), **CEF 154 stops the process with a trap in `Cef::shutdown()`**. The observer works and receives its events; the crash comes at the end. Releasing the registration does not avoid it. The cause is inside CEF (a destructor of the media router during shutdown) and is not solved. Until it is, use the media router in a program that may end abruptly, or only read sources (which is safe). A reproduction is in `experiments/media-router-shutdown/`.
>
> No Cast device was available when this was tried, so the device list and sessions were not exercised; only the calls, the sources and the observer's events (with no devices) were.
