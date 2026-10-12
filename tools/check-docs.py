"""Check the documentation: every relative link points at a file, every #anchor at a heading, every path that the pages
name in `code` (examples/..., tests/..., tools/..., bin/..., php/..., experiments/...) exists.

    python3 tools/check-docs.py [docs directory]          # default: docs/ next to tools/; exit code 1 on problems
"""

import os
import re
import sys


def headings(text):
    found = set()
    for h in re.findall(r"^#{1,6} (.+)$", text, re.M):
        found.add(re.sub(r"[^a-z0-9 -]", "", h.lower()).strip().replace(" ", "-"))
    return found


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    root = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else os.path.join(os.path.dirname(here), "docs")
    project = os.path.dirname(root)
    problems = []
    names = sorted(n for n in os.listdir(root) if n.endswith(".md"))
    if not names:
        print("no pages in %s" % root)
        return 1
    for name in names:
        text = open(os.path.join(root, name), encoding="utf-8").read()
        # code fences hold examples of links and paths: leave them out
        prose = re.sub(r"```.*?```", "", text, flags=re.S)
        links_text = re.sub(r"`[^`\n]*`", "", prose)      # inline code is not a link, but its paths are checked below
        for target in re.findall(r"\]\(([^)\s]+)\)", links_text):
            if re.match(r"^[a-z]+:", target):
                continue
            path, _, fragment = target.partition("#")
            full = os.path.normpath(os.path.join(root, path)) if path else os.path.join(root, name)
            if path and not os.path.exists(full):
                problems.append("%s: link to a file that does not exist: %s" % (name, target))
            elif fragment and full.endswith(".md"):
                if fragment not in headings(open(full, encoding="utf-8").read()):
                    problems.append("%s: link to a heading that does not exist: %s" % (name, target))
        for ref in sorted(set(re.findall(r"`((?:examples|tests|tools|bin|php|experiments|ext|llm-wiki)/[A-Za-z0-9_./-]+)`", prose))):
            if not os.path.exists(os.path.join(project, ref.rstrip("/"))):
                problems.append("%s: names a path that does not exist: %s" % (name, ref))
    for p in problems:
        print(p)
    print("%d pages, %d problems" % (len(names), len(problems)))
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
