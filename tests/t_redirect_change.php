<?php
// xfail: the URL that onResourceRedirect returns is not followed (CEF's HandleRedirect reads it; here the redirect goes on to the old target)
// A handler may change where a redirect goes by returning another URL from onResourceRedirect(). The handler is called with the
// right arguments and returns the new URL, but the load ends at the URL of the server's redirect. Known problem K30
// (llm-wiki); this test fails until that is understood, and then tells that it passes.
require __DIR__ . '/support.php';

use Cefloom\{BrowserHost, BrowserSettings, Cef, Client, LoadHandler, RequestHandler, ResourceRequestHandler, WindowInfo};

$base = start_http_server();

final class Redirects extends ResourceRequestHandler
{
    public array $seen = [];
    public function __construct(private string $base) {}
    public function onResourceRedirect($browser, $frame, $request, $response, $newUrl): string
    {
        $this->seen[] = $newUrl;
        return str_contains($newUrl, '/to-be-changed') ? $this->base . '/hello?changed=1' : $newUrl;
    }
}
final class Req extends RequestHandler
{
    public Redirects $redirects;
    public function __construct(string $base) { $this->redirects = new Redirects($base); }
    public function getResourceRequestHandler($browser, $frame, $request, $isNavigation, $isDownload, $requestInitiator): array { return [$this->redirects, false]; }
}
final class Load extends LoadHandler
{
    public array $urls = [];
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->urls[] = $frame->getURL(); } }
}
final class App extends Client
{
    public Load $load;
    public Req $req;
    public function __construct(string $base) { $this->load = new Load(); $this->req = new Req($base); }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getRequestHandler(): ?RequestHandler { return $this->req; }
}

start_cef();
$app = new App($base);
$info = new WindowInfo();
$info->bounds->width = 200;
$info->bounds->height = 150;
BrowserHost::createBrowser($info, $app, "$base/redirect?to=/to-be-changed&code=302", new BrowserSettings(), null, null);
wait_until(fn() => $app->load->urls !== [], 'the page ended loading');
check(count($app->req->redirects->seen) === 1 && str_ends_with($app->req->redirects->seen[0], '/to-be-changed'), 'onResourceRedirect is called with the target of the redirect');
same("$base/hello?changed=1", $app->load->urls[0] ?? null, 'the load ends at the URL that the handler returned');
Cef::shutdown();
finish();
