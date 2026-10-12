# Context menu

A right click on a page makes CEF build a menu: the items that fit the place that was clicked (back and forward, link, image, edit and spelling items, and so on). A `ContextMenuHandler` ([Handlers](handlers.md)) can change that menu, show it with its own widgets, and run the command that was chosen. The `Client` returns it from `getContextMenuHandler()`; return the same object every time.

`docs/recipes/context-menu.php` is a complete program. It was run under a virtual X server three times in a row (`xvfb-run -a php -d extension=build/dist/cefloom.so docs/recipes/context-menu.php`) and prints each time:

```
link: http://example.org/target
editable: false
default items: 9
removed: true
items now: 9
last item: Show address (type command)
address: http://example.org/target
dismissed
```

The program sends the right click itself with `sendMouseClickEvent()`, so it needs no input tool. `tests/t_input.php` drives the same handler with real mouse and keyboard events from `xdotool`.

## The four methods

They run on the PHP thread ([Concepts](concepts.md#where-php-runs)), in this order for one menu:

| Method | What it is for |
| --- | --- |
| `onBeforeContextMenu($browser, $frame, $params, $model)` | `$model` holds the menu CEF built. Change it here: add, remove, rename, disable. |
| `runContextMenu($browser, $frame, $params, $model, $callback)` | Return `true` to show the menu yourself and call `$callback->continue($commandId, $eventFlags)` with the chosen item, now or later, or `$callback->cancel()`. Return `false` and CEF shows `$model`. |
| `onContextMenuCommand($browser, $frame, $params, $commandId, $eventFlags)` | Return `true` when your program ran the command. Return `false` for the standard commands CEF can run itself. |
| `onContextMenuDismissed($browser, $frame)` | The menu is gone, whether an item was chosen or not. |

Do not keep `$params` or `$model` after the method returns (the CEF header says so for both). The program above printed `dismissed` after the command, and `cancel()` also calls `onContextMenuDismissed()` (seen in a run).

If `onBeforeContextMenu()` calls `$model->clear()`, no menu appears. In a run, `runContextMenu()` was not called after that, and neither was `onContextMenuDismissed()`.

An exception thrown in one of these methods follows the usual rule ([Concepts](concepts.md#errors)).

## Where the click was

`$params` (`ContextMenuParams`) describes the place:

| Method | Tells |
| --- | --- |
| `getXCoord()`, `getYCoord()` | the position of the click, relative to the view |
| `getTypeFlags()` | a combination of `ContextMenuTypeFlags`: `PAGE`, `FRAME`, `LINK`, `MEDIA`, `SELECTION`, `EDITABLE` |
| `getLinkUrl()`, `getUnfilteredLinkUrl()` | the link under the click; the second is meant only for "copy link address" |
| `getSourceUrl()`, `hasImageContents()`, `getTitleText()` | the source of an `img`, `audio` or `video`, whether an image has content, its title or alt text |
| `getMediaType()`, `getMediaStateFlags()` | `ContextMenuMediaType` of the node and `ContextMenuMediaStateFlags` (paused, muted, loop, and so on) |
| `getPageUrl()`, `getFrameUrl()`, `getFrameCharset()` | the top page, the frame that was clicked, and its encoding |
| `getSelectionText()` | the selected text |
| `isEditable()`, `isSpellCheckEnabled()`, `getEditStateFlags()` | an editable node, whether spelling is checked there, and `ContextMenuEditStateFlags` (can copy, paste, and so on) |
| `getMisspelledWord()`, `getDictionarySuggestions()` | the misspelled word and its suggestions (an array, or `null`) |
| `isCustomMenu()` | the page itself brought items for the menu |

In the runs above, a click on a link gave the address in `getLinkUrl()`; a click on the page outside any link gave `getTypeFlags()` of `3` (`PAGE | FRAME`); a click in an input with selected text gave `isEditable()` `true`, the text in `getSelectionText()` and flags `51` (`PAGE | FRAME | SELECTION | EDITABLE`). Suggestions for a misspelled word were not tried.

## The model

`MenuModel` is the list of items. A command id identifies an item; an index is its place.

```php
$model->addItem(SHOW_ADDRESS, 'Show address');       // a command
$model->addSeparator();
$model->addCheckItem(MenuId::USER_FIRST + 1, 'Wrap lines');
$model->addRadioItem(MenuId::USER_FIRST + 2, 'Small', 1);       // the last argument is the group
$model->addRadioItem(MenuId::USER_FIRST + 3, 'Large', 1);
$sub = $model->addSubMenu(MenuId::USER_FIRST + 4, 'More');      // returns the MenuModel of the submenu
$sub->addItem(MenuId::USER_FIRST + 5, 'Inside');
```

- Add and insert: `addItem`, `addCheckItem`, `addRadioItem`, `addSubMenu`, `addSeparator` and the `insert...At($index, ...)` forms.
- Take away: `remove($commandId)` and `removeAt($index)` return `true` when an item was removed; `clear()` empties the model.
- Read: `getCount()`, `getIndexOf()`, `getCommandIdAt()`, `getLabel()` and `getLabelAt()`, `getType()` and `getTypeAt()` (a `MenuItemType`: `COMMAND`, `CHECK`, `RADIO`, `SEPARATOR`, `SUBMENU`), `getGroupId()`, `getSubMenu()`, `isSubMenu()`.
- State: `setLabel`, `setEnabled`, `setVisible`, `setChecked`, `setAccelerator`, `removeAccelerator`, each with an `...At($index)` form; `isEnabled`, `isVisible`, `isChecked`, `hasAccelerator`, `getAccelerator` read it back. `setColor` and `setFontList` also exist.

`tests/t_input.php` builds all of these inside `onBeforeContextMenu()` and reads them back: counts, labels, types, states, groups, a submenu with its items, an accelerator set and removed, and the effect of `remove()` and `removeAt()`.

Command ids of your own lie between `MenuId::USER_FIRST` (26500) and `MenuId::USER_LAST` (28500).

## Ids of the standard items

The ids of the items CEF builds depend on the runtime style ([Printing and PDF](printing.md) describes the Alloy style, `$windowInfo->runtimeStyle = 2`). Both were read with `getCommandIdAt()` in this build:

| Style | Ids of the standard items |
| --- | --- |
| Chrome (the default) | Chrome's own numbers. A link menu had 9 items with the ids `50100`, `50101`, `50111`, `50102`, a separator, `50103`, `50104`, a separator and `50162`. A menu for a click on the page itself had 11 items, the first three (labelled back, forward and reload) with the ids `33000`, `33001` and `33002`. A click in an input with selected text gave 15 items, including two submenus (`57329`, `57328`). |
| Alloy | The values of `MenuId`: 5 items, `MenuId::BACK` (100), `MenuId::FORWARD` (101), a separator, `MenuId::PRINT` (131) and `MenuId::VIEW_SOURCE` (132), for a link and for the page alike. |

So `$model->remove(MenuId::VIEW_SOURCE)` works only in the Alloy style; in the Chrome style it returned `false` because the item is not there. The labels follow the locale of the machine (Korean in these runs). The CEF header says that `cef_id_for_command_id_name()` maps the names of Chrome's command ids to the numbers of a CEF version; cefloom does not declare that function, so the numbers above are known only for this build. Read the ids from the model instead of writing them down, as `docs/recipes/context-menu.php` does with `getCommandIdAt()`.

In the Chrome style, a `CommandHandler` ([Handlers](handlers.md)) gets `onChromeCommand()` for a Chrome command after `onContextMenuCommand()` (CEF header).

## Showing the menu yourself

`runContextMenu()` returning `true` hands the display to the program. Draw `$model` with whatever you have, and call the callback with the id of the chosen item. The program above picks the last item at once:

```php
public function runContextMenu(Browser $browser, Frame $frame, ContextMenuParams $params, MenuModel $model, RunContextMenuCallback $callback): bool
{
    $last = $model->getCount() - 1;
    $callback->continue($model->getCommandIdAt($last), 0);       // the second argument is the event flags
    return true;
}
```

`onContextMenuCommand()` then gets the id and the flags (`tests/t_input.php` checks `[[26509, 0]]`). `continue()` may be called later, for example after your own window has shown the items; cefloom has no widget for that and the example does not draw one. Only the case where the callback is called inside `runContextMenu()` was run.

A `MenuModel` also works in the Views framework: `MenuModel::create($delegate)` and `Window::showMenu()` ([Windows with Views](views.md)).

## Known limits

- Windowless (off-screen) browsers have two more methods, `runQuickMenu()` with `onQuickMenuCommand()` and `onQuickMenuDismissed()`, for the menu that appears over a selection. They are declared in `php/cefloom.stub.php` and were not tried, and neither was the context menu of a windowless browser.
- The native menu, shown by CEF when `runContextMenu()` returns `false`, was tried with `xdotool` (`tests/t_input.php`): the handler's `onBeforeContextMenu()` ran, a key press followed, and `onContextMenuCommand()` or `onContextMenuDismissed()` was called. What the native menu looked like and which item the key press chose were not checked.
- A menu with the window manager of a desktop environment and on Wayland was not tried.
