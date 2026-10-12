<?php
// The example of docs/context-menu.md: change the menu CEF builds, show it yourself, and run the command that was chosen.
// A right click is sent with sendMouseClickEvent(), so the program needs no input tool.
//
//   xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/context-menu.php

use Cefloom\{Browser, BrowserHost, BrowserSettings, Cef, Client, ContextMenuHandler, ContextMenuParams, Frame, LifeSpanHandler, LoadHandler};
use Cefloom\{MenuModel, MouseEvent, RunContextMenuCallback, Task, WindowInfo};
use Cefloom\Types\{MenuId, MenuItemType, MouseButtonType, ThreadId};

const SHOW_ADDRESS = MenuId::USER_FIRST;        // command ids of the application lie between USER_FIRST and USER_LAST
const LAST_DEFAULT = 50162;                     // the last item of the menu CEF builds for a link; read with getCommandIdAt()

final class Menus extends ContextMenuHandler
{
    public ?string $address = null;

    // CEF has built the default menu in $model: change it. Do not keep $params or $model after this method.
    public function onBeforeContextMenu(Browser $browser, Frame $frame, ContextMenuParams $params, MenuModel $model): void
    {
        $this->address = $params->getLinkUrl() ?: $params->getPageUrl();
        echo 'link: ', $params->getLinkUrl(), "\n";
        echo 'editable: ', var_export($params->isEditable(), true), "\n";
        echo 'default items: ', $model->getCount(), "\n";
        echo 'removed: ', var_export($model->remove(LAST_DEFAULT), true), "\n";    // take one standard item away
        $model->addSeparator();
        $model->addItem(SHOW_ADDRESS, 'Show address');                        // and add one of ours
        echo 'items now: ', $model->getCount(), "\n";
    }

    // Return true to show the menu yourself. Here the "user" picks our item at once.
    public function runContextMenu(Browser $browser, Frame $frame, ContextMenuParams $params, MenuModel $model, RunContextMenuCallback $callback): bool
    {
        $last = $model->getCount() - 1;
        echo 'last item: ', $model->getLabelAt($last), ' (type ', $model->getTypeAt($last) === MenuItemType::COMMAND ? 'command' : 'other', ")\n";
        $callback->continue($model->getCommandIdAt($last), 0);
        return true;
    }

    // Return true when the application ran the command; false leaves a standard command to CEF.
    public function onContextMenuCommand(Browser $browser, Frame $frame, ContextMenuParams $params, int $commandId, int $eventFlags): bool
    {
        if ($commandId !== SHOW_ADDRESS) {
            return false;
        }
        echo 'address: ', $this->address, "\n";
        Cef::quit();
        return true;
    }

    public function onContextMenuDismissed(Browser $browser, Frame $frame): void
    {
        echo "dismissed\n";
    }
}

final class App extends Client
{
    public Menus $menus;
    public bool $clicked = false;
    private LifeSpanHandler $life;
    private LoadHandler $load;

    public function __construct()
    {
        $this->menus = new Menus();
        $app = $this;
        $this->life = new class extends LifeSpanHandler {};
        $this->load = new class($app) extends LoadHandler {
            public function __construct(private App $app) {}

            public function onLoadEnd($browser, $frame, $httpStatusCode): void
            {
                if ($frame->isMain() && !$this->app->clicked) {
                    $this->app->clicked = true;
                    $host = $browser->getHost();
                    // a short wait lets the page get its first frame before the click
                    Cef::postDelayedTask(ThreadId::UI, new class($host) extends Task {
                        public function __construct(private $host) {}

                        public function execute(): void
                        {
                            $at = new MouseEvent(60, 30);                     // on the link of the page
                            $this->host->sendMouseMoveEvent($at, false);
                            $this->host->sendMouseClickEvent($at, MouseButtonType::RIGHT, false, 1);
                            $this->host->sendMouseClickEvent($at, MouseButtonType::RIGHT, true, 1);
                        }
                    }, 1000);
                }
            }
        };
    }

    public function getContextMenuHandler(): ?ContextMenuHandler { return $this->menus; }

    public function getLifeSpanHandler(): ?LifeSpanHandler { return $this->life; }

    public function getLoadHandler(): ?LoadHandler { return $this->load; }
}

Cef::addSwitch('ozone-platform', 'x11');
Cef::addSwitch('disable-gpu');
Cef::initialize();

$info = new WindowInfo();
$info->bounds->width = 400;
$info->bounds->height = 300;
$page = 'data:text/html,<body style="margin:0"><a href="http://example.org/target" style="display:block;font-size:40px">a link</a></body>';
BrowserHost::createBrowser($info, new App(), $page, new BrowserSettings(), null, null);
Cef::run();
Cef::shutdown();
