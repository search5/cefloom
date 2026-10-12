<?php
// A window made by CEF's Views framework: a toolbar with Back, Forward and Reload buttons above a page.
//
//   php -d extension=build/dist/cefloom.so examples/views.php [url] [--close-after=SECONDS]
//   (on a machine without a display: xvfb-run -a php -d extension=... examples/views.php --close-after=3)
//
// Views draws the window, the buttons and the layout itself, so the program needs no toolkit. PHP only describes
// the views and answers the events of the delegates.

use Cefloom\{BoxLayoutSettings, BrowserSettings, BrowserView, BrowserViewDelegate, Button, ButtonDelegate, Cef, Client,
    DisplayHandler, LabelButton, LoadHandler, Panel, Rect, Window, WindowDelegate};

$url = 'data:text/html,' . rawurlencode('<title>Views</title><body style="font:20px sans-serif"><h1>cefloom Views</h1><p><a href="https://example.com">example.com</a></p></body>');
$closeAfter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--close-after=')) {
        $closeAfter = (float) substr($arg, strlen('--close-after='));
    } else {
        $url = $arg;
    }
}

/** What the toolbar buttons do. */
final class Toolbar extends ButtonDelegate
{
    public ?LabelButton $back = null;
    public ?LabelButton $forward = null;
    public ?LabelButton $reload = null;
    public ?BrowserView $browserView = null;

    public function onButtonPressed(Button $button): void
    {
        $browser = $this->browserView?->getBrowser();
        if ($browser === null) {
            return;
        }
        match (true) {
            $button->isSame($this->back) => $browser->goBack(),
            $button->isSame($this->forward) => $browser->goForward(),
            $button->isSame($this->reload) => $browser->reload(),
            default => null,
        };
    }
}

/** Keeps the window title and the buttons in step with the page. */
final class Page extends Client
{
    public ?Window $window = null;
    public ?Toolbar $toolbar = null;
    private DisplayHandler $display;
    private LoadHandler $load;

    public function __construct()
    {
        $page = $this;
        $this->display = new class($page) extends DisplayHandler {
            public function __construct(private Page $page) {}
            public function onTitleChange($browser, $title): void { $this->page->window?->setTitle($title); }
        };
        $this->load = new class($page) extends LoadHandler {
            public function __construct(private Page $page) {}
            public function onLoadingStateChange($browser, $isLoading, $canGoBack, $canGoForward): void
            {
                $this->page->toolbar?->back?->setEnabled($canGoBack);
                $this->page->toolbar?->forward?->setEnabled($canGoForward);
            }
        };
    }

    public function getDisplayHandler(): ?DisplayHandler { return $this->display; }
    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

/** The window: put the views in when it exists, and leave the program when it is gone. */
final class Frame extends WindowDelegate
{
    public function __construct(private Page $page, private Toolbar $toolbar, private BrowserView $browserView) {}

    public function onWindowCreated(Window $window): void
    {
        $this->page->window = $window;
        $window->setTitle('cefloom');

        $strip = Panel::createPanel(null);
        $strip->setToBoxLayout(new BoxLayoutSettings(horizontal: 1, betweenChildSpacing: 4));
        foreach (['back' => 'Back', 'forward' => 'Forward', 'reload' => 'Reload'] as $name => $label) {
            $this->toolbar->$name = LabelButton::createLabelButton($this->toolbar, $label);
            $strip->addChildView($this->toolbar->$name);
        }
        $this->toolbar->back->setEnabled(false);
        $this->toolbar->forward->setEnabled(false);

        $layout = $window->setToBoxLayout(new BoxLayoutSettings(horizontal: 0));   // vertical: toolbar, then page
        $window->addChildView($strip);
        $window->addChildView($this->browserView);
        $layout->setFlexForView($this->browserView, 1);                            // the page takes the rest
        $window->show();
        $this->browserView->requestFocus();
    }

    public function onWindowDestroyed(Window $window): void
    {
        $this->page->window = null;
        Cef::quit();
    }

    public function canClose(Window $window): bool { return true; }

    public function getInitialBounds(Window $window): Rect { return new Rect(100, 100, 900, 650); }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::initialize();

$page = new Page();
$toolbar = new Toolbar();
$page->toolbar = $toolbar;
$toolbar->browserView = BrowserView::createBrowserView($page, $url, new BrowserSettings(), null, null, null);
$window = Window::createTopLevelWindow(new Frame($page, $toolbar, $toolbar->browserView));

$deadline = $closeAfter === null ? null : microtime(true) + $closeAfter;
Cef::run(function () use (&$window, $deadline): void {
    if ($deadline !== null && $window !== null && microtime(true) >= $deadline) {
        $window->close();
        $window = null;
    }
});

$toolbar->browserView = null;
$toolbar->back = $toolbar->forward = $toolbar->reload = null;
unset($window, $toolbar, $page);
Cef::shutdown();
echo "closed\n";
