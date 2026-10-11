"""Mechanical checks of the wiki (see SCHEMA.md, "lint").

    python cefloom/llm-wiki/lint.py                 # print findings, exit code 1 on errors
    python cefloom/llm-wiki/lint.py --quiet         # print only the findings

Checks: front matter, `type` matching the folder, `sources` that exist (paths relative to
the workspace root, the parent of the cefloom directory; https URLs are accepted without
a check), relative links that resolve and stay inside cefloom, pages missing from index.md,
pages that no other page links to (orphans), pages over 200 lines (unless the front matter
says `generated: true`), em dashes, the format and the date order of log.md entries, and the numbers that
index.md quotes about other pages (how many cautions, the highest D, F and K number).
Reading the pages for contradictions and stale claims is not something a script can do.
"""

import argparse
import re
import sys
from pathlib import Path

WIKI = Path(__file__).resolve().parent
PROJECT = WIKI.parent          # cefloom/
WORKSPACE = PROJECT.parent     # directory that holds cef_origin, cefpython, java-cef, ...
PAGES = WIKI / "pages"

FOLDER_TYPES = {
    "concepts": "concept",
    "components": "component",
    "procedures": "procedure",
    "reference": "reference",
    "summaries": "summary",
    "analyses": "analysis",
}
MAX_LINES = 200
LINK = re.compile(r"\[[^\]]*\]\(([^)\s]+)\)")
LOG_ENTRY = re.compile(r"^## \[\d{4}-\d{2}-\d{2}\] (ingest|query|lint|schema) \| .+")
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def front_matter(text):
    """Return (fields, sources) of a page, or None when the page has no front matter."""
    if not text.startswith("---\n"):
        return None
    end = text.find("\n---\n", 4)
    if end < 0:
        return None
    fields, sources, key = {}, [], None
    for line in text[4:end].splitlines():
        if line.startswith("  - ") and key == "sources":
            sources.append(line[4:].strip())
        elif ":" in line and not line.startswith(" "):
            key, _, value = line.partition(":")
            key = key.strip()
            fields[key] = value.strip()
    return fields, sources


def links(text):
    """Relative targets of the markdown links (external and anchors excluded).

    Fenced code blocks and inline code are skipped: they hold examples of links.
    """
    text = re.sub(r"```.*?```", "", text, flags=re.S)
    text = re.sub(r"`[^`\n]*`", "", text)
    found = []
    for target in LINK.findall(text):
        if re.match(r"^[a-z]+:", target) or target.startswith("#"):
            continue
        found.append(target.split("#")[0])
    return found


def leaves_project(resolved):
    """True for a link to a file outside cefloom: it exists on one computer only."""
    return not resolved.is_relative_to(PROJECT)


def check_index_numbers(index_text, errors):
    """The index quotes counts of other pages ("rule 25", "D1 to D12"); they must match those pages.

    Each entry: (the page, how the index quotes it, how to count in the page, what it is).
    """
    reference = PAGES / "reference"

    def numbers(page, pattern):
        text = (reference / page).read_text(encoding="utf-8")
        return [int(n) for n in re.findall(pattern, text, re.M)]

    def line_about(page):
        for line in index_text.splitlines():
            if f"reference/{page})" in line:
                return line
        return ""

    checks = [
        ("cautions.md", r"규칙 (\d+)개", None, "cautions"),
        ("design-decisions.md", r"D1부터 D(\d+)", r"^## D(\d+)\.", "decisions"),
        ("verified-findings.md", None, r"^## F(\d+)\.", "findings"),
        ("known-constraints.md", r"K1부터 K(\d+)", r"^\| K(\d+) \|", "constraints"),
    ]
    for page, quoted, counted, what in checks:
        line = line_about(page)
        if not line:
            continue  # the missing entry is reported elsewhere
        if page == "cautions.md":
            actual = len(numbers(page, r"^(\d+)\. \*\*"))
            found = re.search(quoted, line)
            quoted_value = int(found.group(1)) if found else None
        elif page == "verified-findings.md":
            actual = max(numbers(page, counted), default=0)
            ids = [int(n) for n in re.findall(r"F(\d+)", line)]
            quoted_value = max(ids) if ids else None
        else:
            actual = max(numbers(page, counted), default=0)
            found = re.search(quoted, line)
            quoted_value = int(found.group(1)) if found else None
        if quoted_value is None:
            errors.append(f"index.md: the entry of {page} does not say how many {what} there are")
        elif quoted_value != actual:
            errors.append(f"index.md: says {quoted_value} {what} for {page}, the page has {actual}")


def check_log_order(log_text, errors):
    previous = ""
    for number, line in enumerate(log_text.splitlines(), 1):
        match = LOG_ENTRY.match(line)
        if not match:
            continue
        date = line[4:14]
        if date < previous:
            errors.append(f"log.md:{number}: entry dated {date} comes after an entry dated {previous}")
        previous = max(previous, date)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    errors, warnings = [], []
    pages = sorted(PAGES.glob("*/*.md"))
    index_text = (WIKI / "index.md").read_text(encoding="utf-8")
    index_targets = {(WIKI / t).resolve() for t in links(index_text)}
    inbound = {p.resolve(): set() for p in pages}
    names = {}

    for page in pages:
        rel = page.relative_to(WIKI).as_posix()
        text = page.read_text(encoding="utf-8")
        lines = text.count("\n") + 1

        names.setdefault(page.name, []).append(rel)
        parsed = front_matter(text)
        if parsed is None:
            errors.append(f"{rel}: no front matter")
            continue
        fields, sources = parsed
        for key in ("title", "type", "updated"):
            if not fields.get(key):
                errors.append(f"{rel}: front matter lacks `{key}`")
        if not sources:
            errors.append(f"{rel}: front matter lacks `sources`")
        expected = FOLDER_TYPES.get(page.parent.name)
        if fields.get("type") and fields["type"] != expected:
            errors.append(f"{rel}: type `{fields['type']}` does not match folder (expected `{expected}`)")
        if fields.get("updated") and not DATE.match(fields["updated"]):
            errors.append(f"{rel}: `updated` is not YYYY-MM-DD")
        for source in sources:
            if source.startswith("https://"):
                continue
            if not (WORKSPACE / source).exists():
                errors.append(f"{rel}: source does not exist: {source}")

        if not re.search(r"^# .+", text.split("\n---\n", 1)[-1], re.M):
            errors.append(f"{rel}: no `# title` heading")
        if "## 관련 페이지" not in text:
            warnings.append(f"{rel}: no `## 관련 페이지` section")
        if lines > MAX_LINES and fields.get("generated") != "true":
            warnings.append(f"{rel}: {lines} lines (over {MAX_LINES}); consider splitting")
        if "—" in text:
            warnings.append(f"{rel}: contains an em dash")

        for target in links(text):
            resolved = (page.parent / target).resolve()
            if leaves_project(resolved):
                errors.append(f"{rel}: link leaves cefloom (write other projects' files as `inline code`): {target}")
            elif not resolved.exists():
                errors.append(f"{rel}: broken link: {target}")
            elif resolved in inbound and resolved != page.resolve():
                inbound[resolved].add(rel)

        if page.resolve() not in index_targets:
            errors.append(f"{rel}: not listed in index.md")

    for name, where in names.items():
        if len(where) > 1:
            errors.append(f"file name used more than once: {name} ({', '.join(where)})")

    for page, who in inbound.items():
        if not who:
            warnings.append(f"{page.relative_to(WIKI).as_posix()}: orphan (no other page links to it)")

    for target in links(index_text):
        if not (WIKI / target).exists():
            errors.append(f"index.md: broken link: {target}")
    for path in (WIKI / "README.md", WIKI / "SCHEMA.md", WIKI / "log.md", WIKI / "index.md"):
        text = path.read_text(encoding="utf-8")
        for target in links(text):
            resolved = (path.parent / target).resolve()
            if leaves_project(resolved):
                errors.append(f"{path.name}: link leaves cefloom (write other projects' files as `inline code`): {target}")
            elif not resolved.exists():
                errors.append(f"{path.name}: broken link: {target}")
        if "—" in text:
            warnings.append(f"{path.name}: contains an em dash")

    log_text = (WIKI / "log.md").read_text(encoding="utf-8")
    for number, line in enumerate(log_text.splitlines(), 1):
        if line.startswith("## ") and not LOG_ENTRY.match(line):
            errors.append(f"log.md:{number}: entry does not match `## [YYYY-MM-DD] ingest|query|lint|schema | title`")

    check_index_numbers(index_text, errors)
    check_log_order(log_text, errors)

    for message in errors:
        print("error:   " + message)
    for message in warnings:
        print("warning: " + message)
    if not args.quiet:
        print(f"{len(pages)} pages, {len(errors)} errors, {len(warnings)} warnings")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
