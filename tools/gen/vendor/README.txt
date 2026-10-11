These files are copied unmodified from the Chromium Embedded Framework source
(https://github.com/chromiumembedded/cef, directory tools/) and are used only by
the binding generator in tools/gen. They are covered by CEF's BSD-style license
(LICENSE.txt in this directory).

Copied from CEF commit ff57d4eae16d36457895f2de115a71d502e85a08 (2026-09-29), master branch:
  cef_parser.py  date_util.py  file_util.py  version_util.py

The parser reads the headers of the CEF distribution that is actually used
(build/native/cef/include), so it follows that version. To update these files,
copy them again from CEF and run `python tools/gen/generate.py --check`.
