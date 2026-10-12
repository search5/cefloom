"""Read the CEF headers into a model the generators work from.

The parsing itself is done by CEF's own header parser (vendor/cef_parser.py), the
same one CEF uses to generate its C API wrappers. This module adds what the
generators need on top of it: PHP names, enumeration detection, value struct detection
and pure virtual detection.

Pipeline role: first stage. ``generate.py`` builds one :class:`Model`; ``scope`` selects
from it, ``typesys`` plans the methods against it and the emitters read its structs,
enumerations and comments.

Origin: adapted from tools/gen/model.py of the cefweaver checkout (../cefweaver_clone/cefweaver).
"""

import ast
import keyword
import os
import re
import sys
from dataclasses import dataclass, replace

VENDOR_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "vendor")
if VENDOR_DIR not in sys.path:
    sys.path.insert(0, VENDOR_DIR)

import cef_parser  # noqa: E402  (vendored, see vendor/README.txt)


# -- names ------------------------------------------------------------------------


# The CEF API version the wrapper is built with: CEF_API_VERSION is CEF_API_VERSION_EXPERIMENTAL
# (internal/cef_api_versions.h) unless the build defines it, and cefweaver does not. A method with
# `added=N` or `removed=N` in its comment exists only at some versions (`#if CEF_API_REMOVED(15000)`): a
# method that does not exist at this version is not in the headers the C++ compiler sees.
API_VERSION = 999999


def _exists_at_api_version(func):
    """True if a function exists at API_VERSION: not `added` after it and not `removed` at or before it."""
    if hasattr(func, "exists_at_version"):
        return func.exists_at_version(API_VERSION)
    attribs = func.attribs
    if cef_parser._has_version_added(attribs) and cef_parser._get_version_added(attribs) > API_VERSION:
        return False
    return not (cef_parser._has_version_removed(attribs) and cef_parser._get_version_removed(attribs) <= API_VERSION)


def snake_case(name):
    """GetURL -> get_url, OnLoadEnd -> on_load_end, HTTPStatus -> http_status."""
    name = re.sub(r"(.)([A-Z][a-z]+)", r"\1_\2", name)
    name = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", name)
    return name.lower()


def _safe(name):
    """Return ``name`` unchanged (PHP allows reserved words as method and parameter names). Not called."""
    return name


def py_class_name(cef_name):
    """CefResourceHandler -> ResourceHandler."""
    return cef_name[3:] if cef_name.startswith("Cef") else cef_name


def py_method_name(cef_name):
    """GetMainFrame -> getMainFrame, GetURL -> getURL (the CEF name with a lower case first letter)."""
    return cef_name[:1].lower() + cef_name[1:]


def py_param_name(cef_name):
    """popup_id -> popupId, url -> url (camelCase, the PHP convention)."""
    parts = cef_name.split("_")
    return parts[0] + "".join(p[:1].upper() + p[1:] for p in parts[1:])


# -- value type structs -----------------------------------------------------------------

# The field types a struct may have to be handled as plain data: C type -> Python type.
_FIELD_TYPES = {
    "bool": "bool", "int": "int", "int8_t": "int", "uint8_t": "int", "int16_t": "int", "uint16_t": "int", "int32_t": "int",
    "uint32_t": "int", "int64_t": "int", "uint64_t": "int", "float": "float", "double": "float",
    "cef_color_t": "int", "char16_t": "int", "cef_window_handle_t": "int",
}


@dataclass(frozen=True)
class StructField:
    """One member of a value struct: its C member, C type and the ``py`` annotation the emitters read.

    The flags (``enum``, ``string``, ``time``, ``array``) are mutually exclusive and select the conversion the
    emitters generate.
    """
    cname: str  # the member of the C struct (may be a Python keyword)
    name: str  # camelCase name (py_param_name), the PHP property
    cpp: str  # the C type
    py: str  # annotation: int, bool or float, or the class of a nested struct
    struct: str = ""  # the C++ class of a nested struct (CefRect in CefDraggableRegion), else ""
    enum: bool = False  # `py` is the Python enumeration of the member (`cpp` is its C name)
    string: bool = False  # a cef_string_t member (str)
    time: bool = False  # a cef_basetime_t member (a datetime)
    array: int = 0  # a C array of this many structs (`struct` is the element): a tuple in Python
    count: str = ""  # the member that tells how many elements of the array are valid (not a field)


@dataclass(frozen=True)
class StructInfo:
    """A plain-data struct CEF passes by value (``CefRect``) and the fields it exposes to PHP.

    ``raw`` marks a C struct without a C++ class.
    """
    cls: str  # the C++ class CEF uses in signatures, e.g. CefRect
    cname: str  # the C struct it derives from, e.g. cef_rect_t
    fields: tuple
    raw: bool = False  # a plain C struct that has no C++ class (`cls` is a name given to it, see RAW_STRUCTS)


def _strip_comments(text):
    """Return ``text`` without C block and line comments."""
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return re.sub(r"//[^\n]*", "", text)


# Structs that are not value types of the API.
INIT_ONLY_STRUCTS = ()

# Plain C structs without a C++ class that are members of structs the API passes: the name
# the Python class gets (as if CEF had a C++ class) and the C struct.
RAW_STRUCTS = {
    "CefAcceleratedPaintNativePixmapPlane": "cef_accelerated_paint_native_pixmap_plane_t",
    "CefAcceleratedPaintInfoCommon": "cef_accelerated_paint_info_common_t",
}


# Structs that CEF defines differently on each platform (include/internal/cef_types_linux.h,
# _mac.h, _win.h; a CEF distribution holds the header of its own platform only). The binding is
# the same everywhere, so these are read in their Linux form whatever the distribution is, and
# the C++ code reaches them through the platform neutral type of native/cefwrapper/
# platform_structs.h (Cython and the proxies use PLATFORM_STRUCTS' names, not CEF's).
LINUX_STRUCT_BODIES = {
    "cef_accelerated_paint_native_pixmap_plane_t": """
  uint32_t stride;
  uint64_t offset;
  uint64_t size;
  int fd;
""",
    "cef_accelerated_paint_info_t": """
  size_t size;
  cef_accelerated_paint_native_pixmap_plane_t
      planes[kAcceleratedPaintMaxPlanes];
  int plane_count;
  uint64_t modifier;
  cef_color_type_t format;
  cef_accelerated_paint_info_common_t extra;
""",
}
LINUX_STRUCT_CONSTANTS = {"kAcceleratedPaintMaxPlanes": 4}

# CEF class -> the platform neutral C++ type the bindings use for it.
PLATFORM_STRUCTS = {}


def cpp_struct_name(cls):
    """The C++ type of the struct class `cls` in the generated code."""
    return PLATFORM_STRUCTS.get(cls, cls)


def _drop_conditionals(body):
    """The body without the lines inside `#if ... #endif` blocks (the `#else` branch too) and without any line that
    starts with `#`."""
    kept = []
    depth = 0
    for line in body.split("\n"):
        stripped = line.strip()
        if stripped.startswith("#if"):
            depth += 1
        elif stripped.startswith("#endif"):
            depth -= 1
        elif depth == 0 and not stripped.startswith("#"):
            kept.append(line)
    return "\n".join(kept)


def parse_struct_fields(body, nested=None, enums=None, constants=None):
    """Return the tuple of :class:`StructField` of a C struct body, or None if any member is not plain data or
    there are no fields.

    Plain data means a primitive type per member, ``cef_string_t``, ``cef_basetime_t``, an enumeration (`enums` maps
    the C name to the Python class name), or another plain struct (`nested` maps the C name `cef_rect_t` to its C++
    class `CefRect`); an array of nested structs with a size that is a number or a name in `constants` is allowed
    too; no pointers. A leading `size_t size` is the version header of the C API, not a field. `#if` blocks are
    ignored. For an array `planes` (or `foo`) the int member `plane_count` (or `foo_count`), if present, is dropped
    from the fields and named in `StructField.count`.
    """
    nested = nested or {}
    enums = enums or {}
    constants = constants or {}
    fields = []
    for index, statement in enumerate(s for s in _drop_conditionals(_strip_comments(body)).split(";")
                                      if s.strip()):
        statement = " ".join(statement.split())
        array = re.match(r"^([A-Za-z_][\w ]*?) (\w+) ?\[ ?(\w+) ?\]$", statement)
        if array:  # an array of structs: `cef_x_t planes[kMaxPlanes]`
            ctype, cname, size = array.groups()
            length = int(size) if size.isdigit() else constants.get(size)
            if ctype not in nested or not length:
                return None
            fields.append(StructField(cname, py_param_name(cname), ctype,
                                      "tuple[%s, ...]" % py_class_name(nested[ctype]), nested[ctype],
                                      array=length))
            continue
        found = re.match(r"^([A-Za-z_][\w ]*?) (\w+)$", statement)
        if not found:
            return None
        if found.group(2) == "size" and found.group(1) == "size_t":
            if index == 0:
                continue  # the version header
            return None
        ctype, cname = found.groups()
        if ctype == "cef_string_t":
            fields.append(StructField(cname, py_param_name(cname), ctype, "str", "", False, True))
        elif ctype == "cef_basetime_t":
            fields.append(StructField(cname, py_param_name(cname), ctype,
                                      "datetime.datetime | None", "", False, False, True))
        elif ctype in enums:
            fields.append(StructField(cname, py_param_name(cname), ctype, enums[ctype], "", True))
        elif ctype in _FIELD_TYPES:
            fields.append(StructField(cname, py_param_name(cname), ctype, _FIELD_TYPES[ctype]))
        elif ctype in nested:
            fields.append(StructField(cname, py_param_name(cname), ctype,
                                      py_class_name(nested[ctype]), nested[ctype]))
        else:
            return None
    # `planes[4]` with `plane_count`: the count says how many are valid and is not a field
    for index, f in enumerate(list(fields)):
        if f.array:
            count = f.cname[:-1] + "_count" if f.cname.endswith("s") else f.cname + "_count"
            if any(g.cname == count and g.cpp == "int" for g in fields):
                fields[fields.index(f)] = replace(f, count=count)
                fields = [g for g in fields if g.cname != count]
    return tuple(fields) or None


# -- enumerations ---------------------------------------------------------------------


@dataclass(frozen=True)
class EnumInfo:
    """An enumeration read from the C headers.

    ``members`` holds ``(name, value)`` pairs, the name without the prefix the enumerators share; ``doc`` is the
    header comment above the enum joined into one line.
    """
    cname: str  # the C enumeration, e.g. cef_mouse_button_type_t
    py_name: str  # the Python class, e.g. MouseButtonType
    members: tuple  # of (Python name, value)
    flag: bool  # bit flags (an IntFlag in Python)
    doc: str = ""


class EnumUnsupported(Exception):
    """An enumeration the generator cannot read; the message says why."""


_C_CONSTANTS = {"UINT_MAX": 0xFFFFFFFF, "INT_MAX": 0x7FFFFFFF}


def _strip_c_comments(text):
    """Return ``text`` without C block and line comments."""
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return re.sub(r"//[^\n]*", "", text)


def _condition(expression):
    """Return True for the `#if` conditions ``CEF_API_ADDED(...)`` and ``!defined(GENERATING_CEF_API_HASH)``; raise
    :class:`EnumUnsupported` for any other."""
    expression = expression.strip()
    if re.fullmatch(r"CEF_API_ADDED\([^)]*\)", expression):
        return True
    if expression == "!defined(GENERATING_CEF_API_HASH)":
        return True
    raise EnumUnsupported("preprocessor condition %s" % expression)


def _active_lines(body, cef_root):
    """The lines of an enum body that are in effect: `#if` branches selected by :func:`_condition`, the net error
    list (`#define NET_ERROR(label, value) ERR_##label = value,` + `#include`) expanded. Raise
    :class:`EnumUnsupported` for any other directive except `#undef`."""
    out, stack, macro = [], [], None
    for raw in _strip_c_comments(body).splitlines():
        line = raw.strip()
        if not line:
            continue
        if not line.startswith("#"):
            if all(active for active, _ in stack):
                out.append(line)
            continue
        directive, _, rest = line[1:].strip().partition(" ")
        if directive == "if":
            parent = all(active for active, _ in stack)
            value = parent and _condition(rest)
            stack.append((value, value))
        elif directive == "elif":
            was_taken = stack[-1][1]
            parent = all(active for active, _ in stack[:-1])
            value = (not was_taken) and parent and _condition(rest)
            stack[-1] = (value, was_taken or value)
        elif directive == "else":
            was_taken = stack[-1][1]
            parent = all(active for active, _ in stack[:-1])
            stack[-1] = ((not was_taken) and parent, True)
        elif directive == "endif":
            stack.pop()
        elif directive == "define":
            found = re.match(r"(\w+)\(label, value\)\s+(\w+)##label\s*=\s*value,", rest)
            if found:
                macro = (found.group(1), found.group(2))
        elif directive == "include" and macro and all(active for active, _ in stack):
            path = os.path.join(cef_root, re.search(r'"([^"]+)"', rest).group(1))
            with open(path, encoding="utf-8") as f:
                for entry in re.finditer(r"^%s\((\w+),\s*(-?\w+)\)" % macro[0], f.read(), re.M):
                    out.append("%s%s = %s," % (macro[1], entry.group(1), entry.group(2)))
        elif directive in ("undef",):
            macro = None
        else:
            raise EnumUnsupported("preprocessor directive #%s" % directive)
    return out


def _evaluate(expression, known):
    """The value of an enumerator expression (Python integer arithmetic): integers (with u/l suffixes), names of
    earlier enumerators, UINT_MAX, INT_MAX, unary - ~ +, and the binary operators << >> | & + -. Raise
    :class:`EnumUnsupported` otherwise."""
    expression = re.sub(r"\b(0[xX][0-9a-fA-F]+|\d+)[uUlL]+\b", r"\1", expression)

    def walk(node):
        if isinstance(node, ast.Constant) and isinstance(node.value, int):
            return node.value
        if isinstance(node, ast.Name):
            if node.id in known:
                return known[node.id]
            if node.id in _C_CONSTANTS:
                return _C_CONSTANTS[node.id]
            raise EnumUnsupported("unknown name %s" % node.id)
        if isinstance(node, ast.UnaryOp):
            value = walk(node.operand)
            if isinstance(node.op, ast.USub):
                return -value
            if isinstance(node.op, ast.Invert):
                return ~value
            if isinstance(node.op, ast.UAdd):
                return value
        if isinstance(node, ast.BinOp):
            left, right = walk(node.left), walk(node.right)
            operations = {ast.LShift: lambda a, b: a << b, ast.RShift: lambda a, b: a >> b,
                          ast.BitOr: lambda a, b: a | b, ast.BitAnd: lambda a, b: a & b,
                          ast.Add: lambda a, b: a + b, ast.Sub: lambda a, b: a - b}
            if type(node.op) in operations:
                return operations[type(node.op)](left, right)
        raise EnumUnsupported("expression %s" % expression)

    try:
        return walk(ast.parse(expression, mode="eval").body)
    except SyntaxError:
        raise EnumUnsupported("expression %s" % expression) from None


def parse_enum(body, cef_root):
    """Return ``[(C name, value)]`` of an enum body (an enumerator without ``=`` is the previous value plus one,
    starting at 0). Raise :class:`EnumUnsupported` for an unreadable body or no enumerators."""
    members, known, value = [], {}, -1
    text = " ".join(_active_lines(body, cef_root))
    for item in (i.strip() for i in text.split(",")):
        if not item:
            continue
        name, equals, expression = (p.strip() for p in item.partition("="))
        if not re.fullmatch(r"[A-Za-z_]\w*", name):
            raise EnumUnsupported("enumerator %r" % item)
        value = _evaluate(expression, known) if equals else value + 1
        known[name] = value
        members.append((name, value))
    if not members:
        raise EnumUnsupported("no enumerators")
    return members


def _python_member_names(cnames):
    """Return the names without the underscore-separated prefix all share (`MBT_LEFT` -> `LEFT`), keeping at least
    one token per name; a name that starts with a digit gets a leading `_`; the C names unchanged if stripping would
    make two names equal."""
    tokens = [name.split("_") for name in cnames]
    shared = 0
    while all(len(t) > shared + 1 and t[shared] == tokens[0][shared] for t in tokens):
        shared += 1
    names = ["_".join(t[shared:]) for t in tokens]
    names = [n if not n[0].isdigit() else "_" + n for n in names]
    if len(set(names)) != len(names):
        return list(cnames)  # stripping would merge two enumerators
    return names


def _camel_case(cname):
    """Return the CamelCase form of a C type name (``cef_mouse_button_type_t`` -> ``MouseButtonType``)."""
    core = re.sub(r"^cef_|_t$", "", cname)
    return "".join(part.capitalize() for part in core.split("_"))


def _is_flag(cname, values):
    """Return True if the enumerator ``values`` are bit flags: for a name ending ``_flags_t`` or ``_mask_t`` at least
    two single-bit values; otherwise at least three single-bit values and no other non-zero value."""
    single = [v for v in values if v > 0 and v & (v - 1) == 0]
    if cname.endswith(("_flags_t", "_mask_t")):
        return len(single) >= 2
    nonzero = [v for v in values if v != 0]
    return len(single) >= 3 and len(single) == len(nonzero)


# -- the model -----------------------------------------------------------------------


class Model:
    """The parsed headers of one CEF distribution."""

    def __init__(self, cef_root):
        """Parse the headers under ``cef_root`` (a CEF distribution directory) with CEF's own parser.

        :param cef_root: directory that contains ``include/``.
        """
        self.cef_root = os.path.abspath(cef_root)
        self._sources = {}
        header = cef_parser.obj_header()
        header.set_root_directory(self.cef_root)
        for sub in ("include", "include/test", "include/views"):
            path = os.path.join(self.cef_root, sub)
            if os.path.isdir(path):
                header.add_directory(path)
        self.header = header
        self.classes = {c.get_name(): c for c in header.get_classes()}
        self.functions = {f.get_name(): f for f in header.get_funcs()}
        self.enums = self._find_enums()
        self.enum_aliases = self._find_enum_aliases()
        self.enum_defs, self.enum_skipped = self._read_enums()
        self.structs = self._find_structs()

    def _read(self, path):
        """Return the text of ``path``, reading each file once."""
        if path not in self._sources:
            with open(path, encoding="utf-8") as f:
                self._sources[path] = f.read()
        return self._sources[path]

    def _find_enums(self):
        """Return the set of names of the C enumerations (`typedef enum { ... } cef_x_t;`) in all headers under include/."""
        names = set()
        include = os.path.join(self.cef_root, "include")
        for dirpath, _, files in os.walk(include):
            for filename in files:
                if not filename.endswith(".h"):
                    continue
                text = self._read(os.path.join(dirpath, filename))
                for match in re.finditer(r"typedef\s+enum\s*\w*\s*\{.*?\}\s*(\w+)\s*;", text, re.S):
                    names.add(match.group(1))
        return names

    def _find_enum_aliases(self):
        """Return ``{CefThreadId: cef_thread_id_t}`` from the lines `typedef cef_thread_id_t CefThreadId;` whose C name is an enumeration."""
        aliases = {}
        include = os.path.join(self.cef_root, "include")
        for dirpath, _, files in os.walk(include):
            for filename in files:
                if filename.endswith(".h"):
                    for cname, alias in re.findall(r"^typedef\s+(cef_\w+_t)\s+(Cef\w+)\s*;",
                                                   self._read(os.path.join(dirpath, filename)), re.M):
                        if cname in self.enums:
                            aliases[alias] = cname
        return aliases

    def _read_enums(self):
        """Return ``(defs, skipped)``: ``defs`` maps C names to :class:`EnumInfo` for every enumeration that can be read,
        ``skipped`` maps the C names of the others to the reason."""
        include = os.path.join(self.cef_root, "include")
        aliases = {}  # C name -> the name the C++ headers give it (`ErrorCode`)
        found = {}
        for dirpath, _, files in sorted(os.walk(include)):
            for filename in sorted(files):
                if not filename.endswith(".h"):
                    continue
                text = self._read(os.path.join(dirpath, filename))
                for match in re.finditer(r"^\s*typedef\s+(cef_\w+_t)\s+(\w+)\s*;", text, re.M):
                    aliases.setdefault(match.group(1), match.group(2))
                for match in re.finditer(r"((?:^[ \t]*///[^\n]*\n)*)[ \t]*typedef\s+enum\s*\w*\s*\{(.*?)\}\s*(\w+)\s*;",
                                         text, re.S | re.M):
                    found[match.group(3)] = (match.group(2), match.group(1))
        defs, skipped, used_names = {}, {}, set()
        for cname, (body, comment) in sorted(found.items()):
            try:
                members = parse_enum(body, self.cef_root)
            except EnumUnsupported as reason:
                skipped[cname] = str(reason)
                continue
            # The C++ spelling (`ErrorCode`) is used where it only differs from the one made of
            # the C name (`Errorcode`) in capitals. An alias that is declared inside a class
            # (`TypeFlags` of CefContextMenuParams) means little without the class.
            py_name = _camel_case(cname)
            alias = py_class_name(aliases.get(cname, ""))
            if alias and alias.lower() == py_name.lower() and alias not in used_names:
                py_name = alias
            used_names.add(py_name)
            names = _python_member_names([n for n, _ in members])
            values = [v for _, v in members]
            doc = " ".join(l.strip().lstrip("/").strip() for l in comment.splitlines()).strip()
            defs[cname] = EnumInfo(cname, py_name, tuple(zip(names, values)),
                                   _is_flag(cname, values), doc)
        return defs, skipped

    def _find_structs(self):
        """Return ``{C++ class name: StructInfo}``, sorted by name, of the plain data structs CEF passes by value
        (`class CefRect : public cef_rect_t`, `CefStructBase` and `CefStructBaseSimple` forms, and ``RAW_STRUCTS``);
        empty if include/internal/cef_types_wrappers.h is missing."""
        internal = os.path.join(self.cef_root, "include", "internal")
        wrappers = os.path.join(internal, "cef_types_wrappers.h")
        if not os.path.isfile(wrappers):
            return {}
        bodies, constants = {}, {}
        # Some structs differ by platform (cef_accelerated_paint_info_t): the later definition
        # wins and Linux, the platform of this binding, comes last.
        filenames = sorted(f for f in os.listdir(internal) if f.endswith(".h"))
        filenames.sort(key=lambda f: f.endswith("_linux.h"))
        has_linux = any(f.endswith("_linux.h") for f in filenames)
        for filename in filenames:
            text = self._read(os.path.join(internal, filename))
            for match in re.finditer(r"typedef\s+struct\s+_\w+\s*\{(.*?)\}\s*(\w+)\s*;", text, re.S):
                bodies[match.group(2)] = match.group(1)
            constants.update((n, int(v)) for n, v in re.findall(r"^#define\s+(k\w+)\s+(\d+)\s*$", text, re.M))
        if not has_linux:  # a distribution of another platform: the Linux form is built in
            bodies.update(LINUX_STRUCT_BODIES)
            constants.update(LINUX_STRUCT_CONSTANTS)
        # CefWindowInfo is declared in the header of the platform (cef_linux.h), not in cef_types_wrappers.h.
        platform_text = ""
        for name in ("cef_linux.h", "cef_mac.h", "cef_win.h"):
            platform = os.path.join(internal, name)
            if os.path.isfile(platform):
                platform_text += self._read(platform)
        # `class CefRect : public cef_rect_t {`, and the ones with a size header:
        # `class CefScreenInfo : public CefStructBaseSimple<cef_screen_info_t> {` and
        # `using CefKeyEvent = CefStructBaseSimple<cef_key_event_t>;`
        text = self._read(wrappers) + "\n" + platform_text
        classes = dict((c, cn) for c, cn in re.findall(
            r"\bclass\s+(Cef\w+)\s*:\s*public\s+(?:CefStructBaseSimple<\s*)?(cef_\w+_t)\s*>?\s*\{", text))
        classes.update((c, cn) for c, cn in re.findall(
            r"\busing\s+(Cef\w+)\s*=\s*CefStructBaseSimple<\s*(cef_\w+_t)\s*>\s*;", text))
        # The ones with strings: `using CefCookie = CefStructBase<CefCookieTraits>;` and
        # `struct CefCookieTraits { using struct_type = cef_cookie_t; ...`
        traits = dict(re.findall(r"\bstruct\s+(Cef\w+Traits)\s*\{\s*using\s+struct_type\s*=\s*(cef_\w+_t)\s*;", text))
        traits.update(re.findall(r"\bstruct\s+(Cef\w+Traits)\s*\{\s*typedef\s+(cef_\w+_t)\s+struct_type\s*;", text))
        for c, tr in re.findall(r"\busing\s+(Cef\w+)\s*=\s*CefStructBase<\s*(Cef\w+Traits)\s*>\s*;", text):
            if tr in traits:
                classes[c] = traits[tr]
        # `class CefWindowInfo : public CefStructBase<CefWindowInfoTraits> {`
        for c, tr in re.findall(r"\bclass\s+(Cef\w+)\s*:\s*public\s+CefStructBase<\s*(Cef\w+Traits)\s*>\s*\{", text):
            if tr in traits:
                classes[c] = traits[tr]
        # C structs that have no C++ class but are members of one that has (the planes of a shared
        # texture): read like the others, flagged `raw`.
        raw = {c: cn for c, cn in RAW_STRUCTS.items() if cn in bodies}
        classes.update(raw)
        # CefSettings only starts CEF (cefweaver.Settings is its Python form); no method takes it.
        for name in INIT_ONLY_STRUCTS:
            classes.pop(name, None)
        enum_names = {cname: info.py_name for cname, info in self.enum_defs.items()}
        by_cname = {cn: c for c, cn in classes.items()}
        structs = {}
        # A struct may contain another one (CefDraggableRegion has a CefRect), which has to be
        # read first: repeat until nothing more is added.
        progress = True
        while progress:
            progress = False
            for cls, cname in classes.items():
                if cls in structs or cname not in bodies:
                    continue
                known = {cn: c for cn, c in by_cname.items() if c in structs}
                fields = parse_struct_fields(bodies[cname], known, enum_names, constants)
                if fields:
                    structs[cls] = StructInfo(cls, cname, fields, cls in raw)
                    progress = True
        return dict(sorted(structs.items()))

    def virtual_funcs(self, cls, own_only=False):
        """The virtual methods of a class that exist at ``API_VERSION``, those of its CEF parents (recursively) first,
        or with ``own_only`` only its own (for a class that is a PHP subclass of its parent: Scope.python_parent)."""
        parent = cls.get_parent_name() if hasattr(cls, "get_parent_name") else None
        inherited = []
        if parent in self.classes and not own_only:
            inherited = list(self.virtual_funcs(self.classes[parent]))
        return inherited + [f for f in cls.get_virtual_funcs() if f.exists_at_version(API_VERSION)]

    def static_funcs(self, cls):
        """The static functions of a class that exist at ``API_VERSION`` (decided by :func:`_exists_at_api_version`)."""
        return [f for f in cls.get_static_funcs() if _exists_at_api_version(f)]

    def header_path(self, cls):
        """Return the header of ``cls`` as written in an #include line (`include/cef_x.h`)."""
        return "include/" + cls.get_file_name().replace("\\", "/").split("include/")[-1]

    def is_pure_virtual(self, cls, method):
        """Return True if the header declares ``method`` of ``cls`` as `virtual ... Name(...) = 0;`; a method not
        declared in the class itself is looked up in its CEF parent. Raise RuntimeError if the class is not found in its header."""
        path = os.path.join(self.cef_root, self.header_path(cls))
        text = self._read(path)
        # Library-side classes are declared `class CEF_EXPORT Name`, client-side ones `class Name`.
        found = re.search(r"\bclass\s+(?:CEF_EXPORT\s+)?%s\b[^;{]*\{" % re.escape(cls.get_name()), text)
        if not found:
            raise RuntimeError("class %s not found in %s" % (cls.get_name(), path))
        start = found.start()
        end = text.find("\n};", start)
        body = text[start:end if end > 0 else len(text)]
        name = re.escape(method.get_name())
        if re.search(r"virtual\s+[^;{}]*?\b%s\s*\(" % name, body, re.S) is None:
            # Not declared here: the method is inherited (a delegate has the methods of its parents), and the
            # parent that declares it says whether it is pure (CefMenuButtonDelegate::OnButtonPressed).
            parent = cls.get_parent_name() if hasattr(cls, "get_parent_name") else None
            return parent in self.classes and self.is_pure_virtual(self.classes[parent], method)
        pattern = r"virtual\s+[^;{}]*?\b%s\s*\([^;{}]*?\)\s*(?:const\s*)?=\s*0\s*;" % name
        return re.search(pattern, body, re.S) is not None

    def comment(self, node):
        """The header comment of a class or method as a list of lines with the leading ``//`` removed; [] if the node has none."""
        try:
            lines = node.get_comment()
        except Exception:
            return []
        return [re.sub(r"^\s*/+\s?", "", line).rstrip() for line in lines]
