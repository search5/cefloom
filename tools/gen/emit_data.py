"""Emit the value structs (Cefloom\\Rect, Cefloom\\Settings, ...) and the enumerations (Cefloom\\Types\\...).

A struct is a final PHP class with typed public properties and a constructor in which every argument is
optional, plus two C++ functions that convert it from and to the C struct. An enumeration is a final class
of integer constants (flags are combined as integers).
"""

import re

from emit_common import NS, cstr, php_fqn_c

PHP_RESERVED_CONSTANTS = {"class"}


def _prop_type(field):
    """(zend type expression, PHP default expression for arginfo, default zval statement, ZPP kind)."""
    if field.string:
        return "string", '"\\"\\""'
    if field.time:
        return "time", '"null"'
    if field.struct:
        return "struct", '"null"'
    if field.enum:
        return "int", '"0"'
    if field.py == "bool":
        return "bool", '"false"'
    if field.py == "float":
        return "float", '"0.0"'
    return "int", '"0"'


class DataEmitter:
    def __init__(self, model, names):
        self.model = model
        self.names = names

    # -- structs -----------------------------------------------------------------------------------------

    def struct_functions(self, si):
        cname = si.cname
        L = []
        L.append("void cl_to_zval_%s(zval* zv, const %s& s) {" % (cname, cname))
        L.append("  object_init_ex(zv, ce_%s);" % si.cls)
        L.append("  zend_object* o = Z_OBJ_P(zv);")
        L.append("  zval* p;")
        for i, f in enumerate(si.fields):
            L.append("  p = OBJ_PROP_NUM(o, %d);" % i)
            L.append("  zval_ptr_dtor(p);")
            kind, _ = _prop_type(f)
            if kind == "string":
                L.append("  cl::ret_str(p, s.%s);" % f.cname)
            elif kind == "time":
                L.append("  cl::ret_time(p, s.%s.val);" % f.cname)
            elif kind == "struct":
                L.append("  %s(p, s.%s);" % (self.names.to_zval_fn(f.struct), f.cname))
            elif kind == "bool":
                L.append("  ZVAL_BOOL(p, s.%s);" % f.cname)
            elif kind == "float":
                L.append("  ZVAL_DOUBLE(p, static_cast<double>(s.%s));" % f.cname)
            else:
                L.append("  ZVAL_LONG(p, static_cast<zend_long>(s.%s));" % f.cname)
        L.append("}")
        L.append("")
        L.append("void cl_from_zval_%s(zend_object* o, %s* s) {" % (cname, cname))
        L.append("  zval* p;")
        for i, f in enumerate(si.fields):
            L.append("  p = OBJ_PROP_NUM(o, %d);" % i)
            L.append("  if (Z_TYPE_P(p) != IS_UNDEF) {")
            kind, _ = _prop_type(f)
            if kind == "string":
                L.append("    cl::set_str(&s->%s, p);" % f.cname)
            elif kind == "time":
                L.append("    s->%s.val = cl::time_from(p);" % f.cname)
            elif kind == "struct":
                L.append("    if (Z_TYPE_P(p) == IS_OBJECT) %s(Z_OBJ_P(p), &s->%s);" % (
                    self.names.from_zval_fn(f.struct), f.cname))
            elif kind == "bool":
                L.append("    s->%s = zend_is_true(p);" % f.cname)
            elif kind == "float":
                L.append("    s->%s = static_cast<%s>(zval_get_double(p));" % (f.cname, f.cpp))
            else:
                L.append("    s->%s = static_cast<%s>(zval_get_long(p));" % (f.cname, f.cpp))
            L.append("  }")
        L.append("}")
        L.append("")
        L.append("void cl_default_%s(zval* zv) {" % cname)
        L.append("  %s d;" % cname)
        L.append("  memset(&d, 0, sizeof(d));")
        L.append("  cl_to_zval_%s(zv, d);" % cname)
        L.append("}")
        L.append("")
        return L

    def struct_constructor(self, si):
        cls = si.cls
        L = ["ZEND_BEGIN_ARG_INFO_EX(arginfo_%s___construct, 0, 0, 0)" % cls]
        for f in si.fields:
            kind, default = _prop_type(f)
            if kind == "string":
                L.append("ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, %s, IS_STRING, 0, %s)" % (f.name, default))
            elif kind == "time":
                L.append("ZEND_ARG_INFO_WITH_DEFAULT_VALUE(0, %s, \"null\")" % f.name)
            elif kind == "struct":
                L.append("ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, %s, %s, 1, \"null\")" % (f.name, php_fqn_c(f.struct)))
            elif kind == "bool":
                L.append("ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, %s, _IS_BOOL, 0, \"false\")" % f.name)
            elif kind == "float":
                L.append("ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, %s, IS_DOUBLE, 0, \"0.0\")" % f.name)
            else:
                L.append("ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, %s, IS_LONG, 0, \"0\")" % f.name)
        L.append("ZEND_END_ARG_INFO()")
        L.append("")
        L.append("ZEND_METHOD(%s, __construct) {" % cls)
        for i, f in enumerate(si.fields):
            kind, _ = _prop_type(f)
            if kind == "string":
                L.append("  zend_string* v%d = ZSTR_EMPTY_ALLOC();" % i)
            elif kind == "time":
                L.append("  zval* v%d = nullptr;" % i)
            elif kind == "struct":
                L.append("  zend_object* v%d = nullptr;" % i)
            elif kind == "bool":
                L.append("  bool v%d = false;" % i)
            elif kind == "float":
                L.append("  double v%d = 0;" % i)
            else:
                L.append("  zend_long v%d = 0;" % i)
        n = len(si.fields)
        L.append("  ZEND_PARSE_PARAMETERS_START(0, %d)" % n)
        L.append("  Z_PARAM_OPTIONAL")
        for i, f in enumerate(si.fields):
            kind, _ = _prop_type(f)
            macro = {"string": "Z_PARAM_STR(v%d)", "time": "Z_PARAM_ZVAL(v%d)",
                     "struct": "Z_PARAM_OBJ_OF_CLASS_OR_NULL(v%d, ce_" + f.struct + ")" if f.struct else "",
                     "bool": "Z_PARAM_BOOL(v%d)", "float": "Z_PARAM_DOUBLE(v%d)", "int": "Z_PARAM_LONG(v%d)"}[kind]
            L.append("  " + (macro % i if "%d" in macro else macro))
        L.append("  ZEND_PARSE_PARAMETERS_END();")
        L.append("  zend_object* o = Z_OBJ_P(ZEND_THIS);")
        L.append("  zval* p;")
        for i, f in enumerate(si.fields):
            kind, _ = _prop_type(f)
            L.append("  p = OBJ_PROP_NUM(o, %d);" % i)
            L.append("  zval_ptr_dtor(p);")
            if kind == "string":
                L.append("  ZVAL_STR_COPY(p, v%d);" % i)
            elif kind == "time":
                # a DateTimeInterface, an int or a float becomes the DateTimeImmutable the property is typed as
                L.append("  ZVAL_NULL(p);")
                L.append("  if (v%d && Z_TYPE_P(v%d) != IS_NULL && !cl::time_normalize(v%d, p)) {" % (i, i, i))
                L.append("    zend_argument_type_error(%d, \"must be of type DateTimeInterface|int|float|null, %%s given\", zend_zval_type_name(v%d));" % (i + 1, i))
                L.append("    RETURN_THROWS();")
                L.append("  }")
            elif kind == "struct":
                L.append("  if (v%d) { ZVAL_OBJ_COPY(p, v%d); } else { cl_default_%s(p); }" % (
                    i, i, self.model.structs[f.struct].cname))
            elif kind == "bool":
                L.append("  ZVAL_BOOL(p, v%d);" % i)
            elif kind == "float":
                L.append("  ZVAL_DOUBLE(p, v%d);" % i)
            else:
                L.append("  ZVAL_LONG(p, v%d);" % i)
        L.append("}")
        L.append("")
        return L

    def struct_registration(self, si):
        cls = si.cls
        L = ["static void register_%s() {" % cls,
             "  static const zend_function_entry methods[] = {",
             '    ZEND_RAW_FENTRY("__construct", zim_%s___construct, arginfo_%s___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR, NULL, NULL)' % (cls, cls),
             "    ZEND_FE_END};",
             "  zend_class_entry ce;",
             '  INIT_NS_CLASS_ENTRY(ce, "%s", %s, methods);' % (NS, cstr(cls[3:] if cls.startswith("Cef") else cls)),
             "  ce_%s = zend_register_internal_class_ex(&ce, nullptr);" % cls,
             "  ce_%s->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES;" % cls,
             "  zval def;",
             "  zend_string* n;"]
        for f in si.fields:
            kind, _ = _prop_type(f)
            L.append("  n = zend_string_init_interned(%s, %d, true);" % (cstr(f.name), len(f.name)))
            if kind == "string":
                L.append("  ZVAL_EMPTY_STRING(&def);")
                type_expr = "(zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING)"
            elif kind == "time":
                L.append("  ZVAL_NULL(&def);")
                L.append("  zend_string* t_%s = zend_string_init_interned(\"DateTimeImmutable\", 17, true);" % f.name)
                type_expr = "(zend_type) ZEND_TYPE_INIT_CLASS(t_%s, true, 0)" % f.name
            elif kind == "struct":
                L.append("  ZVAL_UNDEF(&def);")
                fq = php_fqn_c(f.struct)
                L.append("  zend_string* t_%s = zend_string_init_interned(%s, %d, true);" % (
                    f.name, cstr(fq.replace("\\\\", "\\")), len(fq.replace("\\\\", "\\"))))
                type_expr = "(zend_type) ZEND_TYPE_INIT_CLASS(t_%s, false, 0)" % f.name
            elif kind == "bool":
                L.append("  ZVAL_FALSE(&def);")
                type_expr = "(zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_BOOL)"
            elif kind == "float":
                L.append("  ZVAL_DOUBLE(&def, 0.0);")
                type_expr = "(zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_DOUBLE)"
            else:
                L.append("  ZVAL_LONG(&def, 0);")
                type_expr = "(zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG)"
            L.append("  zend_declare_typed_property(ce_%s, n, &def, ZEND_ACC_PUBLIC, NULL, %s);" % (cls, type_expr))
            L.append("  zend_string_release(n);")
        L += ["}", ""]
        return L

    def emit_structs(self):
        structs = [self.model.structs[k] for k in sorted(self.model.structs)]
        L = ['#include "cl_gen.h"', "", "#include <cstring>", ""]
        for si in structs:
            L.append("zend_class_entry* ce_%s = nullptr;" % si.cls)
        L.append("")
        for si in structs:
            L += self.struct_functions(si)
        for si in structs:
            L += self.struct_constructor(si)
        for si in structs:
            L += self.struct_registration(si)
        L.append("void cl_register_structs() {")
        for si in structs:
            L.append("  register_%s();" % si.cls)
        L += ["}", ""]
        return "\n".join(L)

    # -- enumerations ------------------------------------------------------------------------------------

    def emit_enums(self):
        L = ['#include "cl_gen.h"', "", "void cl_register_enums() {", "  zend_class_entry ce;",
             "  zend_class_entry* c;"]
        for cname in sorted(self.model.enum_defs):
            info = self.model.enum_defs[cname]
            L.append('  INIT_NS_CLASS_ENTRY(ce, "%s\\\\Types", %s, NULL);' % (NS, cstr(info.py_name)))
            L.append("  c = zend_register_internal_class_ex(&ce, nullptr);")
            L.append("  c->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;")
            for member, value in info.members:
                name = re.sub(r"\W", "_", member)
                if name.lower() in PHP_RESERVED_CONSTANTS:
                    name += "_"
                L.append("  zend_declare_class_constant_long(c, %s, %d, %dLL);" % (cstr(name), len(name), value))
        L += ["}", ""]
        return "\n".join(L)
