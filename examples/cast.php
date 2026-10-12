<?php
// Cast and DIAL from PHP: media sources, and the devices (sinks) that CEF's media router finds.
//
//   php -d extension=build/dist/cefloom.so examples/cast.php              # what a media source looks like
//   php -d extension=build/dist/cefloom.so examples/cast.php --discover   # list the devices found in 5 seconds
//
// A media source names what is to be shown: "cast:<application id>" for a Cast receiver application,
// "cast-dial:<application name>" for a DIAL application such as YouTube.
//
// KNOWN PROBLEM (llm-wiki known-constraints K26): once the media router has been asked for devices (--discover),
// CEF 154 stops the process with a trap when it shuts down. The list is printed before that. Without --discover
// the program ends normally.

use Cefloom\{Cef, MediaObserver, MediaRouter, MediaRoute, Buffer};

/** Told by CEF about the devices and the routes. */
final class Devices extends MediaObserver
{
    private ?array $names = null;

    public function onSinks(array $sinks): void
    {
        $now = array_map(fn($sink) => $sink->getName() . ' (' . $sink->getId() . ')', $sinks);
        if ($now !== $this->names) {
            $this->names = $now;
            echo $now === [] ? "no devices\n" : "devices: " . implode(', ', $now) . "\n";
        }
    }

    public function onRoutes(array $routes): void { echo count($routes) . " route(s)\n"; }
    public function onRouteStateChanged(MediaRoute $route, int $state): void {}
    public function onRouteMessageReceived(MediaRoute $route, Buffer $message): void {}
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::initialize();
$router = MediaRouter::getGlobalMediaRouter(null);

foreach (['cast:CC1AD845', 'cast-dial:YouTube', 'https://example.com'] as $urn) {
    $source = $router->getSource($urn);
    printf("%-22s %s\n", $urn, $source === null ? 'is not a media source' : ($source->isDialSource() ? 'DIAL source' : 'Cast source'));
}

if (in_array('--discover', $argv, true)) {
    $registration = $router->addObserver(new Devices());
    $router->notifyCurrentSinks();
    $router->notifyCurrentRoutes();
    $end = microtime(true) + 5;
    while (microtime(true) < $end) {
        Cef::doMessageLoopWork();
        Cef::wait(20);
    }
    echo "done (the process may stop with a CEF error when it shuts down: known problem K26)\n";
    $registration = null;
}

unset($router, $source);
Cef::shutdown();
