# Printing and PDF

`BrowserHost::printToPDF()` saves what a browser shows as a PDF file. `examples/print-pdf.php` is a complete program:

```sh
php -d extension=build/dist/cefloom.so examples/print-pdf.php <url> <output.pdf> [--landscape] [--letter]
```

```php
final class Done extends PdfPrintCallback
{
    public function onPdfPrintFinished(string $path, bool $ok): void { echo $ok ? "wrote $path\n" : "failed\n"; Cef::quit(); }
}

// in LoadHandler::onLoadEnd(), for the main frame:
$settings = new PdfPrintSettings();
$settings->printBackground = 1;
$settings->scale = 1.0;
$settings->paperWidth = 8.27;      // inches: A4
$settings->paperHeight = 11.69;
$browser->getHost()->printToPDF('/tmp/page.pdf', $settings, new Done());
```

* Print **after the page has loaded** (`onLoadEnd` of the main frame): `printToPDF()` prints what the browser has at that moment.
* `PdfPrintSettings` has the page size and orientation (`landscape`), margins (`marginType`, `marginTop`, ...), `pageRanges` (such as `"1-3,5"`), header and footer templates (`displayHeaderFooter`, `headerTemplate`, `footerTemplate`), and `preferCssPageSize`. Set `scale` and the paper size: a zero value is not a default.
* `onPdfPrintFinished()` is called when the file is written; `$ok` says whether it worked.
* The browser can be a normal window. `printToPDF()` does not need it to be visible.

`BrowserHost::print()` does not call a `PrintHandler` (`Client::getPrintHandler()`) in this build: the Chrome runtime handles printing with its own print preview, and the handler is never asked (`tests/t_print.php` keeps that as it is). Use `printToPDF()` to print from a program. `PrintSettings` can still be created and filled in as a plain object.
