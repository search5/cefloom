"""Which functions of the extension did the tests run? Reads the .gcda files of a coverage build.

    python3 tools/coverage_report.py <build directory of the coverage build> [--json out.json] [--markdown out.md]
        [--write-baseline tests/coverage-baseline.json] [--check tests/coverage-baseline.json]

--write-baseline saves the numbers that must not fall: the methods run in each area of CEF and the line coverage of the hand written
files. --check compares this run with a baseline and exits 1 if any number is lower (a tolerance of 0.5 points for lines; none for methods).

The build is made by `CEFLOOM_COVERAGE=1 BUILD=... tools/build.sh` and the tests are run with `CEFLOOM_DIST=<BUILD>/dist`
(tools/coverage.sh does both). gcov (-n -f -m) gives, for every function, the lines it has and the share the tests ran.

What counts as covered:
  * a PHP method of a library class or a static function (zim_*): the tests called it;
  * a proxy method of a client class (Proxy_*::Method): CEF called it (the PHP method it hands on to may not exist);
  * the hand written files (src/, runtime/): line coverage as gcov reports it.
"""

import argparse
import json
import os
import re
import subprocess
import sys
from collections import defaultdict

FUNCTION = re.compile(r"^Function '(.+)'$")
LINES = re.compile(r"^Lines executed:([0-9.]+)% of (\d+)$")
FILE = re.compile(r"^File '(.+)'$")


# The classes of CEF by what they are for, to see which part of the API the tests leave out.
AREAS = {
    "values and data": "Value DictionaryValue ListValue BinaryValue PostData PostDataElement Image DragData ProcessMessage "
                       "SharedMemoryRegion SharedProcessMessageBuilder",
    "browser and frame": "Browser BrowserHost Frame NavigationEntry NavigationEntryVisitor StringVisitor DevToolsMessageObserver "
                         "RunFileDialogCallback DownloadImageCallback CompletionCallback",
    "basic handlers": "Client LifeSpanHandler LoadHandler DisplayHandler RequestHandler ResourceRequestHandler CookieAccessFilter "
                      "ResponseFilter",
    "input and interaction handlers": "KeyboardHandler FocusHandler ContextMenuHandler ContextMenuParams RunContextMenuCallback "
                                      "RunQuickMenuCallback JSDialogHandler JSDialogCallback DialogHandler FileDialogCallback "
                                      "DragHandler FindHandler PermissionHandler PermissionPromptCallback MediaAccessCallback",
    "download and rendering handlers": "DownloadHandler DownloadItem DownloadItemCallback BeforeDownloadCallback RenderHandler "
                                       "AudioHandler AccessibilityHandler CommandHandler FrameHandler",
    "network and resources": "URLRequest URLRequestClient ResourceHandler SchemeHandlerFactory Request Response Callback "
                             "ResourceReadCallback ResourceSkipCallback AuthCallback ResolveCallback Server ServerHandler",
    "cookies, request context, preferences": "CookieManager CookieVisitor SetCookieCallback DeleteCookiesCallback RequestContext "
                                             "RequestContextHandler PreferenceManager PreferenceObserver SettingObserver",
    "certificates and SSL": "SSLInfo SSLStatus X509Certificate X509CertPrincipal SelectClientCertificateCallback",
    "Views": "Window WindowDelegate View ViewDelegate Panel PanelDelegate BrowserView BrowserViewDelegate Button ButtonDelegate "
             "LabelButton MenuButton MenuButtonDelegate MenuButtonPressedLock Textfield TextfieldDelegate ScrollView MenuModel "
             "MenuModelDelegate BoxLayout FillLayout Layout Display OverlayController",
    "Cast and media": "MediaRouter MediaRoute MediaSink MediaSource MediaObserver MediaRouteCreateCallback "
                      "MediaSinkDeviceInfoCallback Registration",
    "printing": "PrintHandler PrintSettings PrintDialogCallback PrintJobCallback PdfPrintCallback",
    "streams, zip, XML": "StreamReader StreamWriter ReadHandler WriteHandler XmlReader ZipReader",
    "threads and tasks": "Task TaskRunner Thread WaitableEvent TaskManager UnresponsiveProcessCallback",
    "app, command line, updater, resources": "App BrowserProcessHandler CommandLine ComponentUpdater Component ComponentUpdateCallback "
                                             "ResourceBundle ResourceBundleHandler EndTracingCallback",
    "global functions": "(global functions)",
}


def gcov_functions(gcda):
    """[(function, percent, lines)] and {file: (percent, lines)} that gcov reports for one .gcda file."""
    out = subprocess.run(["gcov", "-n", "-f", "-m", gcda], capture_output=True, text=True, cwd=os.path.dirname(gcda)).stdout
    functions, files = [], {}
    lines = out.splitlines()
    i = 0
    while i < len(lines):
        m = FUNCTION.match(lines[i])
        if m and i + 1 < len(lines):
            n = LINES.match(lines[i + 1])
            if n:
                functions.append((m.group(1), float(n.group(1)), int(n.group(2))))
            i += 2
            continue
        m = FILE.match(lines[i])
        if m and i + 1 < len(lines):
            n = LINES.match(lines[i + 1])
            if n:
                files[m.group(1)] = (float(n.group(1)), int(n.group(2)))
            i += 2
            continue
        i += 1
    return functions, files


def short(path):
    for marker in ("/ext/", "/runtime/"):
        if marker in path:
            return path.split(marker, 1)[1] if marker == "/ext/" else "runtime/" + path.split(marker, 1)[1]
    return path


def area_numbers(result):
    """{area: [methods, ran]} for the areas of AREAS."""
    numbers = {}
    for area, names in AREAS.items():
        keys = [names] if names.startswith("(") else ["Cef" + n for n in names.split()]
        have = [result["classes"][k] for k in keys if k in result["classes"]]
        numbers[area] = [sum(c["methods"] for c in have), sum(c["ran"] for c in have)]
    return numbers


def baseline_of(result):
    total = sum(c["methods"] for c in result["classes"].values())
    ran = sum(c["ran"] for c in result["classes"].values())
    return {
        "note": "Numbers that the tests must not fall below (tools/coverage_report.py --check). Raise them with --write-baseline after adding tests.",
        "methods": total,
        "ran": ran,
        "areas": area_numbers(result),
        "hand_written_line_percent": {f: d["percent"] for f, d in sorted(result["hand"].items())},
    }


def check_baseline(result, path):
    """Problems (a list of text) where this run is below the baseline in the file."""
    with open(path, encoding="utf-8") as fh:
        base = json.load(fh)
    now = baseline_of(result)
    problems = []
    if now["ran"] < base["ran"]:
        problems.append("methods run: %d, the baseline has %d" % (now["ran"], base["ran"]))
    for area, (methods, ran) in base["areas"].items():
        got = now["areas"].get(area, [0, 0])[1]
        if got < ran:
            problems.append("%s: %d methods run, the baseline has %d" % (area, got, ran))
    for name, percent in base["hand_written_line_percent"].items():
        got = now["hand_written_line_percent"].get(name)
        if got is None:
            problems.append("%s: not measured any more" % name)
        elif got < percent - 0.5:
            problems.append("%s: %.1f%% of lines, the baseline has %.1f%%" % (name, got, percent))
    return problems


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("build")
    parser.add_argument("--json")
    parser.add_argument("--markdown")
    parser.add_argument("--write-baseline")
    parser.add_argument("--check")
    args = parser.parse_args()

    args.build = os.path.abspath(args.build)   # gcov is run from other directories: a relative path finds nothing there
    gcdas = []
    for root, _, names in os.walk(args.build):
        gcdas += [os.path.join(root, n) for n in names if n.endswith(".gcda")]
    if not gcdas:
        sys.exit("no .gcda files under %s: run the tests on the coverage build first" % args.build)

    classes = defaultdict(lambda: {"lib": [], "client": [], "kind": ""})   # class -> methods [(name, ran)]
    hand = {}                                                             # file -> (percent, lines, [functions not run])
    structs = {"functions": 0, "ran": 0}
    for gcda in sorted(gcdas):
        base = os.path.basename(gcda)[:-5]
        functions, files = gcov_functions(gcda)
        source = next((f for f in files if os.path.basename(f).startswith(base + ".")), None)
        if base.startswith("lib_Cef"):
            cls = base[len("lib_"):]
            for name, percent, lines in functions:
                m = re.match(r"zim_%s_(\w+)\(" % cls, name) or re.match(r"zim_%s_(\w+)$" % cls, name)
                if m:
                    classes[cls]["lib"].append((m.group(1), percent > 0))
            classes[cls]["kind"] = "library"
        elif base.startswith("client_Cef"):
            cls = base[len("client_"):]
            for name, percent, lines in functions:
                # the method itself, not the lambdas and helper functions the compiler makes inside it
                m = re.match(r"(?:\(anonymous namespace\)::)?Proxy_%s::(\w+)\(.*\)(?: const)?$" % cls, name)
                if m and m.group(1) not in (cls, "Proxy_" + cls, "HasOneRef", "HasAtLeastOneRef", "AddRef", "Release") and "::{lambda" not in name:
                    classes[cls]["client"].append((m.group(1), percent > 0))
            classes[cls]["kind"] = "client"
        elif base == "cef_functions":
            for name, percent, lines in functions:
                m = re.match(r"zim_Cef_(\w+)\(", name)
                if m:
                    classes["(global functions)"]["lib"].append((m.group(1), percent > 0))
            classes["(global functions)"]["kind"] = "global"
        elif base in ("structs", "enums", "register_all"):
            for name, percent, lines in functions:
                structs["functions"] += 1
                structs["ran"] += percent > 0
        else:
            if source:
                p, n = files[source]
                hand[short(source)] = (p, n, [name for name, percent, lines in functions if percent == 0 and lines > 0])
    # a hand written file can show up once per object: keep the last

    result = {"classes": {}, "hand": {}, "structs": structs}
    for cls, data in sorted(classes.items()):
        methods = data["lib"] or data["client"]
        ran = [n for n, r in methods if r]
        result["classes"][cls] = {"kind": data["kind"], "methods": len(methods), "ran": len(ran),
                                  "not_run": sorted(n for n, r in methods if not r)}
    for f, (p, n, missed) in sorted(hand.items()):
        result["hand"][f] = {"percent": p, "lines": n, "functions_not_run": sorted(missed)}

    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump(result, fh, indent=1, ensure_ascii=False)
    total = sum(c["methods"] for c in result["classes"].values())
    ran = sum(c["ran"] for c in result["classes"].values())
    print("classes: %d, methods: %d, run by the tests: %d (%.1f%%)" % (len(result["classes"]), total, ran, 100.0 * ran / max(total, 1)))
    for kind in ("library", "client", "global"):
        cs = [c for c in result["classes"].values() if c["kind"] == kind]
        t = sum(c["methods"] for c in cs)
        r = sum(c["ran"] for c in cs)
        print("  %-8s %3d classes %5d methods %5d run (%.1f%%); classes with no method run: %d" % (
            kind, len(cs), t, r, 100.0 * r / max(t, 1), sum(1 for c in cs if c["ran"] == 0)))
    print("by area:")
    placed = set()
    for area, names in AREAS.items():
        keys = [names] if names.startswith("(") else ["Cef" + n for n in names.split()]
        have = [result["classes"][k] for k in keys if k in result["classes"]]
        placed.update(keys)
        methods = sum(c["methods"] for c in have)
        ran = sum(c["ran"] for c in have)
        print("  %-40s %3d classes %5d methods %5d run (%3.0f%%)  classes with none run: %d" % (
            area, len(have), methods, ran, 100.0 * ran / max(methods, 1), sum(1 for c in have if c["ran"] == 0)))
    unplaced = sorted(set(result["classes"]) - placed)
    if unplaced:
        print("  not in any area:", ", ".join(unplaced))
    print("hand written files:")
    for f, d in result["hand"].items():
        print("  %-26s %5.1f%% of %d lines" % (f, d["percent"], d["lines"]))
    if args.write_baseline:
        if not baseline_of(result)["ran"]:
            sys.exit("no method counted as run: the baseline is not written")
        with open(args.write_baseline, "w", encoding="utf-8") as fh:
            json.dump(baseline_of(result), fh, indent=1, ensure_ascii=False)
            fh.write("\n")
        print("baseline written to", args.write_baseline)
    if args.check:
        problems = check_baseline(result, args.check)
        for problem in problems:
            print("BELOW THE BASELINE:", problem)
        if problems:
            sys.exit(1)
        print("not below the baseline", args.check)


if __name__ == "__main__":
    main()
