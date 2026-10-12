<?php
// HTTPS with a certificate nobody trusts (a self-signed one made with openssl, served by a PHP child process): RequestHandler::onCertificateError
// with the SSLInfo and X509Certificate it gets, a page that loads after the handler continues, one that fails when it does not, the SSLStatus of the
// loaded page, a URLRequest that fails, and the client certificate request.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Callback, Cef, Client, LifeSpanHandler, LoadHandler, RequestHandler, SSLInfo, StringVisitor, WindowInfo, X509Certificate};
use Cefloom\Types\{CertStatus, ErrorCode, SSLVersion};

if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "SKIP: no openssl\n";
    exit(0);
}

final class Req extends RequestHandler
{
    public array $errors = [];
    public string $answer = 'continue';      // continue | refuse | decline (return false)
    public array $cert = [];
    public array $selected = [];
    public function onSelectClientCertificate(Browser $browser, bool $isProxy, string $host, int $port, array $certificates, \Cefloom\SelectClientCertificateCallback $callback): bool
    {
        $this->selected[] = [$isProxy, $host, $port, count($certificates)];
        $callback->select(null);          // no certificate: the connection goes on without one
        return true;
    }
    public function onCertificateError(Browser $browser, int $certError, string $requestUrl, SSLInfo $sslInfo, Callback $callback): bool
    {
        $c = $sslInfo->getX509Certificate();
        $this->errors[] = [$certError, $requestUrl, $sslInfo->getCertStatus()];
        if ($c !== null && !$this->cert) {
            $der = $c->getDEREncoded();
            $pem = $c->getPEMEncoded();
            $this->cert = [
                'subject' => $c->getSubject()?->getCommonName(), 'issuer' => $c->getIssuer()?->getCommonName(),
                'org' => $c->getSubject()?->getOrganizationNames(), 'country' => $c->getSubject()?->getCountryName(), 'display' => $c->getSubject()?->getDisplayName(),
                'serial' => $c->getSerialNumber()?->getSize(), 'start' => $c->getValidStart(), 'expiry' => $c->getValidExpiry(),
                'der' => $der ? $der->getSize() : 0, 'pem' => $pem ? $pem->getData(27, 0) : '', 'chain' => $c->getIssuerChainSize(),
                'derChain' => count($c->getDEREncodedIssuerChain()), 'pemChain' => count($c->getPEMEncodedIssuerChain()),
            ];
        }
        if ($this->answer === 'decline') {
            return false;
        }
        if ($this->answer === 'continue') {
            $callback->continue();
        } else {
            $callback->cancel();
        }
        return true;
    }
}
final class Load extends LoadHandler
{
    public int $ends = 0;
    public array $errors = [];
    public function onLoadEnd($browser, $frame, $httpStatusCode): void { if ($frame->isMain()) { $this->ends++; } }
    public function onLoadError($browser, $frame, $errorCode, $errorText, $failedUrl): void { if ($frame->isMain()) { $this->errors[] = [$errorCode, $failedUrl]; } }
}
final class Life extends LifeSpanHandler
{
    public $browser = null;
    public function onAfterCreated($browser): void { $this->browser = $browser; }
}
final class App extends Client
{
    public Req $req;
    public Load $load;
    public Life $life;
    public function __construct() { $this->req = new Req(); $this->load = new Load(); $this->life = new Life(); }
    public function getRequestHandler(): ?RequestHandler { return $this->req; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
}
final class Text extends StringVisitor
{
    public ?string $text = null;
    public function visit(string $string): void { $this->text = $string; }
}

$tls = start_https_server('cefloom-test');
start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 300;
$info->bounds->height = 200;
BrowserHost::createBrowser($info, $app, 'about:blank', new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the browser');
$browser = $app->life->browser;

// ---- the handler continues: the page loads
$app->req->answer = 'continue';
$app->load->errors = [];
$before = $app->load->ends;
$browser->getMainFrame()->loadURL($tls['url'] . '/');
wait_until(fn() => $app->load->ends > $before, 'the page loaded', 20000);
$text = new Text();
$browser->getMainFrame()->getText($text);
wait_until(fn() => $text->text !== null, 'text');
same('hello over tls', trim($text->text), 'callback->continue() lets the page load');
same([], $app->load->errors, 'with no load error');

// ---- the certificate that the handler is shown
$cert = $app->req->cert;
same(['cefloom-test', 'cefloom-test', ['Cefloom Tests'], 'KR'], [$cert['subject'] ?? null, $cert['issuer'] ?? null, $cert['org'] ?? null, $cert['country'] ?? null], 'X509Certificate: subject, issuer (the same: it is self-signed), organization and country');
check(($cert['display'] ?? '') !== '', 'and a display name: ' . ($cert['display'] ?? ''));
check(($cert['serial'] ?? 0) > 0, 'a serial number');
check(($cert['start'] ?? null) instanceof DateTimeImmutable && ($cert['expiry'] ?? null) instanceof DateTimeImmutable && $cert['expiry'] > $cert['start'], 'a validity period (DateTimeImmutable)');
check(abs($cert['expiry']->getTimestamp() - $cert['start']->getTimestamp() - 2 * 86400) < 120, 'of the two days that openssl was asked for');
check(($cert['der'] ?? 0) > 300 && ($cert['pem'] ?? '') === '-----BEGIN CERTIFICATE-----', 'DER and PEM encodings');
same([0, 0, 0], [$cert['chain'] ?? null, $cert['derChain'] ?? null, $cert['pemChain'] ?? null], 'the issuer chain (the certificates above the first) of a self-signed certificate is empty');
check(str_contains(file_get_contents($tls['cert']), '-----BEGIN CERTIFICATE-----'), 'and the PEM is the form of the certificate that the server has');

// ---- the SSL status of the page
$entry = $browser->getHost()->getVisibleNavigationEntry();
$status = $entry->getSSLStatus();
check($status !== null, 'the entry has an SSLStatus');
same(true, $status->isSecureConnection(), 'isSecureConnection (the connection is TLS)');
check(($status->getCertStatus() & CertStatus::AUTHORITY_INVALID) !== 0, 'getCertStatus has AUTHORITY_INVALID');
check(in_array($status->getSSLVersion(), [SSLVersion::TLS1_2, SSLVersion::TLS1_3], true), 'getSSLVersion is TLS 1.2 or 1.3: ' . $status->getSSLVersion());
check($status->getX509Certificate() !== null && $status->getX509Certificate()->getSubject()->getCommonName() === 'cefloom-test', 'and the certificate of the page');
same(true, Cef::isCertStatusError($status->getCertStatus()), 'isCertStatusError says that status is an error');

// (a decision is remembered for its origin, so each case has an origin of its own: 127.0.0.1 and localhost)
// ---- the handler declines (returns false): the page does not load
$app->req->answer = 'decline';
$before = $app->load->ends;
$other = str_replace('127.0.0.1', 'localhost', $tls['url']);
$browser->getMainFrame()->loadURL($other . '/');
wait_until(fn() => $app->load->errors !== [], 'a load error', 20000);
same([ErrorCode::CERT_AUTHORITY_INVALID, $other . '/'], $app->load->errors[0] ?? null, 'a certificate the handler does not accept: the load fails with CERT_AUTHORITY_INVALID');
same(ErrorCode::CERT_AUTHORITY_INVALID, end($app->req->errors)[0] ?? null, 'onCertificateError told that error');
same($other . '/', end($app->req->errors)[1] ?? null, 'and the URL');
check((end($app->req->errors)[2] ?? 0) & CertStatus::AUTHORITY_INVALID, 'and SSLInfo::getCertStatus has AUTHORITY_INVALID');

// ---- the handler refuses with cancel(): the load fails
$app->req->answer = 'refuse';
$app->load->errors = [];
$third = str_replace('127.0.0.1', 'localhost.', $tls['url']);
$browser->getMainFrame()->loadURL($third . '/refused');
wait_until(fn() => $app->load->errors !== [], 'a load error after cancel', 20000);
check(($app->load->errors[0][0] ?? 0) !== 0, 'callback->cancel() fails the load: ' . json_encode($app->load->errors));

// ---- a server that asks for a client certificate: the handler is asked to select one (there is none to offer)
$strict = start_https_server('cefloom-strict', true);
$app->req->answer = 'continue';
$app->load->errors = [];
$browser->getMainFrame()->loadURL($strict['url'] . '/who');
wait_until(fn() => $app->req->selected !== [], 'onSelectClientCertificate', 20000);
same([false, '127.0.0.1', $strict['port'], 0], $app->req->selected[0] ?? null, 'onSelectClientCertificate: not a proxy, the host and port, and no certificate in the list');
wait_until(fn() => $app->load->errors !== [], 'a load error', 20000);
check($app->load->errors !== [], 'select(null) offers no certificate, and the server refuses the connection: ' . json_encode($app->load->errors));

// ---- a URLRequest does not get onCertificateError of a browser: it fails
$f = fetch_url($tls['url'] . '/');
same([Cefloom\Types\URLRequestStatus::FAILED, ErrorCode::CERT_AUTHORITY_INVALID], [$f->status, $f->error], 'a URLRequest to the same server fails with CERT_AUTHORITY_INVALID');

Cef::shutdown();
finish();
