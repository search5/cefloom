"""Emit the PHP classes that wrap the objects CEF implements (CefBrowser, CefFrame, ...), and the static
methods of the class Cefloom\\Cef that stand for the global functions of CEF."""

from emit_common import (NS, Names, arg_entry, begin_arginfo, ce_var, cstr, element_cpp, php_fqn_c, result_type,
                         to_zval, type_of, wrap_call)
from model import py_class_name, py_method_name
from scope import HANDWRITTEN_METHODS, NEEDS_CEF_RUNNING, OFF_UI_THREAD, WRAP_CLIENT, BEFORE_CALL
from typesys import (Buffer, Bytes, ClientRef, Enum, Ignored, ItemBytes, LibRef, Prim, Str, StrMap, Struct, Time,
                     Vector, Void)

ACCESS = "ZEND_ACC_PUBLIC"

# A library method that returns bool and has outputs (GetKeys(keys)) uses the bool to say whether the outputs are
# valid: PHP gets the outputs only, or null when the bool is false. These keep the bool as a result of its own.
KEEP_BOOL_RESULT = {("CefPreferenceManager", "SetPreference")}


def success_flag(plan):
    """True for a method whose bool return only tells that the outputs are valid (see KEEP_BOOL_RESULT)."""
    return (isinstance(plan.ret, Prim) and plan.ret.py == "bool" and bool(plan.outs)
            and (plan.owner, plan.cef_name) not in KEEP_BOOL_RESULT)


class Input:
    """One PHP argument of a method."""

    def __init__(self, php_name, kind, nullable=None):
        self.php_name = php_name
        self.kind = kind
        self.nullable = nullable
        self.decl = []
        self.zpp = ""
        self.conv = []


def _optional(p):
    return bool(getattr(p, "optional", False))


def build(names, scope, plan):
    """Inputs, output locals, the call arguments and the results of one library method."""
    inputs = []
    call_args = []
    outs = []          # (declaration line)
    results = []       # (label, kind, expression of the C++ value)
    bytes_out = None
    index = 0
    for p in plan.params:
        k = p.kind
        i = index
        index += 1
        a, c, o = "a_%d" % i, "c_%d" % i, "o_%d" % i
        if isinstance(k, Ignored):
            raise AssertionError("an ignored parameter in a library method")
        if isinstance(k, Bytes):
            if p.out:
                # PHP passes the size, gets the bytes
                inp = Input("size", Prim("zend_long", "int"))
                inp.decl = ["zend_long %s = 0;" % a]
                inp.zpp = "Z_PARAM_LONG(%s)" % a
                inp.conv = ["if (%s < 0) { zend_argument_value_error(%d, \"must not be negative\"); RETURN_THROWS(); }"
                            % (a, len(inputs) + 1)]
                outs.append("std::string %s(static_cast<size_t>(%s), '\\0');" % (o, a))
                call_args.append("%s.size(), %s.data()" % (o, o) if k.size_first else "%s.data(), %s.size()" % (o, o))
                bytes_out = (o, None)
                inputs.append(inp)
            else:
                inp = Input(p.name, Str())
                inp.decl = ["zend_string* %s = nullptr;" % a]
                inp.zpp = "Z_PARAM_STR(%s)" % a
                inputs.append(inp)
                call_args.append("ZSTR_LEN(%s), ZSTR_VAL(%s)" % (a, a) if k.size_first
                                 else "ZSTR_VAL(%s), ZSTR_LEN(%s)" % (a, a))
            continue
        if isinstance(k, ItemBytes):
            size = Input("size", Prim("zend_long", "int"))
            size.decl = ["zend_long %s_size = 1;" % a]
            size.zpp = "Z_PARAM_LONG(%s_size)" % a
            size.conv = ["if (%s_size < 1) { zend_argument_value_error(%d, \"must be positive\"); RETURN_THROWS(); }"
                         % (a, len(inputs) + 2)]
            if p.out:
                count = Input("n", Prim("zend_long", "int"))
                count.decl = ["zend_long %s = 0;" % a]
                count.zpp = "Z_PARAM_LONG(%s)" % a
                count.conv = ["if (%s < 0) { zend_argument_value_error(%d, \"must not be negative\"); RETURN_THROWS(); }"
                              % (a, len(inputs) + 1)]
                outs.append("std::string %s(static_cast<size_t>(%s) * static_cast<size_t>(%s_size), '\\0');"
                            % (o, a, a))
                call_args.append("%s.data(), static_cast<size_t>(%s_size), static_cast<size_t>(%s)" % (o, a, a))
                bytes_out = (o, "%s_size" % a)
                inputs += [count, size]
            else:
                data = Input("data", Str())
                data.decl = ["zend_string* %s = nullptr;" % a]
                data.zpp = "Z_PARAM_STR(%s)" % a
                size.zpp = "Z_PARAM_LONG(%s_size)" % a
                inputs += [data, size]
                call_args.append("ZSTR_VAL(%s), static_cast<size_t>(%s_size), ZSTR_LEN(%s) / static_cast<size_t>(%s_size)"
                                 % (a, a, a, a))
            continue

        if p.out and not p.inout:
            # a plain output: the callee fills it, PHP gets it back
            if isinstance(k, Prim):
                outs.append("%s %s = %s();" % (k.cpp, o, k.cpp))
            elif isinstance(k, Enum):
                outs.append("%s %s = static_cast<%s>(0);" % (k.cname, o, k.cname))
            elif isinstance(k, Str):
                outs.append("CefString %s;" % o)
            elif isinstance(k, Struct):
                outs.append("%s %s;" % (k.cls, o))
            elif isinstance(k, Vector):
                outs.append("std::vector<%s> %s;" % (element_cpp(k.element), o))
            elif isinstance(k, StrMap):
                outs.append("std::%smap<CefString, CefString> %s;" % ("multi" if k.multi else "", o))
            else:
                raise AssertionError(k)
            call_args.append(o)
            results.append((p.name, k, o))
            continue

        # an input (an in/out struct is both)
        inp = Input(p.name, k, _optional(p))
        if isinstance(k, Prim):
            if k.py == "bool":
                inp.decl = ["bool %s = false;" % a]
                inp.zpp = "Z_PARAM_BOOL(%s)" % a
                call_args.append(a)
            elif k.py == "float":
                inp.decl = ["double %s = 0;" % a]
                inp.zpp = "Z_PARAM_DOUBLE(%s)" % a
                call_args.append("static_cast<%s>(%s)" % (k.cpp, a))
            else:
                inp.decl = ["zend_long %s = 0;" % a]
                inp.zpp = "Z_PARAM_LONG(%s)" % a
                call_args.append("static_cast<%s>(%s)" % (k.cpp, a))
        elif isinstance(k, Enum):
            inp.decl = ["zend_long %s = 0;" % a]
            inp.zpp = "Z_PARAM_LONG(%s)" % a
            call_args.append("static_cast<%s>(%s)" % (k.cname, a))
        elif isinstance(k, Str):
            inp.decl = ["zend_string* %s = nullptr;" % a]
            inp.zpp = "Z_PARAM_STR(%s)" % a
            inp.conv = ["CefString %s = cl::to_cef(%s);" % (c, a)]
            call_args.append(c)
        elif isinstance(k, LibRef):
            inp.decl = ["zend_object* %s = nullptr;" % a]
            if _optional(p):
                inp.zpp = "Z_PARAM_OBJ_OF_CLASS_OR_NULL(%s, %s)" % (a, ce_var(k.cls))
                inp.conv = ["CefRefPtr<%s> %s(cl::lib_ptr<%s>(%s, %d));" % (k.cls, c, k.cls, a, scope.depth(k.cls))]
            else:
                inp.zpp = "Z_PARAM_OBJ_OF_CLASS(%s, %s)" % (a, ce_var(k.cls))
                inp.conv = ["CefRefPtr<%s> %s(cl::lib_ptr<%s>(%s, %d));" % (k.cls, c, k.cls, a, scope.depth(k.cls)),
                            "if (!%s) { zend_argument_value_error(%d, \"holds no native object\"); RETURN_THROWS(); }"
                            % (c, len(inputs) + 1)]
            call_args.append(c)
        elif isinstance(k, ClientRef):
            inp.decl = ["zend_object* %s = nullptr;" % a]
            inp.zpp = ("Z_PARAM_OBJ_OF_CLASS_OR_NULL(%s, %s)" if _optional(p) else "Z_PARAM_OBJ_OF_CLASS(%s, %s)") % (
                a, ce_var(k.cls))
            if k.cls == "CefCompletionCallback":
                # CEF calls an optional completion callback without a check for NULL in several places
                # (CefRequestContext::ClearHttpCache binds callback->OnComplete): a NULL ends the process. A callback that
                # does nothing means the same as none.
                inp.conv = ["CefRefPtr<%s> %s(%s ? cl_make_%s(%s) : cl::noop_completion());" % (k.cls, c, a, k.cls, a)]
            elif k.cls == "CefClient" and (plan.owner, plan.cef_name) in WRAP_CLIENT:
                inp.conv = ["CefRefPtr<CefClient> %s(cl_bridge_wrap_client(%s ? cl_make_CefClient(%s) : nullptr));" % (c, a, a)]
            else:
                inp.conv = ["CefRefPtr<%s> %s(%s ? cl_make_%s(%s) : nullptr);" % (k.cls, c, a, k.cls, a)]
            call_args.append(c)
        elif isinstance(k, Struct):
            inp.decl = ["zend_object* %s = nullptr;" % a]
            inp.zpp = "Z_PARAM_OBJ_OF_CLASS(%s, %s)" % (a, ce_var(k.cls))
            inp.conv = ["%s %s;" % (k.cls, c), "%s(%s, &%s);" % (names.from_zval_fn(k.cls), a, c)]
            call_args.append(c)
            if p.out:  # in/out
                results.append((p.name, k, c))
        elif isinstance(k, Vector):
            inp.decl = ["HashTable* %s = nullptr;" % a]
            inp.zpp = "Z_PARAM_ARRAY_HT(%s)" % a
            inp.conv = _vector_in(names, k, a, c)
            call_args.append(c)
        elif isinstance(k, StrMap):
            inp.decl = ["zval* %s = nullptr;" % a]
            inp.zpp = "Z_PARAM_ARRAY(%s)" % a
            inp.conv = ["std::%smap<CefString, CefString> %s;" % ("multi" if k.multi else "", c),
                        "cl::strmap_from(%s, &%s);" % (a, c)]
            call_args.append(c)
        elif isinstance(k, Time):
            inp.decl = ["zval* %s = nullptr;" % a]
            inp.zpp = "Z_PARAM_ZVAL(%s)" % a
            inp.conv = ["CefBaseTime %s;" % c, "%s.val = cl::time_from(%s);" % (c, a)]
            call_args.append(c)
        else:
            raise AssertionError(k)
        inputs.append(inp)

    if bytes_out:
        results = [("data", Str(), None)]
    elif not isinstance(plan.ret, Void) and not success_flag(plan):
        results.insert(0, ("return", plan.ret, "cl_ret"))
    return inputs, call_args, outs, results, bytes_out


def _vector_in(names, kind, a, c):
    element = kind.element
    lines = ["std::vector<%s> %s;" % (element_cpp(element), c),
             "{", "  zval* cl_v;",
             "  ZEND_HASH_FOREACH_VAL(%s, cl_v) {" % a]
    if isinstance(element, Str):
        lines.append("    %s.push_back(cl::to_cef(cl_v));" % c)
    elif isinstance(element, Prim):
        conv = {"bool": "zend_is_true(cl_v)", "float": "zval_get_double(cl_v)"}.get(element.py, "zval_get_long(cl_v)")
        lines.append("    %s.push_back(static_cast<%s>(%s));" % (c, element.cpp, conv))
    elif isinstance(element, Struct):
        lines += ["    if (Z_TYPE_P(cl_v) == IS_OBJECT) {",
                  "      %s cl_s;" % element.cls,
                  "      %s(Z_OBJ_P(cl_v), &cl_s);" % names.from_zval_fn(element.cls),
                  "      %s.push_back(cl_s);" % c,
                  "    }"]
    else:
        raise AssertionError(element)
    lines += ["  } ZEND_HASH_FOREACH_END();", "}"]
    return lines


def arginfo_lines(var, plan, inputs, results):
    required = len(inputs)
    lines = [begin_arginfo(var, required, [(n, k) for n, k, _ in results], False, nullable=success_flag(plan))]
    for inp in inputs:
        lines.append(arg_entry(inp.php_name, inp.kind, inp.nullable if inp.nullable is not None else None))
    lines.append("ZEND_END_ARG_INFO()")
    return lines


def function_lines(names, scope, class_key, owner_cpp, plan, inputs, call_args, outs, results, bytes_out):
    depth = scope.depth(owner_cpp) if owner_cpp else 0
    """The body of ZEND_METHOD(class_key, CefName)."""
    L = ["ZEND_METHOD(%s, %s) {" % (class_key, plan.cef_name)]
    for inp in inputs:
        L += ["  " + d for d in inp.decl]
    if inputs:
        L.append("  ZEND_PARSE_PARAMETERS_START(%d, %d)" % (len(inputs), len(inputs)))
        L += ["  " + i.zpp for i in inputs]
        L.append("  ZEND_PARSE_PARAMETERS_END();")
    else:
        L.append("  ZEND_PARSE_PARAMETERS_NONE();")
    guard = NEEDS_CEF_RUNNING.get((plan.owner, plan.cef_name))
    if guard:
        L += ["  if (!cl::g_cef_running) {",
              "    zend_throw_error(nullptr, %s);" % cstr(guard),
              "    RETURN_THROWS();",
              "  }"]
    if plan.static:
        # Nothing of CEF exists yet when a static function is called: a build that loads libcef.so when it is needed
        # does it now, and PHP gets an error instead of a dead process if the runtime is missing.
        L += ["#ifdef CEFLOOM_LAZY_LIBCEF",
              "  {",
              "    std::string cl_load_error;",
              "    if (!cl_core_load_cef(&cl_load_error)) {",
              "      zend_throw_error(nullptr, \"%s\", cl_load_error.c_str());",
              "      RETURN_THROWS();",
              "    }",
              "  }",
              "#endif"]
        target = "%s::" % owner_cpp if owner_cpp else ""
    else:
        L += ["  %s* cl_self = cl::self<%s>(Z_OBJ_P(ZEND_THIS), %d);" % (owner_cpp, owner_cpp, depth),
              "  if (!cl_self) { RETURN_THROWS(); }"]
        target = "cl_self->"
    for inp in inputs:
        L += ["  " + c for c in inp.conv]
    L += ["  " + o for o in outs]
    if (plan.owner, plan.cef_name) in BEFORE_CALL:
        L.append("  " + BEFORE_CALL[(plan.owner, plan.cef_name)])

    call = "%s%s(%s)" % (target, plan.cef_name, ", ".join(call_args))
    if (plan.owner, plan.cef_name) in OFF_UI_THREAD:
        if isinstance(plan.ret, Void):
            L.append("  cl::off_ui_thread([&]() { %s; return true; });" % call)
        else:
            L.append("  auto cl_ret = cl::off_ui_thread([&]() { return %s; });" % call)
    elif isinstance(plan.ret, Void):
        L.append("  %s;" % call)
    else:
        L.append("  auto cl_ret = %s;" % call)

    if success_flag(plan):
        L.append("  if (!cl_ret) { RETURN_NULL(); }")
    if bytes_out:
        o, size = bytes_out
        trim = "%s.size()" % o
        if not isinstance(plan.ret, Void) and isinstance(plan.ret, Prim):
            if size:
                trim = "std::min<size_t>(%s.size(), static_cast<size_t>(cl_ret) * static_cast<size_t>(%s))" % (o, size)
            else:
                trim = "std::min<size_t>(%s.size(), static_cast<size_t>(cl_ret < 0 ? 0 : cl_ret))" % o
        L.append("  RETURN_STRINGL(%s.data(), %s);" % (o, trim))
    elif not results:
        pass
    elif len(results) == 1:
        label, kind, expr = results[0]
        L += to_zval(names, scope, kind, expr, "return_value", "  ")
    else:
        L.append("  array_init(return_value);")
        for n, (label, kind, expr) in enumerate(results):
            L.append("  {")
            L.append("    zval cl_z;")
            L += to_zval(names, scope, kind, expr, "&cl_z", "    ")
            L.append("    add_next_index_zval(return_value, &cl_z);")
            L.append("  }")
    L.append("}")
    return L


def method_entry(php_name, class_key, cef_name, arginfo_var, static, handwritten=False):
    flags = ACCESS + (" | ZEND_ACC_STATIC" if static else "")
    return '  ZEND_RAW_FENTRY(%s, zim_%s_%s, %s, %s, NULL, NULL)' % (cstr(php_name), class_key, cef_name, arginfo_var, flags)


class LibEmitter:
    def __init__(self, model, scope):
        self.model = model
        self.scope = scope
        self.names = Names(model, scope)

    def methods(self, class_key, owner_cpp, plans):
        """(code lines, entries, listing) for the supported plans of a class."""
        code, entries, listing = [], [], []
        for plan in plans:
            if (owner_cpp and plan.static and plan.cef_name == "Create" + py_class_name(owner_cpp)
                    and not self.scope.in_hierarchy(owner_cpp)):
                # CommandLine::createCommandLine() is CommandLine::create(). (Not in the Views: a static method
                # of a subclass has to be compatible with the one of its parent in PHP, and their arguments differ.)
                plan.name = "create"
            if not owner_cpp and plan.cef_name.startswith("Cef"):
                # a global function CefPostTask is Cef::postTask
                plan.name = plan.cef_name[3:4].lower() + plan.cef_name[4:]
            if not plan.supported:
                listing.append((plan, "not generated: " + plan.reason))
                continue
            inputs, call_args, outs, results, bytes_out = build(self.names, self.scope, plan)
            var = "arginfo_%s_%s" % (class_key, plan.cef_name)
            code += arginfo_lines(var, plan, inputs, results)
            hand = (plan.owner, plan.cef_name) in HANDWRITTEN_METHODS
            if hand:
                code.append("ZEND_METHOD(%s, %s);  // written by hand: %s" % (
                    class_key, plan.cef_name, HANDWRITTEN_METHODS[(plan.owner, plan.cef_name)]))
                listing.append((plan, "handwritten"))
            else:
                code += function_lines(self.names, self.scope, class_key, owner_cpp, plan, inputs, call_args, outs,
                                       results, bytes_out)
                listing.append((plan, "generated"))
            code.append("")
            entries.append(method_entry(plan.name, class_key, plan.cef_name, var, plan.static))
        return code, entries, listing

    def wrapper(self, cls_name, plans):
        """cl_wrap_<Class>: wraps a CefRefPtr of the class in a PHP object. A class that has subclasses in PHP is
        wrapped as the most derived one (a CefView that is a CefWindow becomes a Window): the accessors As<Child> tell
        the real type. The wrapper keeps a typed pointer for every class of the chain (see cl_runtime.h)."""
        children = self.scope.python_children(cls_name)
        L = ["void cl_wrap_%s(zval* rv, %s* p) {" % (cls_name, cls_name),
             "  if (!p) { ZVAL_NULL(rv); return; }"]
        accessors = {pl.cef_name: pl for pl in plans if pl.supported and isinstance(pl.ret, LibRef)}
        for child in children:
            accessor = "As" + py_class_name(child)
            if accessor in accessors:
                L += ["  {",
                      "    CefRefPtr<%s> cl_child = p->%s();" % (child, accessor),
                      "    if (cl_child) { cl_wrap_%s(rv, cl_child.get()); return; }" % child,
                      "  }"]
        L += ["  object_init_ex(rv, %s);" % ce_var(cls_name),
              "  cl::LibObject* cl_o = cl::lib_from(Z_OBJ_P(rv));"]
        for depth, ancestor in enumerate(self.scope.chain(cls_name)):
            L.append("  cl_o->up[%d] = static_cast<%s*>(p);" % (depth, ancestor))
        L += ["  cl_o->ptr = p;", "  p->AddRef();", "}"]
        return L

    def emit_class(self, cls, plans):
        name = cls.get_name()
        parent = self.scope.python_parent(name)
        code, entries, listing = self.methods(name, name, plans)
        L = ['#include "cl_gen.h"', "", "zend_class_entry* %s = nullptr;" % ce_var(name), ""]
        L += code
        L += self.wrapper(name, plans)
        L.append("")
        L.append("static const zend_function_entry methods_%s[] = {" % name)
        L.append('  ZEND_RAW_FENTRY("__construct", cl_private_ctor, arginfo_cl_private_ctor, ZEND_ACC_PRIVATE | ZEND_ACC_CTOR, NULL, NULL)')
        L += entries
        L += ["  ZEND_FE_END", "};", ""]
        L += ["void cl_register_%s() {" % name,
              "  zend_class_entry ce;",
              '  INIT_NS_CLASS_ENTRY(ce, "%s", %s, methods_%s);' % (NS, cstr(py_class_name(name)), name),
              "  %s = zend_register_internal_class_ex(&ce, %s);" % (ce_var(name), ce_var(parent) if parent else "nullptr")]
        if not parent:
            L.append("  %s->create_object = cl::lib_create;" % ce_var(name))
        flags = "ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE"
        if not self.scope.python_children(name):
            flags += " | ZEND_ACC_FINAL"
        L += ["  %s->ce_flags |= %s;" % (ce_var(name), flags), "}", ""]
        return "\n".join(L), listing

    def emit_cef(self, plans):
        """The global functions as static methods of Cefloom\\Cef (the hand written ones are added at runtime)."""
        code, entries, listing = self.methods("Cef", "", plans)
        L = ['#include "cl_gen.h"', "", "zend_class_entry* ce_Cef = nullptr;", ""]
        L += code
        L.append("const zend_function_entry cl_generated_cef_methods[] = {")
        L += entries
        L += ["  ZEND_FE_END", "};", ""]
        L += ["void cl_register_Cef() {",
              "  zend_class_entry ce;",
              '  INIT_NS_CLASS_ENTRY(ce, "%s", "Cef", cl_generated_cef_methods);' % NS,
              "  ce_Cef = zend_register_internal_class_ex(&ce, nullptr);",
              "  ce_Cef->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;",
              "}", ""]
        return "\n".join(L), listing
