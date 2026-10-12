# Windows with Views

CEF can draw the window itself with its **Views** framework: top-level windows, panels, buttons, text fields, menus and layouts, all in Chromium's own toolkit. A program needs no GTK or Qt, and the same code is meant to work on every platform CEF supports (only Linux/X11 was tried). `examples/views.php` is a complete program: a toolbar with Back, Forward and Reload above a page.

```sh
php -d extension=build/dist/cefloom.so examples/views.php [url] [--close-after=SECONDS]
```

## The pieces

| Class | Role |
| --- | --- |
| `Window::createTopLevelWindow($delegate)` | makes the window; its `WindowDelegate` is told when it exists, closes and moves |
| `BrowserView::createBrowserView($client, $url, $settings, null, null, null)` | a view that shows a page (and owns its browser: `$view->getBrowser()`) |
| `Panel::createPanel(null)` | a container; `setToBoxLayout()` or `setToFillLayout()` arranges its children |
| `LabelButton::createLabelButton($buttonDelegate, 'text')`, `Textfield`, `MenuButton` | controls; their delegates (`ButtonDelegate`, ...) receive the clicks |
| `MenuModel::create($menuDelegate)` | a menu: `addItem()`, `addSeparator()`, `addCheckItem()`, and `Window::showMenu()` |
| `BoxLayoutSettings`, `BoxLayout::setFlexForView()` | rows and columns; a view with flex 1 takes the space that is left |

## The shape of a program

```php
final class Frame extends WindowDelegate
{
    public function __construct(private BrowserView $browserView) {}

    public function onWindowCreated(Window $window): void        // CEF has made the window: fill it
    {
        $layout = $window->setToBoxLayout(new BoxLayoutSettings(horizontal: 0));   // a column
        $window->addChildView($this->browserView);
        $layout->setFlexForView($this->browserView, 1);
        $window->show();
    }
    public function onWindowDestroyed(Window $window): void { Cef::quit(); }
    public function canClose(Window $window): bool { return true; }
    public function getInitialBounds(Window $window): Rect { return new Rect(100, 100, 900, 650); }
}

Cef::initialize();
$view   = BrowserView::createBrowserView($client, $url, new BrowserSettings(), null, null, null);
$window = Window::createTopLevelWindow(new Frame($view));
Cef::run();
```

Tips:

* Build the views in `onWindowCreated()`, not before: the window does not exist until CEF says so.
* Keep references to what you need later (`LabelButton`, the `BrowserView`) in properties of your delegates; set them to `null` before `Cef::shutdown()`.
* Update controls from handlers of the page: `examples/views.php` sets the window title from `DisplayHandler::onTitleChange()` and enables Back and Forward from `LoadHandler::onLoadingStateChange()`.
* Closing: `Window::close()` asks the delegate `canClose()`, then `onWindowDestroyed()` follows; call `Cef::quit()` there.

## Input

Mouse clicks, the wheel and typing reach buttons and the page in both modes of the loop ([The message loop](message-loop.md#polling-and-the-external-pump)). A `BrowserView` with a live browser cannot be taken out of its window with `removeChildView()` or `removeAllChildViews()` (CEF ends the process when the browser closes afterwards): these throw an `Error`; close the browser first with `closeBrowser()`, or hide the view with `setVisible(false)`.

`tests/t_views_input.php` sends clicks, the wheel and typing with `xdotool` and checks that they arrive, on a virtual display without a window manager and with the window manager `twm`. A desktop environment's window manager and Wayland were not tried.

## Without a display

`xvfb-run -a php ...` gives the window a virtual screen. Nothing is shown, but the program, its events and its input work as on a real screen.
