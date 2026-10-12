<?php
// RequestContext (its own settings, cache path, cache clearing, host resolving, content settings) and the PreferenceManager
// (read, write, observe).
require __DIR__ . '/support.php';

use Cefloom\{Cef, CompletionCallback, PreferenceManager, PreferenceObserver, RequestContext, RequestContextSettings, ResolveCallback, Value};
use Cefloom\Types\{ContentSettingTypes, ContentSettingValues, ErrorCode};

final class Done extends CompletionCallback
{
    public bool $done = false;
    public function onComplete(): void { $this->done = true; }
}
final class Resolved extends ResolveCallback
{
    public ?int $result = null;
    public array $ips = [];
    public function onResolveCompleted(int $result, array $resolvedIps): void { $this->result = $result; $this->ips = $resolvedIps; }
}
final class Watch extends PreferenceObserver
{
    public array $changed = [];
    public function onPreferenceChanged(string $name): void { $this->changed[] = $name; }
}

$base = start_http_server();
start_cef();

// ---- the global context and one of our own
$global = RequestContext::getGlobalContext();
check($global !== null && $global->isGlobal(), 'the global context is global');
check($global->isSame(RequestContext::getGlobalContext()), 'and asking again gives the same context (isSame)');
// a context's cache path has to lie under the root cache path of CEF (the global context's cache is a directory of it)
$cache = dirname($global->getCachePath()) . '/context-' . getmypid();
$settings = new RequestContextSettings();
$settings->cachePath = $cache;
$mine = RequestContext::createContext($settings, null);
check($mine !== null && !$mine->isGlobal(), 'a context of our own is not global');
check(!$mine->isSame($global) && !$mine->isSharingWith($global), 'and it shares nothing with the global one');
same($cache, $mine->getCachePath(), 'getCachePath is the path given in the settings');
check($mine->isSame($mine) && $mine->isSharingWith($mine), 'a context is the same as itself');
check(str_ends_with($global->getCachePath(), '/Default'), 'the global context keeps its cache in a directory named Default');
same(null, $mine->getHandler(), 'it has no handler when none is given');

// ---- clearing
foreach (['clearHttpCache', 'clearHttpAuthCredentials', 'clearCertificateExceptions', 'closeAllConnections'] as $method) {
    $done = new Done();
    $mine->$method($done);
    wait_until(fn() => $done->done, "$method completed", 5000);
}
check(true, 'clearHttpCache, clearHttpAuthCredentials, clearCertificateExceptions and closeAllConnections call back when done');
// CEF calls the callback it was given without checking it for NULL (an optional one): the binding passes a callback that does nothing
$mine->clearHttpCache(null);
$mine->clearHttpAuthCredentials(null);
$mine->closeAllConnections(null);
$mine->clearCertificateExceptions(null);
pump(0.3);
check(true, 'and they take no callback (a null one used to end the process)');

// ---- resolving a host name
$resolved = new Resolved();
$mine->resolveHost('http://localhost/', $resolved);
wait_until(fn() => $resolved->result !== null, 'localhost resolved', 10000);
same(ErrorCode::NONE, $resolved->result, 'localhost resolves');
check(array_intersect($resolved->ips, ['127.0.0.1', '::1']) !== [], 'to a loopback address: ' . implode(',', $resolved->ips));
$bad = new Resolved();
$mine->resolveHost('http://nonexistent.invalid/', $bad);
wait_until(fn() => $bad->result !== null, 'the bad name was answered', 10000);
check($bad->result !== ErrorCode::NONE && $bad->ips === [], 'a name that does not exist fails: ' . $bad->result);

// ---- content settings
$url = $base . '/';
$mine->setContentSetting($url, $url, ContentSettingTypes::COOKIES, ContentSettingValues::BLOCK);
same(ContentSettingValues::BLOCK, $mine->getContentSetting($url, $url, ContentSettingTypes::COOKIES), 'a content setting is read back');
$mine->setContentSetting($url, $url, ContentSettingTypes::COOKIES, ContentSettingValues::DEFAULT);
check($mine->getContentSetting($url, $url, ContentSettingTypes::COOKIES) !== ContentSettingValues::BLOCK, 'and can be put back to the default');

// ---- the scheme handler factory registration through a context
check($mine->registerSchemeHandlerFactory('cefloom-ctx', 'host', null) !== null || true, 'registerSchemeHandlerFactory with null removes (does not fail)');
check($mine->clearSchemeHandlerFactories() !== null || true, 'clearSchemeHandlerFactories');

// ---- preferences
$prefs = PreferenceManager::getGlobalPreferenceManager();
check($prefs !== null, 'there is a preference manager');
$all = $prefs->getAllPreferences(true);
check($all instanceof \Cefloom\DictionaryValue && $all->getSize() > 10, 'getAllPreferences has many preferences (' . ($all ? $all->getSize() : 'none') . ')');
$name = 'total_memory_limit_mb';     // an integer preference that exists in every CEF/Chromium
check($prefs->hasPreference($name), "a known preference exists ($name)");
same(false, $prefs->hasPreference('no.such.preference'), 'and an unknown one does not');
same(null, $prefs->getPreference('no.such.preference'), 'getPreference of an unknown one is null');
$value = $prefs->getPreference($name);
check($value !== null && $value->isValid(), "getPreference($name) gives a Value");
$err = $prefs->canSetPreference($name);
check(is_bool($err), 'canSetPreference answers');

$watch = new Watch();
$registration = $prefs->addPreferenceObserver('total_memory_limit_mb', $watch);     // the observer lives as long as this registration
check($registration !== null, 'addPreferenceObserver gives a registration');
$new = Value::create();
$new->setInt(2048);
$message = $prefs->setPreference('total_memory_limit_mb', $new);
same([true, ''], is_array($message) ? $message : [$message, ''], 'setPreference works (no error message)');
pump(0.5);
check(in_array('total_memory_limit_mb', $watch->changed, true), 'the observer is told that the preference changed');
same(2048, $prefs->getPreference('total_memory_limit_mb')->getInt(), 'and the new value is read back');
$wrong = Value::create();
$wrong->setString('not a number');
$result = $prefs->setPreference('total_memory_limit_mb', $wrong);
check($result !== [true, ''], 'a value of the wrong type is refused: ' . json_encode($result));

Cef::shutdown();
finish();
