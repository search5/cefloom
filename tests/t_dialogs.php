<?php
// Dialogs and permission prompts: JSDialogHandler (alert, confirm and prompt, answered and refused), PermissionHandler (a geolocation
// prompt that is accepted and one that is denied), DialogHandler (an <input type=file> that is clicked). The page logs what it got.
require __DIR__ . '/support.php';

use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, DialogHandler, DisplayHandler, FileDialogCallback, JSDialogCallback, JSDialogHandler,
    LifeSpanHandler, LoadHandler, PermissionHandler, PermissionPromptCallback, WindowInfo};
use Cefloom\Types\{FileDialogMode, JSDialogType, PermissionRequestResult, PermissionRequestTypes};

final class Dialogs extends JSDialogHandler
{
    public array $seen = [];
    public array $script = [];       // answers in order: ['accept', 'text'] | ['cancel'] | ['ignore'] (not handled: CEF's default)
    public int $closed = 0;
    public int $resets = 0;
    public function onJSDialog(Browser $browser, string $originUrl, int $dialogType, string $messageText, string $defaultPromptText, JSDialogCallback $callback): array
    {
        $this->seen[] = [$dialogType, $messageText, $defaultPromptText, $originUrl];
        $answer = array_shift($this->script) ?? ['cancel'];
        if ($answer[0] === 'ignore') {
            return [false, false];                       // [handled, suppress]
        }
        if ($answer[0] === 'suppress') {
            return [false, true];
        }
        $callback->continue($answer[0] === 'accept', $answer[1] ?? '');
        return [true, false];
    }
    public function onResetDialogState(Browser $browser): void { $this->resets++; }
    public function onDialogClosed(Browser $browser): void { $this->closed++; }
}
final class Permissions extends PermissionHandler
{
    public array $prompts = [];
    public array $dismissed = [];
    public int $answer = PermissionRequestResult::ACCEPT;
    public function onShowPermissionPrompt(Browser $browser, int $promptId, string $requestingOrigin, int $requestedPermissions, PermissionPromptCallback $callback): bool
    {
        $this->prompts[] = [$requestingOrigin, $requestedPermissions];
        $callback->continue($this->answer);
        return true;
    }
    public function onDismissPermissionPrompt(Browser $browser, int $promptId, int $result): void { $this->dismissed[] = [$promptId, $result]; }
}
final class Files extends DialogHandler
{
    public array $seen = [];
    public ?string $pick = null;
    public function onFileDialog(Browser $browser, int $mode, string $title, string $defaultFilePath, array $acceptFilters, array $acceptExtensions, array $acceptDescriptions, FileDialogCallback $callback): bool
    {
        $this->seen[] = [$mode, $title, $defaultFilePath, $acceptFilters, $acceptExtensions];
        if ($this->pick === null) {
            $callback->cancel();
        } else {
            $callback->continue([$this->pick]);
        }
        return true;
    }
}
final class Console extends DisplayHandler
{
    public array $lines = [];
    public function onConsoleMessage($browser, $level, $message, $source, $line): bool { $this->lines[] = $message; return false; }
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
    public Dialogs $dialogs;
    public Permissions $permissions;
    public Files $files;
    public Console $console;
    public Life $life;
    public Load $load;
    public function __construct()
    {
        $this->dialogs = new Dialogs(); $this->permissions = new Permissions(); $this->files = new Files();
        $this->console = new Console(); $this->life = new Life(); $this->load = new Load();
    }
    public function getJSDialogHandler(): ?JSDialogHandler { return $this->dialogs; }
    public function getPermissionHandler(): ?PermissionHandler { return $this->permissions; }
    public function getDialogHandler(): ?DialogHandler { return $this->files; }
    public function getDisplayHandler(): ?DisplayHandler { return $this->console; }
    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
    /** The lines the page logged that start with $prefix. */
    public function logged(string $prefix): array
    {
        $found = [];
        foreach ($this->console->lines as $l) {
            if (str_starts_with($l, $prefix)) { $found[] = substr($l, strlen($prefix)); }
        }
        return $found;
    }
}

$base = start_http_server();
start_cef();
$app = new App();
$info = new WindowInfo();
$info->bounds->width = 400;
$info->bounds->height = 300;
$fileInput = '<input type="file" id="f" style="display:block;width:900px;height:800px" onchange="console.log(\'FILE:\' + this.files[0].name)">';
BrowserHost::createBrowser($info, $app, "$base/page?title=dialogs&body=" . rawurlencode($fileInput), new BrowserSettings(), null, null);
wait_until(fn() => $app->life->browser !== null && $app->load->ends > 0, 'the page');
$frame = $app->life->browser->getMainFrame();
// the script runs after a short delay: a dialog blocks the script, and the page's answer is logged when it comes
function js(Frame|\Cefloom\Frame $frame, string $code): void { $frame->executeJavaScript("setTimeout(() => { $code }, 50);", '', 0); }

// ---- alert, confirm, prompt: answered
$d = $app->dialogs;
$d->script = [['accept'], ['accept'], ['cancel'], ['accept', 'typed text'], ['cancel']];
js($frame, "alert('hello'); console.log('R:alert done'); console.log('R:confirm ' + confirm('sure?')); console.log('R:confirm ' + confirm('really?')); console.log('R:prompt ' + prompt('name?', 'default')); console.log('R:prompt ' + prompt('again?', 'dflt'));");
wait_until(fn() => count($app->logged('R:')) === 5, 'the dialogs were answered', 20000);
same(['alert done', 'confirm true', 'confirm false', 'prompt typed text', 'prompt null'], $app->logged('R:'),
    'alert returns when the handler continues; confirm gives true when accepted and false when canceled; prompt gives the text passed, and null when canceled');
same([JSDialogType::ALERT, 'hello'], [$d->seen[0][0], $d->seen[0][1]], 'onJSDialog gets the type and the message of an alert');
same([JSDialogType::CONFIRM, 'sure?'], [$d->seen[1][0], $d->seen[1][1]], 'of a confirm');
same([JSDialogType::PROMPT, 'name?', 'default'], [$d->seen[3][0], $d->seen[3][1], $d->seen[3][2]], 'and of a prompt, with its default text');
check(str_starts_with($d->seen[0][3], $base), 'and the origin URL');
check($d->closed >= 1, 'onDialogClosed was called (' . $d->closed . ')');

// ---- a dialog the handler leaves to CEF is not shown here (no window to show it in): the page goes on
$d->script = [['suppress']];
$before = count($d->seen);
js($frame, "alert('suppressed'); console.log('R2:after suppressed alert');");
wait_until(fn() => $app->logged('R2:') !== [], 'the page went on after a suppressed alert', 20000);
same(['after suppressed alert'], $app->logged('R2:'), 'suppress = true skips the dialog');

// ---- permission prompts
$p = $app->permissions;
$p->answer = PermissionRequestResult::ACCEPT;
js($frame, "navigator.geolocation.getCurrentPosition(() => console.log('G:position'), e => console.log('G:error ' + e.code));");
wait_until(fn() => $p->prompts !== [] && $app->logged('G:') !== [], 'a geolocation prompt', 20000);
same(PermissionRequestTypes::GEOLOCATION, $p->prompts[0][1] ?? null, 'onShowPermissionPrompt: a geolocation request');
check(str_starts_with($p->prompts[0][0] ?? '', $base), 'from the origin of the page: ' . ($p->prompts[0][0] ?? ''));
check($app->logged('G:') !== [], 'and the page got an answer (position or a position error, since there is no location service here): ' . json_encode($app->logged('G:')));
// (an accepted permission is remembered for its origin: the denial is asked of another origin, localhost)
$p->answer = PermissionRequestResult::DENY;
$app->console->lines = [];
$before = $app->load->ends;
$frame->loadURL(str_replace('127.0.0.1', 'localhost', $base) . '/page?title=denied');
wait_until(fn() => $app->load->ends > $before, 'the localhost page');
$frame = $app->life->browser->getMainFrame();
js($frame, "navigator.geolocation.getCurrentPosition(() => console.log('G:position'), e => console.log('G:error ' + e.code));");
wait_until(fn() => $app->logged('G:') !== [], 'the denied geolocation', 20000);
same(['error 1'], $app->logged('G:'), 'a denied prompt gives PERMISSION_DENIED (code 1) to the page');
check(count($p->prompts) === 2 && str_contains($p->prompts[1][0], 'localhost'), 'and it was asked for the other origin');

// ---- the file dialog (a click on an <input type=file>)
if (can_make_input()) {
    $before = $app->load->ends;
    $app->life->browser->getMainFrame()->loadURL("$base/page?title=dialogs&body=" . rawurlencode($fileInput));
    wait_until(fn() => $app->load->ends > $before, 'the file page');
    pump(0.5);
    $tmp = tmpdir('cefloom-dialogs');
    file_put_contents("$tmp/chosen.txt", 'x');
    $app->files->pick = "$tmp/chosen.txt";
    xdo('mousemove', '290', '390');
    pump(0.3);
    xdo('mousemove', '300', '400');
    pump(0.3);
    xdo('click', '1');
    wait_until(fn() => $app->files->seen !== [], 'the file dialog', 8000);
    same(FileDialogMode::OPEN, $app->files->seen[0][0] ?? null, 'onFileDialog: an open dialog');
    wait_until(fn() => $app->logged('FILE:') !== [], 'the page got the file', 8000);
    same(['chosen.txt'], $app->logged('FILE:'), 'a path given to the callback is the file the page gets');
} else {
    echo "SKIP file dialog: no xdotool or display\n";
}

Cef::shutdown();
finish();
