"""Write the reStructuredText of the API reference from ``php/cefloom.stub.php``.

The stub is generated from the CEF headers by ``tools/gen`` and carries the phpdoc of every class, method, property
and constant, so the reference is a rendering of it and holds no text of its own. The pages use the directives of
``sphinxcontrib-phpdomain``::

    python3 tools/docs/gen_api.py [stub] [output directory]    # defaults: php/cefloom.stub.php, docs/api
"""

import os
import re
import sys

#: Page of the reference and the title it gets, in the order of the table of contents.
PAGES = [
    ("cef", "Cef", "The static entry point: initialization, the message loop, scheme registration, the JavaScript bridge and the other global functions of CEF."),
    ("browser", "Browsers and frames", "The browser, its host, frames, navigation entries, requests, responses and the other objects that CEF hands to the handlers."),
    ("handlers", "Handlers", "The classes that a program extends to receive events and to answer requests."),
    ("views", "Views", "The windows, panels, buttons, layouts and menus of CEF's own toolkit."),
    ("values", "Values and streams", "Dictionaries, lists, binary values, streams, readers and writers, and the other data holders."),
    ("structures", "Structures", "Plain structures whose properties are read and written directly."),
    ("types", "Constants", "The enumerations of CEF, as classes of constants."),
]

HANDLER_SUFFIX = ("Handler", "Delegate", "Callback", "Visitor", "Listener", "Factory", "Observer", "App", "Client")
VIEW_WORDS = ("View", "Window", "Button", "Panel", "Layout", "MenuModel", "Display", "Textfield", "ScrollView")
VALUE_WORDS = ("Value", "Stream", "Reader", "Writer", "Buffer", "Binary", "Dictionary", "List", "Image", "Zip", "Xml", "Task", "Process", "Cookie", "Registration")


def parse(path):
    """Return ``[(namespace, class record)]`` in file order.

    A class record is a dict with ``name``, ``kind``, ``final``, ``extends``, ``doc`` and ``members`` (a list of
    dicts with ``kind`` in ``method``, ``property`` and ``const``).
    """
    classes = []
    namespace = ""
    current = None
    doc = []
    in_doc = False
    for raw in open(path, encoding="utf-8"):
        line = raw.rstrip("\n")
        text = line.strip()
        if in_doc:
            if text.endswith("*/"):
                body = text[:-2].strip().lstrip("*").strip()
                if body:
                    doc.append(body)
                in_doc = False
            else:
                doc.append(text[1:].strip() if text.startswith("*") else text)
            continue
        if text.startswith("/**"):
            body = text[3:]
            if body.endswith("*/"):
                doc = [body[:-2].strip()]
            else:
                doc = [body.strip()] if body.strip() else []
                in_doc = True
            continue
        m = re.match(r"namespace ([\w\\]+) \{", text)
        if m:
            namespace = m.group(1)
            continue
        m = re.match(r"(final |abstract )?(class|interface) (\w+)(?: extends ([\w\\]+))? \{", text)
        if m:
            current = {"name": m.group(3), "kind": m.group(2), "final": m.group(1) == "final " if m.group(1) else False,
                       "extends": m.group(4), "doc": doc, "members": [], "namespace": namespace}
            classes.append(current)
            doc = []
            continue
        if text == "}":
            current = None
            doc = []
            continue
        if current is None:
            continue
        m = re.match(r"public (abstract |static )?function (\w+)\((.*)\)(?:: ([^{]+))? \{\}", text)
        if m:
            current["members"].append({"kind": "method", "name": m.group(2), "static": m.group(1) == "static ",
                                       "params": m.group(3), "returns": (m.group(4) or "").strip(), "doc": doc})
            doc = []
            continue
        m = re.match(r"public const (\w+) = (.+);", text)
        if m:
            current["members"].append({"kind": "const", "name": m.group(1), "value": m.group(2), "doc": doc})
            doc = []
            continue
        m = re.match(r"public (static |readonly )?(\??[\w\\|]+) \$(\w+);", text)
        if m:
            current["members"].append({"kind": "property", "name": m.group(3), "type": m.group(2), "doc": doc})
            doc = []
            continue
        if text:
            doc = []
    return classes


def page_of(cls):
    """Return the key of the page that documents ``cls``."""
    name = cls["name"]
    if cls["namespace"].endswith("Types"):
        return "types"
    if name == "Cef":
        return "cef"
    methods = [m for m in cls["members"] if m["kind"] == "method" and m["name"] != "__construct"]
    if not cls["final"] and name.endswith(HANDLER_SUFFIX):
        return "handlers"
    if not methods:
        return "structures"
    if any(w in name for w in VIEW_WORDS):
        return "views"
    if any(w in name for w in VALUE_WORDS):
        return "values"
    return "browser"


_SUBST = re.compile(r"\|([A-Za-z_][\w:.\-]*)\|")


def rst_inline(text):
    """Escape ``text`` for reST: ``|name|`` becomes a literal, ``*`` and a trailing ``_`` lose their meaning."""
    text = text.replace("\\", "\\\\")
    text = _SUBST.sub(lambda m: "``%s``" % m.group(1), text)
    parts = re.split(r"(``[^`]*``)", text)
    for i in range(0, len(parts), 2):
        part = parts[i].replace("*", "\\*").replace("`", "\\`").replace("|", "\\|")
        part = re.sub(r"(\w)_(?=\W|$)", r"\1\\_", part)
        part = re.sub(r"(^|\s)_(\w)", r"\1\\_\2", part)
        parts[i] = part
    return "".join(parts)


def doc_paragraphs(lines):
    """Split a phpdoc into ``(paragraphs, tags)`` where ``tags`` is a list of ``(tag, text)``."""
    paragraphs, tags, buf = [], [], []
    for line in lines:
        if line.startswith("@"):
            tag, _, rest = line[1:].partition(" ")
            tags.append((tag, rest.strip()))
        elif tags and line:
            tags[-1] = (tags[-1][0], tags[-1][1] + " " + line)
        elif line:
            buf.append(line)
        elif buf:
            paragraphs.append(" ".join(buf))
            buf = []
    if buf:
        paragraphs.append(" ".join(buf))
    return paragraphs, tags


def body(lines, indent):
    """Return the reST body of a phpdoc, indented by ``indent`` spaces."""
    paragraphs, tags = doc_paragraphs(lines)
    pad = " " * indent
    out = []
    for p in paragraphs:
        out.append(pad + rst_inline(p))
        out.append("")
    for tag, text in tags:
        if tag == "see":
            out.append("%s:see: ``%s``" % (pad, text.replace("`", "")))
        elif tag in ("param", "return", "throws", "note", "deprecated"):
            out.append("%s:%s: %s" % (pad, {"return": "returns", "throws": "throws", "note": "note",
                                             "param": "param", "deprecated": "deprecated"}[tag], rst_inline(text)))
    if out and out[-1] != "":
        out.append("")
    return out


def render_class(cls):
    """Return the reST lines of one class."""
    out = []
    out.append(".. php:class:: %s" % cls["name"])
    out.append("")
    if cls["extends"]:
        out.append("   Extends :php:class:`%s`." % cls["extends"].split("\\")[-1])
        out.append("")
    out.extend(body(cls["doc"], 3))
    for m in cls["members"]:
        if m["kind"] == "const":
            # phpdomain backtracks without end on ``NAME = value``; the value goes into the body.
            out.append("   .. php:const:: %s" % m["name"])
            out.append("")
            out.append("      Value ``%s``." % m["value"])
            out.append("")
            out.extend(body(m["doc"], 6))
        elif m["kind"] == "property":
            out.append("   .. php:attr:: $%s" % m["name"])
            out.append("")
            out.append("      Type ``%s``." % m["type"])
            out.append("")
            out.extend(body(m["doc"], 6))
        else:
            params = re.sub(r"\\Cefloom\\(?:Types\\)?", "", m["params"])
            ret = re.sub(r"\\Cefloom\\(?:Types\\)?", "", m["returns"])
            directive = "php:staticmethod" if m["static"] else "php:method"
            out.append("   .. %s:: %s(%s)" % (directive, m["name"], params))
            out.append("")
            if ret:
                out.append("      :returns: ``%s``" % ret)
                out.append("")
            out.extend(body(m["doc"], 6))
    out.append("")
    return out


def main():
    root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    stub = sys.argv[1] if len(sys.argv) > 1 else os.path.join(root, "php", "cefloom.stub.php")
    target = sys.argv[2] if len(sys.argv) > 2 else os.path.join(root, "docs", "api")
    classes = parse(stub)
    pages = {key: [] for key, _, _ in PAGES}
    for cls in classes:
        pages[page_of(cls)].append(cls)
    os.makedirs(target, exist_ok=True)
    for key, title, lead in PAGES:
        lines = [title, "=" * len(title), "", lead, "", ".. php:namespace:: Cefloom" if key != "types" else ".. php:namespace:: Cefloom\\Types", ""]
        for cls in sorted(pages[key], key=lambda c: c["name"]):
            lines.extend(render_class(cls))
        with open(os.path.join(target, "gen-%s.rst" % key), "w", encoding="utf-8") as fh:
            fh.write("\n".join(lines).rstrip("\n") + "\n")
    print("%d classes into %d pages in %s" % (len(classes), len(PAGES), target))


if __name__ == "__main__":
    main()
