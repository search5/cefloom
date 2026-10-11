"""Emit the classes the application implements (handlers, callbacks, delegates).

For every client class of CEF this makes
  * a PHP class (Cefloom\\LoadHandler) the user extends. A method the user does not override is not called
    from C++: CEF behaves as if no handler method existed;
  * a C++ proxy (CefLoadHandler implemented by Proxy_CefLoadHandler) that calls the methods of the PHP
    object. It runs PHP on the PHP thread (cl::run_on_php_thread), so CEF may call it from any thread;
  * a forwarder (Fwd_CefLoadHandler) for hand written handlers that observe an event and pass it on.

What a PHP handler method gets and returns: the inputs of the C++ method in order (outputs are not passed,
an in/out value is), and it returns the C++ return value followed by the outputs: nothing for none, the value
itself for one, an array for several.
"""

import re

from emit_common import (NS, Names, arg_entry, begin_arginfo, ce_var, cstr, element_cpp, php_fqn_c, to_zval,
                         type_of, wrap_call)
from model import py_class_name
from scope import ASYNC_METHODS, CUSTOM_PROXY, NATIVE_ONLY
from typesys import (REMEMBER, Buffer, ClientRef, Enum, Ignored, LibRef, Planes, Prim, Str, Struct, Time, Vector,
                     Void)


def declaration(param):
    """The parameter as the CEF header declares it."""
    if isinstance(param.kind, Planes):
        return "const float** %s, int %s" % (param.cef_name, param.size_name)
    if isinstance(param.kind, Buffer):
        if param.kind.size_expr:
            return "%svoid* %s" % ("const " if param.kind.readonly else "", param.cef_name)
        return "%svoid* %s, %s %s" % ("const " if param.kind.readonly else "", param.cef_name,
                                      param.kind.size_cpp, param.size_name)
    text = ("const " if param.const else "") + param.spelled + ("&" if param.byref else "*" if param.byaddr else "")
    return "%s %s" % (text, param.cef_name)


def call_arguments(plan):
    names = []
    for param in plan.params:
        if param.is_return:
            continue
        names.append(param.cef_name)
        if isinstance(param.kind, Planes) or (isinstance(param.kind, Buffer) and not param.kind.size_expr):
            names.append(param.size_name)
    return names


def default_return(plan):
    kind = plan.ret
    if isinstance(kind, Void) and not plan.void_return:
        return "return %s();" % plan.ret_spelled
    if isinstance(kind, Void):
        return "return;"
    if isinstance(kind, (ClientRef, LibRef)):
        return "return nullptr;"
    return "return %s();" % plan.ret_spelled


def default_zval(names, kind, target, ind="  "):
    """Lines that set |target| (a zval*) to the value a method of the PHP base class returns (the CEF default)."""
    if isinstance(kind, Prim):
        return [ind + ("ZVAL_FALSE(%s);" if kind.py == "bool" else "ZVAL_DOUBLE(%s, 0.0);" if kind.py == "float"
                       else "ZVAL_LONG(%s, 0);") % target]
    if isinstance(kind, Str):
        return [ind + "ZVAL_EMPTY_STRING(%s);" % target]
    if isinstance(kind, Enum):
        return [ind + "ZVAL_LONG(%s, 0);" % target]
    if isinstance(kind, Struct):
        return [ind + "{", ind + "  %s cl_d;" % kind.cls, ind + "  %s(%s, cl_d);" % (names.to_zval_fn(kind.cls), target),
                ind + "}"]
    if isinstance(kind, Vector):
        return [ind + "array_init(%s);" % target]
    return [ind + "ZVAL_NULL(%s);" % target]


class ClientEmitter:
    def __init__(self, model, scope):
        self.model = model
        self.scope = scope
        self.names = Names(model, scope)

    # -- the PHP side ----------------------------------------------------------------------------------

    def php_inputs(self, plan):
        return [p for p in plan.params if (not p.out or p.inout) and not isinstance(p.kind, Ignored)]

    def arginfo(self, cls, plan):
        var = "arginfo_%s_%s" % (cls, plan.cef_name)
        ins = self.php_inputs(plan)
        results = [(n, k) for n, k in plan.results]
        lines = [begin_arginfo(var, len(ins), results, True)]
        for p in ins:
            lines.append(arg_entry(p.name, p.kind, False if isinstance(p.kind, (LibRef, ClientRef)) else None)
                         if not isinstance(p.kind, ClientRef) else
                         "ZEND_ARG_OBJ_INFO(0, %s, %s, 1)" % (p.name, php_fqn_c(p.kind.cls)))
        lines.append("ZEND_END_ARG_INFO()")
        return var, lines

    def stub_function(self, cls, plan):
        """The default body of a method of the PHP base class: what CEF does without a handler."""
        L = ["ZEND_METHOD(%s, %s) {" % (cls, plan.cef_name)]
        results = plan.results
        if not results:
            pass
        elif len(results) == 1:
            L += default_zval(self.names, results[0][1], "return_value")
        else:
            L.append("  array_init(return_value);")
            for _, kind in results:
                L += ["  {", "    zval cl_z;"]
                L += default_zval(self.names, kind, "&cl_z", "    ")
                L += ["    add_next_index_zval(return_value, &cl_z);", "  }"]
        L.append("}")
        return L

    # -- the C++ proxy -----------------------------------------------------------------------------------

    def proxy_method(self, model_cls, plan, hook):
        cls = model_cls.get_name()
        params = ", ".join(declaration(p) for p in plan.params if not p.is_return)
        const = " const" if plan.const_method else ""
        L = ["  %s %s(%s)%s override {" % (plan.ret_spelled, plan.cef_name, params, const)]
        native = NATIVE_ONLY.get((cls, plan.cef_name))
        if native:
            L += ["    " + native, "  }"]
            return L

        fallback = []
        if self.model.is_pure_virtual(model_cls, plan.node):
            fallback.append("    " + default_return(plan))
        else:
            call = "%s::%s(%s)" % (cls, plan.cef_name, ", ".join(call_arguments(plan)))
            if plan.void_return:
                fallback += ["    %s;" % call, "    return;"]
            else:
                fallback.append("    return %s;" % call)
        L.append("    zend_function* cl_fn = hooks_[%d];" % hook)
        L.append("    if (!cl_fn) {")
        L += ["  " + f for f in fallback]
        L.append("    }")

        if (cls, plan.cef_name) in ASYNC_METHODS:
            args = ", ".join(["CefRefPtr<%s>(this)" % cls, "object()", "cl_fn"] + call_arguments(plan))
            L.append("    %scl_async::%s_%s(%s);" % ("" if plan.void_return else "return ", cls, plan.cef_name, args))
            L.append("  }")
            return L

        for remembered, member in [REMEMBER.get((cls, plan.cef_name), (None, None))][:1]:
            if remembered:
                L.append("    %s = %s;" % (member, remembered))

        ins = self.php_inputs(plan)
        # the value the method returns, and the outputs, before the call
        if not isinstance(plan.ret, Void):
            if isinstance(plan.ret, (ClientRef, LibRef)):
                L.append("    %s cl_result;" % plan.ret_spelled)
            else:
                L.append("    %s cl_result = %s();" % (plan.ret_spelled, plan.ret_spelled))
        for p in plan.outs:
            L.append("    " + self.out_local(p))

        L.append("    cl::run_on_php_thread([&]() {")
        n = len(ins)
        if n:
            L.append("      zval cl_args[%d];" % n)
        else:
            L.append("      zval* cl_args = nullptr;")
        for j, p in enumerate(ins):
            L += self.to_php(p, "&cl_args[%d]" % j)
        L += ["      zval cl_rv;", "      ZVAL_UNDEF(&cl_rv);",
              "      bool cl_ok = cl::call_method(object(), cl_fn, %d, cl_args, &cl_rv);" % n]
        results = []
        if not isinstance(plan.ret, Void):
            results.append(("return", plan.ret, plan.ret_spelled, "cl_result"))
        for p in plan.outs:
            results.append((p.name, p.kind, p.spelled, "o_" + p.cef_name))
        if results:
            L.append("      if (cl_ok) {")
            for i, (label, kind, spelled, target) in enumerate(results):
                body = self.from_php(kind, spelled, target)
                if body:
                    L.append("        if (zval* cl_r = cl::result_at(&cl_rv, %d, %d)) {" % (len(results), i))
                    L += ["          " + b for b in body]
                    L.append("        }")
            L.append("      }")
        L.append("      if (Z_TYPE(cl_rv) != IS_UNDEF) zval_ptr_dtor(&cl_rv);")
        if n:
            L += ["      for (int cl_i = 0; cl_i < %d; ++cl_i) {" % n,
                  "        cl::invalidate_buffers(&cl_args[cl_i]);",
                  "        zval_ptr_dtor(&cl_args[cl_i]);",
                  "      }"]
        L.append("    });")

        if plan.clamp_return:
            L.append("    if (cl_result > %s) cl_result = %s;" % (plan.clamp_return, plan.clamp_return))
        for p in plan.outs:
            if p.is_return:
                L.append("    return o_%s;" % p.cef_name)
            elif p.byaddr:
                L.append("    if (%s) *%s = o_%s;" % (p.cef_name, p.cef_name, p.cef_name))
            else:
                L.append("    %s = o_%s;" % (p.cef_name, p.cef_name))
        if not isinstance(plan.ret, Void):
            L.append("    return cl_result;")
        L.append("  }")
        return L

    def unsupported_override(self, plan):
        """A pure virtual method of CEF that PHP cannot implement (a type this binding does not have): the proxy still
        has to override it, and does nothing."""
        def raw(argument):
            a = argument.get_type()
            return "%s%s%s%s %s" % ("const " if a.is_const() else "", a.get_type(), "&" if a.is_byref() else "",
                                    "*" if a.is_byaddr() else "", argument.get_name())
        ret = plan.node.get_retval().get_type()
        ret_text = "%s%s%s" % ("const " if ret.is_const() else "", ret.get_type(), "*" if ret.is_byaddr() else "")
        const = " const" if plan.const_method else ""
        L = ["  // not exposed to PHP: %s" % plan.reason,
             "  %s %s(%s)%s override {" % (ret_text, plan.cef_name,
                                           ", ".join(raw(a) for a in plan.node.get_arguments()), const)]
        L.append("    return;" if ret_text == "void" else "    return %s();" % ret_text)
        L.append("  }")
        return L

    def out_local(self, p):
        name = p.cef_name
        if p.is_return:
            return "%s o_%s;" % (p.kind.cls, name)
        if isinstance(p.kind, Str):
            return "CefString o_%s = %s;" % (name, name)
        if p.byaddr:
            return "%s o_%s = %s ? *%s : %s();" % (p.spelled, name, name, name, p.spelled)
        return "%s o_%s = %s;" % (p.spelled, name, name)

    def to_php(self, p, target):
        """Lines (inside the lambda) that put the parameter |p| into the zval at |target|."""
        k = p.kind
        name = p.cef_name
        ind = "      "
        if isinstance(k, Buffer):
            size = k.size_expr or p.size_name
            return [ind + "cl::buffer_new(%s, const_cast<void*>(static_cast<const void*>(%s)), %s, %s);" % (
                target, name, size, "false" if k.readonly else "true")]
        if isinstance(k, Planes):
            return [ind + "array_init(%s);" % target,
                    ind + "for (int cl_c = 0; cl_c < %s.load(); ++cl_c) {" % k.count_member,
                    ind + "  zval cl_b;",
                    ind + "  cl::buffer_new(&cl_b, const_cast<float*>(%s[cl_c]), static_cast<size_t>(%s) * sizeof(float), false);" % (
                        name, p.size_name),
                    ind + "  add_next_index_zval(%s, &cl_b);" % target,
                    ind + "}"]
        expr = "o_" + name if p.inout else name
        return to_zval(self.names, self.scope, k, expr, target, ind)

    def from_php(self, kind, spelled, target):
        """Lines that read the zval |cl_r| the PHP method returned into the C++ value |target|."""
        if isinstance(kind, Prim):
            conv = {"bool": "zend_is_true(cl_r)", "float": "zval_get_double(cl_r)"}.get(kind.py, "zval_get_long(cl_r)")
            if kind.py == "bool":
                return ["%s = zend_is_true(cl_r);" % target]
            return ["%s = static_cast<%s>(%s);" % (target, kind.cpp, conv)]
        if isinstance(kind, Str):
            return ["%s = cl::to_cef(cl_r);" % target]
        if isinstance(kind, Enum):
            return ["%s = static_cast<%s>(zval_get_long(cl_r));" % (target, spelled)]
        if isinstance(kind, LibRef):
            return ["if (Z_TYPE_P(cl_r) == IS_OBJECT && instanceof_function(Z_OBJCE_P(cl_r), %s)) {" % ce_var(kind.cls),
                    "  %s = cl::lib_ptr<%s>(Z_OBJ_P(cl_r), %d);" % (target, kind.cls, self.scope.depth(kind.cls)), "}"]
        if isinstance(kind, ClientRef):
            return ["if (Z_TYPE_P(cl_r) == IS_OBJECT && instanceof_function(Z_OBJCE_P(cl_r), %s)) {" % ce_var(kind.cls),
                    "  %s = cl_make_%s(Z_OBJ_P(cl_r));" % (target, kind.cls), "}"]
        if isinstance(kind, Struct):
            return ["if (Z_TYPE_P(cl_r) == IS_OBJECT) {",
                    "  %s(Z_OBJ_P(cl_r), &%s);" % (self.names.from_zval_fn(kind.cls), target), "}"]
        return []

    def forwarder(self, model_cls, plans):
        cls = model_cls.get_name()
        member = "forward_"
        L = ["class Fwd_%s : public %s {" % (cls, cls), " protected:",
             "  CefRefPtr<%s> %s;" % (cls, member), "", " public:"]
        for plan in plans:
            params = ", ".join(declaration(p) for p in plan.params if not p.is_return)
            const = " const" if plan.const_method else ""
            args = ", ".join(call_arguments(plan))
            L.append("  %s %s(%s)%s override {" % (plan.ret_spelled, plan.cef_name, params, const))
            L.append("    if (!%s) {" % member)
            if self.model.is_pure_virtual(model_cls, plan.node):
                L.append("      " + default_return(plan))
            elif plan.void_return:
                L += ["      %s::%s(%s);" % (cls, plan.cef_name, args), "      return;"]
            else:
                L.append("      return %s::%s(%s);" % (cls, plan.cef_name, args))
            L.append("    }")
            L.append("    %s%s->%s(%s);" % ("" if plan.void_return else "return ", member, plan.cef_name, args))
            L.append("  }")
            L.append("")
        L[-1:] = ["};", ""]
        return L

    # -- the file of a class ------------------------------------------------------------------------------

    def emit_class(self, model_cls, all_plans):
        cls = model_cls.get_name()
        plans = [p for p in all_plans if p.supported and not p.static]
        listing = [(p, "generated") for p in plans] + [(p, "not generated: " + p.reason) for p in all_plans
                                                         if not p.supported]
        php_plans = [p for p in plans if (cls, p.cef_name) not in NATIVE_ONLY]
        abstract = [p for p in php_plans if self.model.is_pure_virtual(model_cls, p.node)]
        members = sorted({m for (c, _), (_, m) in REMEMBER.items() if c == cls})

        L = ['#include "cl_gen.h"', "", "zend_class_entry* %s = nullptr;" % ce_var(cls), ""]

        # the PHP class
        entries = []
        for p in php_plans:
            var, lines = self.arginfo(cls, p)
            L += lines
            if p in abstract:
                entries.append('  ZEND_RAW_FENTRY(%s, NULL, %s, ZEND_ACC_PUBLIC | ZEND_ACC_ABSTRACT, NULL, NULL)' % (
                    cstr(p.name), var))
            else:
                L += self.stub_function(cls, p)
                entries.append('  ZEND_RAW_FENTRY(%s, zim_%s_%s, %s, ZEND_ACC_PUBLIC, NULL, NULL)' % (
                    cstr(p.name), cls, p.cef_name, var))
            L.append("")
        L.append("static const zend_function_entry methods_%s[] = {" % cls)
        L += entries
        L += ["  ZEND_FE_END", "};", ""]

        for async_cls, async_method in sorted(ASYNC_METHODS):
            if async_cls != cls:
                continue
            plan = next(p for p in plans if p.cef_name == async_method)
            params = ", ".join(["CefRefPtr<%s> self, zend_object* object, zend_function* fn" % cls] +
                               [declaration(p) for p in plan.params if not p.is_return])
            line = "%s %s_%s(%s);" % (plan.ret_spelled, cls, async_method, params)
            # typedefs of the CEF class are not visible in the namespace of the async functions
            for local, global_name in (("ReturnValue", "cef_return_value_t"), ("URLRequestStatus", "cef_urlrequest_status_t")):
                line = re.sub(r"\b%s\b" % local, global_name, line)
            L += ["namespace cl_async {", line, "}", ""]

        if cls in CUSTOM_PROXY:
            L.append("void cl_register_%s() {" % cls)
            L.append("  zend_class_entry ce;")
            L.append('  INIT_NS_CLASS_ENTRY(ce, "%s", %s, methods_%s);' % (NS, cstr(py_class_name(cls)), cls))
            L.append("  %s = zend_register_internal_class_ex(&ce, nullptr);" % ce_var(cls))
            if abstract:
                L.append("  %s->ce_flags |= ZEND_ACC_EXPLICIT_ABSTRACT_CLASS;" % ce_var(cls))
            L += ["}", ""]
            return "\n".join(L), listing

        # the proxy
        L.append("namespace {")
        L.append("")
        L.append("const char* const kHookNames_%s[] = {%s};" % (
            cls, ", ".join(cstr(p.name.lower()) for p in plans) or "nullptr"))
        L.append("")
        L.append("class Proxy_%s : public %s, public cl::ProxyBase {" % (cls, cls))
        L.append(" public:")
        L.append("  explicit Proxy_%s(zend_object* o) : cl::ProxyBase(o) {" % cls)
        L.append("    register_as(static_cast<%s*>(this));" % cls)
        L.append("    for (size_t cl_i = 0; cl_i < %d; ++cl_i) {" % len(plans))
        L.append("      hooks_[cl_i] = cl::find_override(o->ce, %s, kHookNames_%s[cl_i]);" % (ce_var(cls), cls))
        L.append("    }")
        L.append("  }")
        L.append("")
        for hook, plan in enumerate(plans):
            L += self.proxy_method(model_cls, plan, hook)
            L.append("")
        for plan in all_plans:
            if not plan.supported and not plan.static and self.model.is_pure_virtual(model_cls, plan.node):
                L += self.unsupported_override(plan)
                L.append("")
        L.append(" private:")
        for m in members:
            L.append("  std::atomic<int> %s{0};" % m)
        L.append("  zend_function* hooks_[%d] = {};" % max(len(plans), 1))
        L.append("  IMPLEMENT_REFCOUNTING(Proxy_%s);" % cls)
        L.append("  DISALLOW_COPY_AND_ASSIGN(Proxy_%s);" % cls)
        L.append("};")
        L.append("")
        L.append("}  // namespace")
        L.append("")
        L.append("CefRefPtr<%s> cl_make_%s(zend_object* o) { return new Proxy_%s(o); }" % (cls, cls, cls))
        L.append("")

        # the registration
        L.append("void cl_register_%s() {" % cls)
        L.append("  zend_class_entry ce;")
        L.append('  INIT_NS_CLASS_ENTRY(ce, "%s", %s, methods_%s);' % (NS, cstr(py_class_name(cls)), cls))
        L.append("  %s = zend_register_internal_class_ex(&ce, nullptr);" % ce_var(cls))
        flags = "ZEND_ACC_NO_DYNAMIC_PROPERTIES" if False else "0"
        if abstract:
            L.append("  %s->ce_flags |= ZEND_ACC_EXPLICIT_ABSTRACT_CLASS;" % ce_var(cls))
        L.append("}")
        L.append("")
        return "\n".join(L), listing
