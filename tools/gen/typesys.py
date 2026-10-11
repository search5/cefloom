"""Type handling of the generator.

Every C++ type in a CEF method signature is classified into a *kind*. A kind is a
small description (what the type is, how it looks in Python) that the emitters
turn into C++, Cython and .pyi code. Types the generator cannot handle yet are
reported with a reason instead of being skipped silently, so the coverage report
shows exactly what is left to do.

To support a new kind of type:
  1. add a `Kind` subclass here and recognise it in `classify()`;
  2. teach the emitters (emit_cpp.py, emit_cython.py, emit_pyi.py) how to convert it.
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
    pass


@dataclass(frozen=True)
class Void(Kind):
    pass


@dataclass(frozen=True)
class Prim(Kind):
    cpp: str  # the type as written in the header
    py: str  # annotation in Python: int, bool or float


@dataclass(frozen=True)
class Str(Kind):
    """CefString (UTF-16 inside CEF, str in Python)."""


@dataclass(frozen=True)
class Enum(Kind):
    spelled: str  # as written where it is used, e.g. TransitionType
    cname: str  # the C enumeration, e.g. cef_transition_type_t
    # The class in cefweaver.types ("" if the enumeration could not be read: then an int).
    py: str = field(default="", compare=False)


@dataclass(frozen=True)
class LibRef(Kind):
    """CefRefPtr<T> of an object implemented by CEF (wrapped for Python)."""

    cls: str


@dataclass(frozen=True)
class ClientRef(Kind):
    """CefRefPtr<T> of an object implemented by the application (proxied to Python)."""

    cls: str


@dataclass(frozen=True)
class Struct(Kind):
    """A plain data struct passed by value (CefRect, CefPoint, ...): a named tuple in Python."""

    cls: str  # the C++ class, e.g. CefRect
    fields: tuple  # of model.StructField
    raw: bool = False  # a C struct without a C++ class (cname is its name)
    cname: str = ""


@dataclass(frozen=True)
class Vector(Kind):
    """`std::vector<T>` (a list in Python). The element is a string, a number (not bool), a
    value type struct or, from a library method, an object implemented by CEF."""

    element: Kind


@dataclass(frozen=True)
class Buffer(Kind):
    """`void* data, <integer> size`: a writable memoryview in Python. A `const void*` of
    which the header gives the size in words (OnPaint: width * height pixels) has no size
    parameter: `size_expr` is the C++ expression of its size in bytes, and the memoryview is
    read-only."""

    size_cpp: str  # type of the size parameter
    size_expr: str = ""  # C++ size in bytes, when there is no size parameter
    readonly: bool = False


@dataclass(frozen=True)
class Planes(Kind):
    """`const float** data, int frames`: a list of read-only float32 memoryviews in Python, one of `frames`
    samples for every channel. The number of channels is no argument of the call: an earlier call told it
    (OnAudioStreamStarted) and the proxy remembers it in the member `count_member`."""

    count_member: str


@dataclass(frozen=True)
class StrMap(Kind):
    """`std::multimap<CefString, CefString>` (a header map) or `std::map<CefString, CefString>`
    (the switches of a command line): a dict of str in Python, as a Map in java-cef. A key that
    occurs twice in a multimap keeps its last value."""

    multi: bool


@dataclass(frozen=True)
class Time(Kind):
    """`CefBaseTime`: microseconds since 1601-01-01 UTC (cef_time.h). A timezone-aware
    `datetime` in Python; 0, CEF's null time, is None."""


@dataclass(frozen=True)
class Ignored(Kind):
    """A parameter of a handler method that is not given to Python: the platform's native
    event (`CefEventHandle`, an XEvent* on Linux). java-cef does not pass it either."""


@dataclass(frozen=True)
class Bytes(Kind):
    """`const void* data, size_t size` of a library method: any bytes-like object in Python.
    With `out` on its ParamPlan it is `void* buffer, size_t buffer_size` that CEF fills: the
    Python method takes the size and returns `bytes` (the method's return value, the number of
    bytes written, only trims them)."""

    size_cpp: str  # type of the size parameter
    size_first: bool = False  # `size_t size, const void* bytes` (CefPostDataElement)


@dataclass(frozen=True)
class ItemBytes(Kind):
    """`void* ptr, size_t size, size_t n` of a library method (fread/fwrite): `n` items of
    `size` bytes. In Python `write(data, size=1)` takes bytes and returns the items written,
    and `read(n, size=1)` returns the bytes read (`n * size` at most)."""

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
    cef_name: str
    name: str  # PEP 8 name in Python
    spelled: str  # the C++ type as written
    kind: Kind
    out: bool = False  # filled by the callee and returned from the Python method
    # A library method that takes a struct by non-const reference reads and changes it
    # (CefDisplay::ConvertPointToPixels): the Python method takes it and returns it.
    inout: bool = False
    const: bool = False
    byref: bool = False
    byaddr: bool = False  # `bool* flag`: an output the handler sets through a pointer
    is_return: bool = False  # the hidden output that carries a struct a handler method returns
    count_name: str = ""  # ItemBytes only: the C++ name of the item count parameter
    optional: bool = False  # the header marks it optional_param: None is allowed
    size_name: str = ""  # Buffer only: the C++ name of the size parameter


@dataclass
class MethodPlan:
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
        return not self.reason

    @property
    def outs(self):
        return [p for p in self.params if p.out]

    @property
    def void_return(self):
        """True if the C++ method returns nothing (a returned struct travels as an output)."""
        return isinstance(self.ret, Void) and not any(p.is_return for p in self.params)

    @property
    def ins(self):
        return [p for p in self.params
                if (not p.out or p.inout) and not isinstance(p.kind, Ignored)]

    @property
    def results(self):
        """What the Python side of a client method returns, in order."""
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
    """Decide how one method or function is generated (or why it is not)."""
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
                        const=entry[1]))
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
                              const=analysis.is_const()))
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
    """The plans of all methods of a class (its parents' included, unless the class is a Python subclass of its
    parent: see Scope.python_parent). Python has one attribute
    per name, so of overloads (CefRequestContext::CreateContext has two) the first is
    generated and the others say so."""
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
    try:
        kind = classify(model, scope, argument.get_type())
    except Unsupported:
        return False
    return isinstance(kind, Prim) and kind.cpp == "size_t"


def _check_return(kind, client_side):
    if client_side:
        if isinstance(kind, Time):
            raise Unsupported("a client method returning a time")
    # A client method returns a library object (the Python object holds one: it is given to CEF with a reference);
    # a library method returns a client object (the Python object that was given to CEF is found again).
