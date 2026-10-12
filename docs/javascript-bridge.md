# JavaScript bridge

JavaScript in a page can call PHP functions that you expose, and PHP can run JavaScript in a page.

## JavaScript calls PHP

<!-- docs-test: run bridge -->

```php
Cef::initialize();
Cef::expose('add', fn(int $a, int $b): int => $a + $b);
Cef::expose('user', fn(string $id): array => ['id' => $id, 'name' => 'Ada', 'tags' => ['admin']]);
```

```js
const sum  = await cefloom.call('add', 2, 3);          // 5
const user = await cefloom.call('user', '42');         // {id: "42", name: "Ada", tags: ["admin"]}
```

`window.cefloom.call(name, ...args)` exists in every frame of every page and returns a **Promise**.

* The arguments travel as JSON and arrive in PHP as scalars and arrays (a JSON object becomes an associative array). PHP's own type declarations apply: passing `'a'` to an `int` parameter is a `TypeError`.
* The return value is encoded as JSON. A function that returns nothing resolves to `null`. A result that cannot be encoded rejects the promise.
* Calls run on the PHP thread, one after another, inside the message loop. Several calls made together (`Promise.all`) each get their own answer.
* A call is **rejected** with an `Error` whose `code` and `message` say why:

| Case | `code` | `message` |
| --- | --- | --- |
| The PHP function threw an exception | 500 | the exception's message |
| The PHP function has a type error, or its result cannot be encoded | 500 | the error's message |
| No function is exposed under that name | 404 | `unknown function` |
| The request is not valid JSON | 400 | `invalid request` |
| PHP could not run the call (it is shutting down) | 500 | `PHP is not available` |

```js
try { await cefloom.call('boom'); } catch (e) { console.log(e.code, e.message); }   // 500 boom
```

`Cef::expose()` takes any callable. Expose before the page calls, at any time after `initialize()`.

> **Security.** Every page loaded in any browser of the process can call every exposed function, whatever its origin. Expose only functions that are safe for all pages you load, validate their arguments, and do not load untrusted pages in a program that exposes functions with side effects.

`tests/t_bridge.php` covers these cases.

## PHP calls JavaScript

```php
$frame->executeJavaScript('document.title = "hi"', '', 0);     // code, a URL for error messages, a first line number
```

`executeJavaScript()` returns nothing. To get a result back, let the page call PHP: have the script finish with `cefloom.call('result', value)` and expose a `result` function, or print with `console.log` and read it in `DisplayHandler::onConsoleMessage()` (the way `examples/hello.php` does).
