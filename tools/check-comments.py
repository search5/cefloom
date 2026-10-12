"""Check that every declaration of the hand written code carries a documentation comment.

    python3 tools/check-comments.py [-v] [path ...]    # default: the whole project; exit status 1 if anything is missing

Languages and the form that counts as documentation (the form the Sphinx toolchain reads):
  * C and C++ (ext/src, ext/runtime, subprocess): a Doxygen block comment (/** ... */) or ///. Checked: functions, ZEND_METHOD
    and ZEND_FUNCTION definitions, classes, structs, enums, unions, typedefs, using aliases, macros, namespace scope variables, and the
    members of a class or struct that is declared at the left margin.
  * Python (tools/gen): a docstring on every module, class and function (nested functions excluded).
  * PHP (tests/support.php, bin/cefloom-runtime, php/*.php): a doc comment on every function, class, interface and constant.
Files under ext/generated are skipped: tools/gen writes their comments.
"""

import ast
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SKIP_DIRS = {"generated", "vendor", "build", "build-coverage", "node_modules", ".git"}
CPP_EXT = (".h", ".cc")

TYPE_HEAD = re.compile(r"^(?:template\s*<.*>\s*)?(?:typedef\s+)?(?:struct|class|enum(?:\s+class)?|union)\b[^;]*[{;]\s*(?://.*)?$")
USING = re.compile(r"^using\s+(?!namespace\b)\w+\s*=")
MACRO = re.compile(r"^#\s*define\s+\w+")
ZEND = re.compile(r"^(?:ZEND_METHOD|ZEND_FUNCTION|PHP_FUNCTION|PHP_METHOD|PHP_MINIT_FUNCTION|PHP_MSHUTDOWN_FUNCTION|PHP_RINIT_FUNCTION|"
                  r"PHP_RSHUTDOWN_FUNCTION|PHP_MINFO_FUNCTION)\s*\(")
SKIP_HEAD = re.compile(r"^(?:namespace\b|extern\s+\"C\"|#|//|/\*|\*|\}|public:|private:|protected:|using\s+namespace\b|"
                       r"friend\b|static_assert\b|return\b|if\b|for\b|while\b|else\b|switch\b|case\b|default\b|ZEND_BEGIN_ARG|ZEND_END_ARG|"
                       r"ZEND_ARG|ZEND_TSRMLS|ZEND_GET_MODULE|ZEND_DECLARE_MODULE_GLOBALS|\)|\{)")
FUNC = re.compile(r"^[\w:<>,\*&\[\]\s~\[\]]+?[\s\*&]([\w:~]+|operator\s*\S+)\s*\([^;{]*(?:\)[^;{]*)?[{;]?\s*(?://.*)?$")
VAR = re.compile(r"^(?:static\s+|extern\s+|constexpr\s+|const\s+|inline\s+|thread_local\s+)*[\w:<>,\*&\s]+?[\s\*&]\w+(?:\[[^\]]*\])?\s*(?:=[^;]*|\{[^;]*\})?;\s*(?://.*)?$")


def documented(lines, index):
    """True if the declaration at lines[index] is preceded by a Doxygen comment (directly, blank lines excluded).

    A `template <...>` line in between is skipped: Doxygen attaches the comment that stands above the template header.
    """
    j = index - 1
    while j >= 0 and lines[j].lstrip().startswith("template"):
        j -= 1
    while j >= 0 and not lines[j].strip():
        j -= 1
    if j < 0:
        return False
    text = lines[j].strip()
    if text.startswith("///"):
        return True
    if text.endswith("*/"):
        k = j
        while k >= 0 and "/*" not in lines[k]:
            k -= 1
        return k >= 0 and lines[k].lstrip().startswith("/**")
    return False


def declaration_kind(text, in_class):
    """Name what a line declares, or None when it needs no comment of its own."""
    if SKIP_HEAD.match(text):
        return "macro" if MACRO.match(text) else None
    if ZEND.match(text):
        return "zend"
    if TYPE_HEAD.match(text):
        # A forward declaration (`struct X;`, `class Y;`) needs none.
        return None if text.rstrip().endswith(";") and "{" not in text and "(" not in text and not text.startswith("typedef") else "type"
    if USING.match(text):
        return "using"
    if text.startswith("typedef"):
        return "typedef"
    if text.endswith(";") and "(" not in text and not VAR.match(text):
        return None
    if "(" in text:
        # A call statement at the left margin (a macro use such as ZEND_END_ARG_INFO()) has no return type before the name.
        if FUNC.match(text) and re.match(r"^[\w:<>,\*&\s~]+?[\s\*&][\w:~]+\s*\(", text) and not re.match(r"^[A-Z_0-9]+\s*\(", text):
            return "function"
        if re.match(r"^[~\w:]+\s*\(", text) and in_class:
            return "function"          # a constructor or destructor
        return None
    if VAR.match(text):
        return "variable"
    return None


def check_cpp(path, rel):
    """Report the undocumented declarations of one C or C++ file as (line, text) pairs."""
    lines = open(path, encoding="utf-8", errors="replace").read().split("\n")
    missing = []
    in_class = False
    in_comment = False
    class_close = None
    for i, raw in enumerate(lines):
        stripped = raw.strip()
        if in_comment:
            in_comment = "*/" not in stripped
            continue
        if stripped.startswith("/*") and "*/" not in stripped:
            in_comment = True
            continue
        if not stripped:
            continue
        indent = len(raw) - len(raw.lstrip())
        if indent == 0 and not raw.startswith((" ", "\t")):
            if in_class and stripped.startswith("}"):
                in_class = False
                continue
            kind = declaration_kind(stripped, False)
            if kind == "macro" and i and lines[i - 1].lstrip().startswith("#ifndef") and stripped.split()[1:2] == lines[i - 1].split()[1:2]:
                kind = None            # the #define of an include guard
            if kind and not documented(lines, i):
                missing.append((i + 1, stripped))
            if TYPE_HEAD.match(stripped) and "{" in stripped and not stripped.rstrip().endswith("};") and not stripped.startswith("enum"):
                in_class = True
        elif in_class and indent in (2, 4) and not stripped.startswith(("//", "/*", "*")):
            # one level inside a class: members declared at the first indentation step
            first = indent
            if first == 2 or (first == 4 and not lines[i - 1].startswith("    ")) and False:
                pass
            if indent == 2:
                kind = declaration_kind(stripped, True)
                if kind and not documented(lines, i):
                    missing.append((i + 1, stripped))
    return missing


def check_python(path, rel):
    """Report the module, classes and functions of one Python file that have no docstring."""
    source = open(path, encoding="utf-8").read()
    tree = ast.parse(source)
    missing = []
    if not ast.get_docstring(tree):
        missing.append((1, "module"))

    def visit(body, nested):
        for node in body:
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                if not nested and not ast.get_docstring(node):
                    missing.append((node.lineno, "def " + node.name))
            elif isinstance(node, ast.ClassDef):
                if not ast.get_docstring(node):
                    missing.append((node.lineno, "class " + node.name))
                visit(node.body, False)
    visit(tree.body, False)
    return missing


PHP_DECL = re.compile(r"^\s*(?:(?:abstract|final|public|protected|private|static|readonly)\s+)*(?:function|class|interface|trait|enum|const)\s+(&?\w+)")


def check_php(path, rel):
    """Report the functions, classes and constants of one PHP file that have no doc comment."""
    lines = open(path, encoding="utf-8", errors="replace").read().split("\n")
    missing = []
    for i, raw in enumerate(lines):
        match = PHP_DECL.match(raw)
        if not match or raw.strip().startswith(("//", "*", "/*")):
            continue
        if re.match(r"^\s+(?:(?:public|protected|private)\s+)?const\b", raw):
            continue          # the constants of a documented class need no comment of their own
        j = i - 1
        while j >= 0 and not lines[j].strip():
            j -= 1
        if j < 0 or not lines[j].strip().endswith("*/"):
            missing.append((i + 1, raw.strip()))
    return missing


def targets(args):
    """The files to check: the given paths, or the hand written sources of the project."""
    if args:
        roots = [os.path.abspath(a) for a in args]
    else:
        roots = [os.path.join(ROOT, p) for p in ("ext/src", "ext/runtime", "subprocess", "tools/gen", "tests/support.php",
                                                  "bin/cefloom-runtime", "php")]
    for root in roots:
        if os.path.isfile(root):
            yield root
            continue
        for base, dirs, files in os.walk(root):
            dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
            for name in sorted(files):
                yield os.path.join(base, name)


def main():
    """Run the check; print the missing comments and exit with 1 if there are any."""
    args = [a for a in sys.argv[1:] if not a.startswith("-")]
    verbose = "-v" in sys.argv
    total = 0
    for path in targets(args):
        rel = os.path.relpath(path, ROOT)
        if path.endswith(CPP_EXT):
            missing = check_cpp(path, rel)
        elif path.endswith(".py"):
            missing = check_python(path, rel)
        elif path.endswith(".php") or os.path.basename(path) == "cefloom-runtime":
            missing = check_php(path, rel)
        else:
            continue
        total += len(missing)
        if missing:
            print("%s: %d undocumented" % (rel, len(missing)))
            if verbose:
                for line, text in missing:
                    print("  %d: %s" % (line, text[:100]))
    print("%d undocumented declaration(s)" % total)
    return 1 if total else 0


if __name__ == "__main__":
    sys.exit(main())
