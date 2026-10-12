"""Type handling of the generator.

Every C++ type in a CEF method signature is classified into a *kind*. A kind is a
small description (what the type is, how it looks in PHP) that the emitters
turn into C++ and the PHP stub. Types the generator cannot handle are recorded
as ``MethodPlan.reason`` (raised as :class:`Unsupported`), and the coverage report lists them.

Pipeline role: third stage. ``plan_class`` and ``plan_method`` turn the methods selected by ``scope`` into
:class:`MethodPlan` objects that ``emit_lib``, ``emit_client`` and ``emit_stub`` render.

To support a new kind of type:
  1. add a `Kind` subclass here and recognise it in `classify()`;
  2. teach the emitters (emit_lib.py, emit_client.py, emit_stub.py) how to convert it.
The method planning below does not need to change.
"""

import re
from dataclasses import dataclass, field

from model import py_method_name, py_param_name


class Unsupported(Exception):
    """A type or construct that is not handled yet; the message says why."""


# -- kinds -------------------------------------------------------------------------


@dataclass(frozen=True)
class Kind:
    """Base class of the type kinds; a kind says how a C++ type crosses the PHP boundary."""
    pass


@dataclass(frozen=True)
class Void(Kind):
    """``void``: no value."""
    pass


@dataclass(frozen=True)
class Prim(Kind):
    """A number or ``bool`` that converts directly to a PHP scalar."""
    cpp: str  # the type as written in the header
    py: str  # "int", "bool" or "float"


@dataclass(frozen=True)
class Str(Kind):
    """CefString (UTF-16 inside CEF, a PHP string)."""


@dataclass(frozen=True)
class Enum(Kind):
    """A C enumeration, passed to PHP as an int."""
    spelled: str  # as written where it is used, e.g. TransitionType
    cname: str  # the C enumeration, e.g. cef_transition_type_t
    # EnumInfo.py_name, the class of the constants in Cefloom\Types ("" if the enumeration could not be read).
    py: str = field(default="", compare=False)


@dataclass(frozen=True)
class LibRef(Kind):
    """CefRefPtr<T> of an object implemented by CEF (a PHP object that wraps it)."""

    cls: str


@dataclass(frozen=True)
class ClientRef(Kind):
    """CefRefPtr<T> of an object implemented by the application (a PHP object; CEF gets a C++ proxy)."""

    cls: str


@dataclass(frozen=True)
class Struct(Kind):
    """A plain data struct passed by value (CefRect, CefPoint, ...): an object of the generated final PHP class."""

    cls: str  # the C++ class, e.g. CefRect
    fields: tuple  # of model.StructField
    raw: bool = False  # a C struct without a C++ class (cname is its name)
    cname: str = ""


@dataclass(frozen=True)
class Vector(Kind):
    """`std::vector<T>` (a PHP array). The element is a string, a number (not bool), a
    value type struct or an object implemented by CEF (not accepted as a parameter of a library method)."""

    element: Kind


@dataclass(frozen=True)
class Buffer(Kind):
    """`void* data, <integer> size` of a client method: a ``Cefloom\\Buffer`` (writable unless `readonly`). A `const void*` of
    which the header gives the size in a comment (OnPaint: width * height * 4 bytes) has no size
    parameter: `size_expr` is the C++ expression of its size in bytes, and the buffer is
    read-only."""

    size_cpp: str  # type of the size parameter
    size_expr: str = ""  # C++ size in bytes, when there is no size parameter
    readonly: bool = False


@dataclass(frozen=True)
class Planes(Kind):
    """`const float** data, int frames`: a PHP array of read-only ``Cefloom\\Buffer`` of `frames` floats, one for every
    channel. The number of channels is no argument of the call: an earlier call told it
    (OnAudioStreamStarted) and the proxy remembers it in the member `count_member`."""

    count_member: str


@dataclass(frozen=True)
class StrMap(Kind):
    """`std::multimap<CefString, CefString>` (a header map) or `std::map<CefString, CefString>`
    (the switches of a command line): a PHP array of strings. A key that
    occurs twice in a multimap keeps its last value (cl::ret_strmap)."""

    multi: bool


@dataclass(frozen=True)
class Time(Kind):
    """`CefBaseTime`: microseconds since 1601-01-01 (cl::ret_time). A ``DateTimeImmutable`` in PHP (null for 0);
    as an input also a DateTimeInterface, an int or a float of Unix seconds (cl::time_from)."""


@dataclass(frozen=True)
class Ignored(Kind):
    """A parameter of a handler method that is not given to PHP: a `CefEventHandle` (an XEvent* on Linux) or an
    entry of ``IGNORED_PARAMS``."""


@dataclass(frozen=True)
class Bytes(Kind):
    """`const void* data, size_t size` of a library method: a PHP string.
    With `out` on its ParamPlan it is `void* buffer, size_t buffer_size` that CEF fills: the
    PHP method takes the size and returns a string (the method's return value, the number of
    bytes written, only trims it)."""

    size_cpp: str  # type of the size parameter
    size_first: bool = False  # `size_t size, const void* bytes` (CefPostDataElement)


@dataclass(frozen=True)
class ItemBytes(Kind):
    """`void* ptr, size_t size, size_t n` of a library method (fread/fwrite): `n` items of
    `size` bytes. In PHP `write(string $data, int $size)` returns the items written,
    and `read(int $n, int $size)` returns the string read (`n * size` bytes at most)."""

    size_cpp: str


_PRIMITIVES = {
    "bool": "bool",
    "int": "int",
    "int16_t": "int",
    "uint16_t": "int",
    "int32_t": "int",
    "uint32_t": "int",
    "int64_t": "int",
    "uint64_t": "int",
    "size_t": "int",
    "long": "int",
    "unsigned long": "int",
    "long long": "int",
    "cef_color_t": "int",
    "double": "float",
    "float": "float",
}


def _vector_element(model, scope, analysis):
    """The kind of the elements of a `std::vector<T>`; the parser reports T."""
    element = analysis.result_value[0] if analysis.result_value else {}
    spelled = element.get("vector_type", "")
    kind = element.get("result_type")
    if kind == "string":
        return Str()
    if kind == "simple":
        if spelled in _PRIMITIVES and spelled != "bool":  # std::vector<bool> is not a container
            return Prim(spelled, _PRIMITIVES[spelled])
        if spelled in model.structs:
            return Struct(spelled, model.structs[spelled].fields)
        raise Unsupported("vector of values")
    if kind == "refptr":
        inner = re.match(r"^CefRefPtr<\s*(\w+)\s*>$", spelled)
        if inner and scope.is_library(inner.group(1)):
            return LibRef(inner.group(1))
        if inner and not scope.is_client(inner.group(1)):
            raise Unsupported("class %s is not generated yet" % inner.group(1))
    raise Unsupported("vector of values")


def classify(model, scope, analysis):
    """Return the Kind of one parsed type, or raise Unsupported(reason)."""
    spelled = analysis.get_type()
    result = analysis.result_type

    if result == "simple":
        if spelled == "void" and not analysis.is_byaddr():
            return Void()
        if analysis.is_byaddr():
            raise Unsupported("pointer to %s" % spelled)
        if spelled in _PRIMITIVES:
            return Prim(spelled, _PRIMITIVES[spelled])
        if spelled in model.structs:
            return Struct(spelled, model.structs[spelled].fields)
        if spelled == "CefBaseTime":
            return Time()
        if spelled in ("CefWindowHandle", "cef_window_handle_t"):
            # an X11 window on Linux (unsigned long); a plain integer in Python
            return Prim("cef_window_handle_t", "int")
        if spelled in model.enum_aliases and not analysis.is_byaddr():
            cname = model.enum_aliases[spelled]
            info = model.enum_defs.get(cname)
            return Enum(spelled, cname, info.py_name if info else "")
        raise Unsupported("struct-like value type %s" % spelled)

    if result == "string":
        if analysis.is_byaddr():
            raise Unsupported("pointer to CefString")
        return Str()

    if result == "structure":
        cname = analysis.result_value
        if cname in model.enums and not analysis.is_byaddr():
            info = model.enum_defs.get(cname)
            return Enum(spelled, cname, info.py_name if info else "")
        if spelled in model.structs and not analysis.is_byaddr():
            return Struct(spelled, model.structs[spelled].fields)  # one with strings (a Traits class)
        raise Unsupported("struct %s" % cname)

    if result == "refptr":
        if analysis.is_byref() and not analysis.is_const():
            raise Unsupported("reference to a CefRefPtr")
        # get_result_ptr_type_root() is the C API name (cef_request_t); we need CefRequest.
        inner = re.match(r"^CefRefPtr<\s*(\w+)\s*>$", spelled).group(1)
        if scope.is_library(inner):
            return LibRef(inner)
        if scope.is_client(inner):
            return ClientRef(inner)
        raise Unsupported("class %s is not generated yet" % inner)

    if result == "vector":
        return Vector(_vector_element(model, scope, analysis))
    if result in ("map", "multimap"):
        pair = analysis.result_value or []
        if len(pair) == 2 and all(item.get("result_type") == "string" for item in pair):
            return StrMap(multi=result == "multimap")
        raise Unsupported(result + " of values")
    if result in ("ownptr", "rawptr"):
        raise Unsupported("%s pointer" % result)
    raise Unsupported("type %s" % spelled)


# -- method plans --------------------------------------------------------------------


@dataclass
class ParamPlan:
    """How one parameter of a method is passed: its kind, direction and the C++ names that go with it.

    The ``out``, ``inout``, ``byref`` and ``byaddr`` flags select the conversion code the emitters generate.
    """
    cef_name: str
    name: str  # camelCase name (py_param_name)
    spelled: str  # the C++ type as written
    kind: Kind
    out: bool = False  # filled by the callee and returned from the PHP method
    # A library method that takes a struct by non-const reference reads and changes it
    # (CefDisplay::ConvertPointToPixels): the PHP method takes it and returns it.
    inout: bool = False
    const: bool = False
    byref: bool = False
    byaddr: bool = False  # `bool* flag`: an output the handler sets through a pointer
    is_return: bool = False  # the hidden output that carries a struct a handler method returns
    count_name: str = ""  # ItemBytes only: the C++ name of the item count parameter
    optional: bool = False  # the header marks it optional_param: null is allowed
    size_name: str = ""  # Buffer only: the C++ name of the size parameter


@dataclass
class MethodPlan:
    """The decision for one method or global function: its parameters and return kind, or ``reason`` when it is not generated."""
    owner: str  # CEF class name, or "" for a global function
    cef_name: str
    name: str
    static: bool
    ret: Kind = None
    ret_spelled: str = ""
    params: list = field(default_factory=list)
    reason: str = ""  # why it is not generated; empty when it is
    pure: bool = False
    node: object = None
    const_method: bool = False
    clamp_return: str = ""  # the parameter that limits the (integer) result of a handler

    @property
    def supported(self):
        """True if the method is generated (``reason`` is empty)."""
        return not self.reason

    @property
    def outs(self):
        """Parameters the callee fills; they are returned to PHP (library method) or by the PHP handler (client method)."""
        return [p for p in self.params if p.out]

    @property
    def void_return(self):
        """True if the C++ method returns nothing (a returned struct travels as an output)."""
        return isinstance(self.ret, Void) and not any(p.is_return for p in self.params)

    @property
    def ins(self):
        """Parameters PHP supplies: not output-only and not ignored."""
        return [p for p in self.params
                if (not p.out or p.inout) and not isinstance(p.kind, Ignored)]

    @property
    def results(self):
        """The ``(label, kind)`` pairs the PHP method returns, in order: the return value (``"return"``, unless void), then the outputs."""
        found = [] if isinstance(self.ret, Void) else [("return", self.ret)]
        return found + [(p.name, p.kind) for p in self.outs]


# A `const void*` parameter without a size parameter: the buffer is as large as the header
# says in its comment. Keyed by (class, method, parameter); the value is the C++ expression
# of the size in bytes, which may use the other parameters.
SIZED_BUFFERS = {
    # "|buffer| ... contains |width|*|height|*4 bytes of BGRA pixel data" (cef_render_handler.h)
    ("CefRenderHandler", "OnPaint", "buffer"):
        ("static_cast<size_t>(width) * static_cast<size_t>(height) * 4", True),
    # fread and fwrite: |n| items of |size| bytes; the Python handler sees the buffer and the
    # item size, and its result (the items) is limited to |n|.
    ("CefReadHandler", "Read", "ptr"):
        ("static_cast<size_t>(size) * static_cast<size_t>(n)", False),
    ("CefWriteHandler", "Write", "ptr"):
        ("static_cast<size_t>(size) * static_cast<size_t>(n)", True),
}
# A `const float** data, int frames` (one array of samples for every channel): keyed like the
# buffers; the value is the member of the proxy that holds the number of channels.
PLANES = {("CefAudioHandler", "OnAudioStreamPacket", "data"): "audio_channels_"}
# What a proxy remembers of a call for a later one: (class, method) -> (parameter, member).
REMEMBER = {("CefAudioHandler", "OnAudioStreamStarted"): ("channels", "audio_channels_")}
# The item count of the methods above is the length of the buffer: not given to Python.
IGNORED_PARAMS = {("CefReadHandler", "Read", "n"), ("CefWriteHandler", "Write", "n")}
# Parameters of handler methods that are not given to PHP. They are outputs the handler could set that need
# a kind of type this binding does not have yet (a CefRefPtr or a CefWindowInfo as output, a native cursor
# handle, a structure with buffers): CEF keeps its own defaults for them.
IGNORED_PARAMS |= {("CefLifeSpanHandler", "OnBeforePopup", name) for name in (
    "windowInfo", "client", "settings", "extra_info", "no_javascript_access")}
IGNORED_PARAMS |= {("CefDisplayHandler", "OnCursorChange", "cursor"),
                   ("CefDisplayHandler", "OnCursorChange", "custom_cursor_info")}
CLAMPED_RETURNS = {("CefReadHandler", "Read"): "n", ("CefWriteHandler", "Write"): "n"}

# Library methods with `ptr, size, n` (fread/fwrite): "in" copies bytes in, "out" fills them.
ITEM_BYTES = {("CefStreamWriter", "Write"): "in", ("CefStreamReader", "Read"): "out"}
# A `void*` that CEF only reads and copies, though the header does not say const.
BYTES_IN_COPY = {("CefStreamReader", "CreateForData", "data"),
                 ("CefV8Value", "CreateArrayBufferWithCopy", "buffer")}


# Library methods that fill a buffer of the size the caller gives (and return how much they
# wrote): (class, method) -> the buffer parameter. Others with a pointer and sizes
# (CefStreamReader::Read: ptr, size, n) have a different meaning for each size.
BYTES_OUT = {("CefBinaryValue", "GetData"): "buffer", ("CefZipReader", "ReadFile"): "buffer"}

# A handler parameter that CEF fills with the current value and the handler may change (java-cef
# gives Java a StringRef holding it): the Python method gets the value and returns the new one.
INOUT_PARAMS = {("CefResourceRequestHandler", "OnResourceRedirect", "new_url")}

# Library methods in which the size comes before the pointer: (class, method) -> "in" or "out".
BYTES_SIZE_FIRST = {("CefPostDataElement", "SetToBytes"): "in",
                    ("CefPostDataElement", "GetBytes"): "out"}

# Pointers that stay out, with the reason (shown in the coverage report): memory that CEF or V8
# owns can be freed while a Python object still points to it, and memory that Python lends for
# longer than a call needs it kept alive.
_OWNED = "a pointer into memory that %s owns and can free while Python still holds it"
DELIBERATE_POINTERS = {
    ("CefBinaryValue", "GetRawData"): (_OWNED % "CEF") + " (get_data() copies the bytes)",
    ("CefSharedMemoryRegion", "Memory"): _OWNED % "CEF's shared memory region",
    ("CefSharedProcessMessageBuilder", "Memory"): _OWNED % "CEF's shared memory builder",
    ("CefV8BackingStore", "Data"): _OWNED % "V8",
    ("CefV8Value", "GetArrayBufferData"): _OWNED % "V8",
    ("CefV8Value", "CreateArrayBuffer"):
        "V8 would share this memory and free it through a callback, so Python's bytes cannot "
        "be lent to it (CreateArrayBufferWithCopy copies)",
    ("CefV8ArrayBufferReleaseCallback", "ReleaseBuffer"):
        "the release of memory that V8 shared with the host (see CreateArrayBuffer)",
    ("CefResourceBundleHandler", "GetDataResource"):
        "CEF keeps the pointer the handler returns, so the bytes would have to outlive every use",
    ("CefResourceBundleHandler", "GetDataResourceForScale"):
        "CEF keeps the pointer the handler returns, so the bytes would have to outlive every use",
}


def plan_method(model, scope, owner, method, *, client_side, static=False):
    """Return the :class:`MethodPlan` of one method or function of class ``owner`` ("" for a global function).

    A construct that cannot be generated sets ``plan.reason`` (from :class:`Unsupported`) instead of raising.
    ``client_side`` is True for a method of a client class; ``static`` marks static methods and global functions.
    """
    plan = MethodPlan(
        owner=owner,
        cef_name=method.get_name(),
        name=py_method_name(method.get_name()),
        static=static,
        node=method,
        const_method=method.is_const() if hasattr(method, "is_const") else False,
    )
    try:
        if (owner, method.get_name()) in DELIBERATE_POINTERS:
            raise Unsupported(DELIBERATE_POINTERS[(owner, method.get_name())])
        retval = method.get_retval().get_type()
        plan.ret = classify(model, scope, retval)
        plan.ret_spelled = retval.get_type()
        _check_return(plan.ret, client_side)

        arguments = list(method.get_arguments())
        optional_names = set(method.get_attrib_list("optional_param") or [])
        i = 0
        while i < len(arguments):
            argument = arguments[i]
            analysis = argument.get_type()
            name = argument.get_name()
            direction = BYTES_SIZE_FIRST.get((owner, method.get_name()))
            if (direction and not client_side and i + 1 < len(arguments)
                    and _is_size_t(model, scope, argument)
                    and arguments[i + 1].get_type().get_type() == "void"
                    and arguments[i + 1].get_type().is_byaddr()):
                # `size_t size, void* bytes` -> bytes in or out, the size first
                plan.params.append(ParamPlan(
                    arguments[i + 1].get_name(), py_param_name(arguments[i + 1].get_name()),
                    "void*", Bytes("size_t", size_first=True), out=direction == "out",
                    const=direction == "in"))
                plan.params[-1].size_name = name
                i += 2
                continue
            if (client_side and analysis.get_type() == "float*" and analysis.is_byaddr()
                    and (owner, method.get_name(), name) in PLANES and i + 1 < len(arguments)):
                # `const float** data, int frames` -> one view a channel; the frames are the length of the views
                plan.params.append(ParamPlan(
                    name, py_param_name(name), "float*",
                    Planes(PLANES[(owner, method.get_name(), name)]), const=True, byaddr=True))
                plan.params[-1].size_name = arguments[i + 1].get_name()
                i += 2
                continue
            if analysis.get_type() == "void" and analysis.is_byaddr():
                entry = SIZED_BUFFERS.get((owner, method.get_name(), name))
                if client_side and entry:
                    plan.params.append(ParamPlan(
                        name, py_param_name(name), "void*",
                        Buffer("size_t", size_expr=entry[0], readonly=entry[1]),
                        const=entry[1], optional=name in optional_names))
                    i += 1
                    continue
                direction = ITEM_BYTES.get((owner, method.get_name()))
                if not client_side and direction and i + 2 < len(arguments) and all(
                        _is_size_t(model, scope, a) for a in arguments[i + 1:i + 3]):
                    # `void* ptr, size_t size, size_t n` -> bytes in or out, in items
                    plan.params.append(ParamPlan(
                        name, "data" if direction == "in" else py_param_name(name), "void*",
                        ItemBytes("size_t"), out=direction == "out", const=direction == "in"))
                    plan.params[-1].size_name = arguments[i + 1].get_name()
                    plan.params[-1].count_name = arguments[i + 2].get_name()
                    i += 3
                    continue
                if not client_side and i + 1 < len(arguments):
                    size = classify(model, scope, arguments[i + 1].get_type())
                    sized = isinstance(size, Prim) and size.cpp == "size_t"
                    copied = (owner, method.get_name(), name) in BYTES_IN_COPY
                    if sized and (analysis.is_const() or copied) and not (
                            i + 2 < len(arguments) and _is_size_t(model, scope, arguments[i + 2])):
                        # `const void* data, size_t size` -> bytes in
                        plan.params.append(ParamPlan(name, py_param_name(name), "void*",
                                                     Bytes(size.cpp), const=analysis.is_const()))
                        plan.params[-1].size_name = arguments[i + 1].get_name()
                        i += 2
                        continue
                    if sized and BYTES_OUT.get((owner, method.get_name())) == name:
                        # `void* buffer, size_t buffer_size` -> bytes out
                        plan.params.append(ParamPlan(name, py_param_name(name), "void*",
                                                     Bytes(size.cpp), out=True))
                        plan.params[-1].size_name = arguments[i + 1].get_name()
                        i += 2
                        continue
                # `void* data, <integer> size` -> Buffer
                if not client_side or i + 1 >= len(arguments):
                    raise Unsupported("untyped pointer parameter %s" % name)
                size = classify(model, scope, arguments[i + 1].get_type())
                if not isinstance(size, Prim) or size.py != "int":
                    raise Unsupported("untyped pointer parameter %s" % name)
                plan.params.append(
                    ParamPlan(name, py_param_name(name), "void*",
                              Buffer(size.cpp, readonly=analysis.is_const()),
                              const=analysis.is_const(), optional=name in optional_names))
                plan.params[-1].size_name = arguments[i + 1].get_name()
                i += 2
                continue
            if client_side and (owner, method.get_name(), name) in IGNORED_PARAMS:
                # still declared as the header does (a reference, a pointer, const)
                plan.params.append(ParamPlan(name, py_param_name(name), analysis.get_type(),
                                             Ignored(), const=analysis.is_const(),
                                             byref=analysis.is_byref(), byaddr=analysis.is_byaddr()))
                i += 1
                continue
            if client_side and analysis.get_type() == "CefEventHandle":
                plan.params.append(ParamPlan(name, py_param_name(name), "CefEventHandle", Ignored()))
                i += 1
                continue
            if (client_side and analysis.result_type == "simple" and analysis.is_byaddr()
                    and not analysis.is_const() and analysis.get_type() in _PRIMITIVES):
                # `bool* is_keyboard_shortcut`: set by the handler, returned by the Python method
                kind = Prim(analysis.get_type(), _PRIMITIVES[analysis.get_type()])
                plan.params.append(ParamPlan(name, py_param_name(name), analysis.get_type(), kind,
                                             out=True, byaddr=True))
                i += 1
                continue
            kind = classify(model, scope, analysis)
            if client_side and isinstance(kind, StrMap):
                raise Unsupported("a map in a handler method")
            out = analysis.is_byref() and not analysis.is_const() and not isinstance(kind, LibRef)
            if out:
                if not client_side and not isinstance(kind, (Vector, Prim, Str, Enum, Struct,
                                                              StrMap)):
                    raise Unsupported("output parameter %s of a library method" % name)
                if client_side and isinstance(kind, Vector):
                    raise Unsupported("output vector %s of a handler method" % name)
                if isinstance(kind, Time):
                    raise Unsupported("output parameter %s of type time" % name)
                if isinstance(kind, (LibRef, ClientRef)):
                    raise Unsupported("output parameter %s of object type" % name)
            elif isinstance(kind, Vector) and not client_side and isinstance(kind.element, LibRef):
                raise Unsupported("vector of objects passed to a library method")
            if isinstance(kind, Void):
                raise Unsupported("void parameter")
            plan.params.append(ParamPlan(name, py_param_name(name), analysis.get_type(), kind,
                                         out=out, const=analysis.is_const(),
                                         byref=analysis.is_byref(),
                                         optional=name in optional_names,
                                         inout=out and ((not client_side and isinstance(kind, Struct))
                                                        or (owner, method.get_name(), name) in INOUT_PARAMS)))
            i += 1
        plan.clamp_return = CLAMPED_RETURNS.get((owner, method.get_name()), "")
        if client_side and isinstance(plan.ret, Struct):
            # A handler returns a struct by value: the table function fills one through a
            # hidden last output parameter, and the proxy returns it.
            plan.params.append(ParamPlan("result", "result", plan.ret_spelled, plan.ret,
                                         out=True, is_return=True))
            plan.ret = Void()
    except Unsupported as reason:
        plan.reason = str(reason)
    return plan


def plan_class(model, scope, cls):
    """The plans of the virtual methods, then the static functions, of a class (its CEF parents' included, unless the
    class is a PHP subclass of its parent: see Scope.python_parent). PHP has one method
    per name, so of several supported plans with the same name (overloads: CefRequestContext::CreateContext has two) the first is
    generated and the others get a ``reason``."""
    client = cls.is_client_side()
    own_only = scope.python_parent(cls.get_name()) is not None      # the rest is inherited in Python
    plans = [plan_method(model, scope, cls.get_name(), m, client_side=client)
             for m in model.virtual_funcs(cls, own_only=own_only)]
    plans += [plan_method(model, scope, cls.get_name(), m, client_side=client, static=True)
              for m in model.static_funcs(cls)]
    seen = set()
    for plan in plans:
        if plan.name in seen and plan.supported:
            plan.reason = "another overload of %s is generated (Python has one name)" % plan.cef_name
        elif plan.supported:
            seen.add(plan.name)
    return plans


def _is_size_t(model, scope, argument):
    """Return True if ``argument`` is a ``size_t`` parameter."""
    try:
        kind = classify(model, scope, argument.get_type())
    except Unsupported:
        return False
    return isinstance(kind, Prim) and kind.cpp == "size_t"


def _check_return(kind, client_side):
    """Raise :class:`Unsupported` if a client method returns a ``Time``; nothing else is checked."""
    if client_side:
        if isinstance(kind, Time):
            raise Unsupported("a client method returning a time")
    # A client method returns a library object (the Python object holds one: it is given to CEF with a reference);
    # a library method returns a client object (the Python object that was given to CEF is found again).
