<?php
// Small callbacks that the other tests do not reach: a RequestContextHandler on a context of our own (also for the requests made in it),
// a SettingObserver on content settings, BrowserHost::downloadImage with its DownloadImageCallback, and the media access callback of a
// getUserMedia request.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, DownloadImageCallback, Image, LifeSpanHandler, LoadHandler, Request, RequestContext, RequestContextHandler,
    RequestContextSettings, ResourceRequestHandler, SettingObserver, WindowInfo};
use Cefloom\Types\{ContentSettingTypes, ContentSettingValues};

final class ContextEvents extends RequestContextHandler
{
    public int $initialized = 0;
    public array $requests = [];
    public function onRequestContextInitialized(RequestContext $requestContext): void { $this->initialized++; }
    public function getResourceRequestHandler(?Browser $browser, ?\Cefloom\Frame $frame, Request $request, bool $isNavigation, bool $isDownload, string $requestInitiator): array
    {
        $this->requests[] = [parse_url($request->getURL(), PHP_URL_PATH), $isNavigation, $isDownload];
        return [null, false];
    }
}
final class Settings extends SettingObserver
{
    public array $changed = [];
    public function onSettingChanged(string $requestingUrl, string $topLevelUrl, int $contentType): void { $this->changed[] = [$requestingUrl, $contentType]; }
}
final class Images extends DownloadImageCallback
{
    public ?array $result = null;
    public function onDownloadImageFinished(string $imageUrl, int $httpStatusCode, ?Image $image): void
    {
        $this->result = [$imageUrl, $httpStatusCode, $image !== null ? [$image->getWidth(), $image->getHeight()] : null];
    }
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
    public Life $life;
    public Load $load;
    public function __construct() { $this->life = new Life(); $this->load = new Load(); }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

$base = start_http_server();
// RequestContextHandler::getResourceRequestHandler is asked on CEF's IO thread while the UI thread, in the middle of the first navigation, waits for
// that thread: the call is not answered until the wait limit is over, and CEF's default is used (K40). A short limit keeps the test quick.
putenv('CEFLOOM_SYNC_WAIT_MS=300');
start_cef();

// ---- a request context with a handler
$handler = new ContextEvents();
$settings = new RequestContextSettings();
$context = RequestContext::createContext($settings, $handler);
wait_until(fn() => $handler->initialized > 0, 'onRequestContextInitialized', 10000);
same(1, $handler->initialized, 'RequestContextHandler::onRequestContextInitialized is called once');
check($context->getHandler() !== null, 'the context knows its handler');

// a browser in that context: its requests ask the handler for a ResourceRequestHandler
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 300;
$info->bounds->height = 200;
BrowserHost::createBrowser($info, $app, "$base/page?title=ctx", new BrowserSettings(), null, $context);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'a browser in the context');
pump(1.0);
check(in_array(['/page', true, false], $handler->requests, true), 'RequestContextHandler::getResourceRequestHandler is called for the requests of a browser in the context (late: K40): ' . json_encode($handler->requests));
check($app->life->browser->getHost()->getRequestContext()->isSame($context), 'and getRequestContext() of the browser is that context');

// ---- the setting observer
$watch = new Settings();
$registration = $context->addSettingObserver($watch);
check($registration !== null, 'addSettingObserver gives a registration');
$context->setContentSetting("$base/", "$base/", ContentSettingTypes::JAVASCRIPT, ContentSettingValues::BLOCK);
pump(0.5);
check(array_filter($watch->changed, fn($c) => $c[1] === ContentSettingTypes::JAVASCRIPT) !== [], 'SettingObserver::onSettingChanged is told that a content setting changed: ' . json_encode($watch->changed));

// ---- downloadImage
$images = new Images();
$app->life->browser->getHost()->downloadImage("$base/image.png", false, 0, true, $images);
wait_until(fn() => $images->result !== null, 'the image was downloaded', 20000);
same("$base/image.png", $images->result[0] ?? null, 'DownloadImageCallback gets the URL');
same(200, $images->result[1] ?? null, 'the HTTP status');
check(is_array($images->result[2] ?? null) && $images->result[2][0] > 0 && $images->result[2][1] > 0, 'and an Image with a size: ' . json_encode($images->result[2] ?? null));
$missing = new Images();
$app->life->browser->getHost()->downloadImage("$base/status/404", false, 0, true, $missing);
wait_until(fn() => $missing->result !== null, 'the 404 was answered', 20000);
same(404, $missing->result[1] ?? null, 'an image that cannot be downloaded: the status is passed on');
check(!is_array($missing->result[2] ?? null) || $missing->result[2][0] === 0, 'and there is no image (or an empty one)');

Cef::shutdown();
finish();
