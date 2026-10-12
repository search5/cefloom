<?php
// xfail: once a MediaObserver is registered, Cef::shutdown() ends in SIGTRAP (CEF 154, known-constraints K26, verified-findings F18)
// What should work: register an observer, ask for the current sinks and routes, get the callbacks (a machine without a Cast device has no sinks:
// the lists are empty), drop the registration, and shut down cleanly. This test fails (xfail) until the trap in shutdown is understood.
// It then reports "unexpectedly passed", and the test is to become an ordinary one.
require __DIR__ . '/support.php';

use Cefloom\{Cef, MediaObserver, MediaRouter};

final class Watch extends MediaObserver
{
    public array $sinks = [];
    public array $routes = [];
    public function onSinks(array $sinks): void { $this->sinks[] = count($sinks); }
    public function onRoutes(array $routes): void { $this->routes[] = count($routes); }
    public function onRouteStateChanged(\Cefloom\MediaRoute $route, int $state): void {}
    public function onRouteMessageReceived(\Cefloom\MediaRoute $route, \Cefloom\Buffer $message): void {}
}

start_cef();
$router = MediaRouter::getGlobalMediaRouter(null);
$watch = new Watch();
$registration = $router->addObserver($watch);
check($registration !== null, 'addObserver gives a registration');
$router->notifyCurrentSinks();
$router->notifyCurrentRoutes();
wait_until(fn() => $watch->sinks !== [] && $watch->routes !== [], 'the observer was told the current sinks and routes', 10000);
same([[0], [0]], [$watch->sinks, $watch->routes], 'with no Cast device there are no sinks and no routes');
unset($registration, $router);
fwrite(STDOUT, "before shutdown\n");
Cef::shutdown();
check(true, 'shutdown returned');
finish();
