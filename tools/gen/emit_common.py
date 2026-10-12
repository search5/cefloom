"""Code snippets shared by the emitters: names, argument info, and the conversion of every kind of type
between PHP values (zval) and C++ values.

Pipeline role: shared layer of the emitters. ``emit_lib`` and ``emit_client`` build their C++ from the snippets
here; ``emit_data`` uses ``NS``, ``cstr``, ``php_fqn_c`` and ``Names``; ``emit_stub`` uses ``NS``; ``generate`` uses ``Names``.

Conventions of the generated code
  a_<i>   the i-th PHP argument as the Zend parser delivers it
  c_<i>   the same as a C++ value
  o_<i>   an output the callee fills
  cl_*    other locals of the generated code (they cannot collide with the names of CEF parameters)
"""

from model import py_class_name
from typesys import (Buffer, Bytes, ClientRef, Enum, Ignored, ItemBytes, LibRef, Planes, Prim, Str, StrMap, Struct,
                     Time, Vector, Void)

NS = "Cefloom"


def cstr(text):
    """Return ``text`` as a C string literal, quotes and backslashes escaped."""
    return '"' + text.replace("\\", "\\\\").replace('"', '\\"') + '"'


def php_fqn(cef_class):
    """CefBrowser -> Cefloom\\Browser (the backslash as PHP writes it)."""
    return NS + "\\" + py_class_name(cef_class)


def php_fqn_c(cef_class):
    """The same as the text of a C string literal."""
    return NS + "\\\\" + py_class_name(cef_class)


def ce_var(cef_class):
    """Return the name of the ``zend_class_entry*`` variable of ``cef_class``."""
    return "ce_" + cef_class


def lower(name):
    """Return ``name`` in lower case."""
    return name.lower()


class Names:
    """Maps the C++ struct classes to the C structs the conversion functions are named after."""

    def __init__(self, model, scope):
        """Bind the names to the parsed ``model`` and its ``scope``."""
        self.model = model
        self.scope = scope

    def struct_cname(self, cls):
        """Return the C struct (``cef_rect_t``) behind the value struct class ``cls`` (``CefRect``)."""
        return self.model.structs[cls].cname

    def to_zval_fn(self, cls):
        """Return the name of the generated function that converts a ``cls`` value to a PHP object."""
        return "cl_to_zval_" + self.struct_cname(cls)

    def from_zval_fn(self, cls):
        """Return the name of the generated function that fills a ``cls`` value from a PHP object."""
        return "cl_from_zval_" + self.struct_cname(cls)


# -- argument info ---------------------------------------------------------------------------------------


def type_of(kind):
    """Return ``(Zend type code or None, class name as a C string literal body or None, nullable)`` for a kind used as a
    parameter or result; raise AssertionError for a kind that has no PHP type (Void, Ignored, ItemBytes)."""
    if isinstance(kind, Prim):
        return ("_IS_BOOL" if kind.py == "bool" else "IS_DOUBLE" if kind.py == "float" else "IS_LONG"), None, False
    if isinstance(kind, (Str, Bytes)):
        return "IS_STRING", None, False
    if isinstance(kind, Enum):
        return "IS_LONG", None, False
    if isinstance(kind, (LibRef, ClientRef)):
        return None, php_fqn_c(kind.cls), True
    if isinstance(kind, Struct):
        return None, php_fqn_c(kind.cls), False
    if isinstance(kind, (Vector, StrMap, Planes)):
        return "IS_ARRAY", None, False
    if isinstance(kind, Time):
        return None, "DateTimeImmutable", True
    if isinstance(kind, Buffer):
        return None, NS + "\\\\Buffer", False
    raise AssertionError(kind)


def arg_entry(name, kind, nullable_override=None):
    """Return one ``ZEND_ARG_*`` line for an input called ``name``: untyped (``ZEND_ARG_INFO``) for a time, an object
    type for an object, else a scalar or array type. ``nullable_override`` replaces the nullability of the kind."""
    code, cls, nullable = type_of(kind)
    if nullable_override is not None:
        nullable = nullable_override
    if cls and isinstance(kind, Time):
        return "ZEND_ARG_INFO(0, %s)" % name  # a DateTimeInterface, a number or null
    if cls:
        return "ZEND_ARG_OBJ_INFO(0, %s, %s, %d)" % (name, cls, 1 if nullable else 0)
    return "ZEND_ARG_TYPE_INFO(0, %s, %s, 0)" % (name, code)


def result_type(results):
    """Return ``(code, class, nullable)`` of the PHP return type for a list of ``(label, kind)``: void for none, array
    for several, else the type of the one."""
    if not results:
        return "IS_VOID", None, False
    if len(results) > 1:
        return "IS_ARRAY", None, False
    kind = results[0][1]
    code, cls, nullable = type_of(kind)
    return code, cls, nullable


def begin_arginfo(var, required, results, tentative, nullable=False):
    """Return the ``ZEND_BEGIN_ARG_*`` line of an arginfo table named ``var``.

    :param required: number of required parameters.
    :param results: ``(label, kind)`` pairs the method returns, see :func:`result_type`.
    :param tentative: emit the tentative-return-type form (``ZEND_BEGIN_ARG_WITH_TENTATIVE_RETURN_*``; emit_client uses it, emit_lib does not).
    :param nullable: make the return type nullable even if the kind is not.
    """
    code, cls, was_nullable = result_type(results)
    nullable = nullable or was_nullable
    if cls:
        macro = "ZEND_BEGIN_ARG_WITH_%sRETURN_OBJ_INFO_EX" % ("TENTATIVE_" if tentative else "")
        return "%s(%s, 0, %d, %s, %d)" % (macro, var, required, cls, 1 if nullable else 0)
    macro = "ZEND_BEGIN_ARG_WITH_%sRETURN_TYPE_INFO_EX" % ("TENTATIVE_" if tentative else "")
    return "%s(%s, 0, %d, %s, %d)" % (macro, var, required, code, 1 if nullable else 0)


# -- C++ -> zval -----------------------------------------------------------------------------------------


def element_cpp(kind):
    """Return the C++ element type of a ``std::vector`` whose elements are of ``kind``; raise AssertionError for a kind a vector cannot hold."""
    if isinstance(kind, Str):
        return "CefString"
    if isinstance(kind, Prim):
        return kind.cpp
    if isinstance(kind, Struct):
        return kind.cls
    if isinstance(kind, LibRef):
        return "CefRefPtr<%s>" % kind.cls
    raise AssertionError(kind)


def wrap_call(scope, cls, target, pointer):
    """Return the statement ``cl_wrap_<cls>(target, pointer);`` that wraps the CEF object ``pointer`` (a raw pointer expression) in the zval ``target``. ``scope`` is unused."""
    return "cl_wrap_%s(%s, %s);" % (cls, target, pointer)


def to_zval(names, scope, kind, expr, target, ind="    "):
    """Return the lines that put the C++ value ``expr`` into the zval pointer ``target``; raise AssertionError for a kind that cannot be returned (Void, Ignored, Buffer, Planes, Bytes, ItemBytes). A ClientRef with no PHP object gives null."""
    if isinstance(kind, Prim):
        if kind.py == "bool":
            return [ind + "ZVAL_BOOL(%s, %s);" % (target, expr)]
        if kind.py == "float":
            return [ind + "ZVAL_DOUBLE(%s, static_cast<double>(%s));" % (target, expr)]
        return [ind + "ZVAL_LONG(%s, static_cast<zend_long>(%s));" % (target, expr)]
    if isinstance(kind, Str):
        return [ind + "cl::ret_str(%s, %s);" % (target, expr)]
    if isinstance(kind, Enum):
        return [ind + "ZVAL_LONG(%s, static_cast<zend_long>(%s));" % (target, expr)]
    if isinstance(kind, LibRef):
        return [ind + wrap_call(scope, kind.cls, target, "%s.get()" % expr)]
    if isinstance(kind, ClientRef):
        return [ind + "{",
                ind + "  zend_object* cl_o = cl::ProxyBase::find(%s.get());" % expr,
                ind + "  if (cl_o) { ZVAL_OBJ_COPY(%s, cl_o); } else { ZVAL_NULL(%s); }" % (target, target),
                ind + "}"]
    if isinstance(kind, Struct):
        return [ind + "%s(%s, %s);" % (names.to_zval_fn(kind.cls), target, expr)]
    if isinstance(kind, Time):
        return [ind + "cl::ret_time(%s, %s.val);" % (target, expr)]
    if isinstance(kind, StrMap):
        return [ind + "cl::ret_strmap(%s, %s);" % (target, expr)]
    if isinstance(kind, Vector):
        lines = [ind + "array_init(%s);" % target,
                 ind + "for (const auto& cl_e : %s) {" % expr,
                 ind + "  zval cl_item;"]
        lines += to_zval(names, scope, kind.element, "cl_e", "&cl_item", ind + "  ")
        lines += [ind + "  add_next_index_zval(%s, &cl_item);" % target, ind + "}"]
        return lines
    raise AssertionError(kind)


# -- zval -> C++ -----------------------------------------------------------------------------------------


def from_zval(names, scope, kind, zv, spelled, ind="    "):
    """Return an expression (a string) for the C++ value of the zval pointer ``zv`` for Prim, Str and Enum; None for every other kind. ``names``, ``scope`` and ``ind`` are unused."""
    if isinstance(kind, Prim):
        if kind.py == "bool":
            return "zend_is_true(%s)" % zv
        if kind.py == "float":
            return "static_cast<%s>(zval_get_double(%s))" % (kind.cpp, zv)
        return "static_cast<%s>(zval_get_long(%s))" % (kind.cpp, zv)
    if isinstance(kind, Str):
        return "cl::to_cef(%s)" % zv
    if isinstance(kind, Enum):
        return "static_cast<%s>(zval_get_long(%s))" % (spelled, zv)
    return None


# -- the methods of a plan ------------------------------------------------------------------------------


def php_name(plan):
    """Return the PHP method name of ``plan``."""
    return plan.name


def doc_signature(plan):
    """Return ``plan.name`` (the name only, no parameters). Not called."""
    return plan.name
