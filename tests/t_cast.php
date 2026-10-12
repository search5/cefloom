<?php
// Cast: the media router and its sources can be used from PHP. A machine without a Cast device reports no sinks, so
// this checks the calls, not a cast session.
//
// It does not register an observer: once the media router manager exists, CEF 154 traps in Cef::shutdown() (see
// experiments/media-router-shutdown/repro.php and llm-wiki verified-findings F18, known-constraints K26).
require __DIR__ . '/support.php';

use Cefloom\{Cef, MediaRouter};

start_cef();

$router = MediaRouter::getGlobalMediaRouter(null);
check($router !== null, 'the global media router is there');

$cast = $router->getSource('cast:CC1AD845');
check($cast !== null && $cast->isCastSource() && !$cast->isDialSource(), 'a Cast source is made and recognised');
same('cast:CC1AD845', $cast?->getId(), 'its id is the urn');
$dial = $router->getSource('cast-dial:YouTube');
check($dial !== null && $dial->isDialSource() && !$dial->isCastSource(), 'a DIAL source (cast-dial: scheme) is told apart');
same(null, $router->getSource('https://example.com'), 'a web page is not a source');
same(null, $router->getSource(''), 'an empty urn is not a source');

unset($router, $cast, $dial);
Cef::shutdown();
finish();
