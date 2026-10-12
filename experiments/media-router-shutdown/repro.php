<?php
// Reproduces a crash of CEF 154 at Cef::shutdown() once the media router manager of the browser context exists
// (it is made by MediaRouter::addObserver() or notifyCurrentSinks()). The observer works: it receives sinks and routes.
// At CefShutdown, CefBrowserContext::Shutdown() resets the manager and the destructor of
// media_router::MediaRoutesObserver traps (SIGTRAP). Releasing the registration first does not help.
//
//   xvfb-run -a php -d extension=build/dist/cefloom.so experiments/media-router-shutdown/repro.php keep
//   variants: keep | release | router_too | quiet   (all four trap; without addObserver the shutdown is clean)
require __DIR__ . '/../../tests/support.php';
use Cefloom\{Cef, MediaObserver, MediaRouter};
final class Obs extends MediaObserver {
    public int $sinks = 0; public int $routes = 0;
    public function onSinks(array $sinks): void { $this->sinks++; }
    public function onRoutes(array $routes): void { $this->routes++; }
    public function onRouteStateChanged(\Cefloom\MediaRoute $r, int $s): void {}
    public function onRouteMessageReceived(\Cefloom\MediaRoute $r, \Cefloom\Buffer $m): void {}
}
$variant = $argv[1];
start_cef();
$router = MediaRouter::getGlobalMediaRouter(null);
$obs = new Obs();
$reg = $router->addObserver($obs);
fwrite(STDERR, "registered\n");
if ($variant !== 'quiet') { $router->notifyCurrentSinks(); $router->notifyCurrentRoutes(); }
$pump = function (float $s) { $end = microtime(true) + $s; while (microtime(true) < $end) { Cef::doMessageLoopWork(); Cef::wait(5); } };
$pump(1.0);
fwrite(STDERR, "observer calls: sinks={$obs->sinks} routes={$obs->routes}\n");
if ($variant === 'release') { $reg = null; fwrite(STDERR, "registration released\n"); $pump(1.0); }
if ($variant === 'release_noparam') { $reg = null; }
if ($variant === 'router_too') { $reg = null; $router = null; $pump(1.0); }
fwrite(STDERR, "shutting down ($variant)\n");
Cef::shutdown();
fwrite(STDERR, "shut down\n");
echo "OK (0 checks)\n";
