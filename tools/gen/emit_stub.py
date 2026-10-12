"""Emit php/cefloom.stub.php: the declarations of everything the extension defines, for IDEs and static
analysers (the file is never loaded by PHP)."""

from emit_common import NS
from emit_lib import success_flag
from model import py_class_name
from scope import NATIVE_ONLY
from typesys import (Buffer, Bytes, ClientRef, Enum, Ignored, ItemBytes, LibRef, Planes, Prim, Str, StrMap, Struct,
                     Time, Vector, Void)

CORE_STUB = '''
        /** Start CEF in this process (the PHP process is the CEF browser process). */
        public static function initialize(?Settings $settings = null, ?App $app = null): bool {}
        /** Run one iteration of the CEF message loop. Raises the exception a handler left pending. */
        public static function doMessageLoopWork(): void {}
        /** Run the message loop until quit() is called, $tick returns false, or (by default) the last browser has closed. $tick, if given, is called after every iteration. */
        public static function run(?callable $tick = null, bool $quitWhenLastBrowserCloses = true): void {}
        /** Make run() return. */
        public static function quit(): void {}
        /** Close the browsers and shut CEF down. Drop every reference to CEF objects first. */
        public static function shutdown(): void {}
        public static function isRunning(): bool {}
        /** The version of CEF this module was built against. The runtime must be the same. */
        public static function cefVersion(): string {}
        /** The directory the module takes the CEF runtime from (CEFLOOM_HOME, the module directory, or the user directory). */
        public static function runtimeDirectory(): string {}
        /** The directory a runtime installed for the user goes into (the cefloom-runtime tool installs there). */
        public static function userRuntimeDirectory(): string {}
        /** Register a custom scheme (before initialize()). Serve it with registerSchemeHandlerFactory(). */
        public static function registerCustomScheme(string $name, int $options = \\Cefloom\\Types\\SchemeOptions::STANDARD | \\Cefloom\\Types\\SchemeOptions::SECURE | \\Cefloom\\Types\\SchemeOptions::CORS_ENABLED | \\Cefloom\\Types\\SchemeOptions::FETCH_ENABLED): void {}
        /** Add a command line switch for CEF (before initialize()). */
        public static function addSwitch(string $name, string $value = ""): void {}
        /** Sleep until CEF asks for work or $maxMilliseconds have passed (for your own loop). Without Settings::externalMessagePump CEF announces no work, so the wait is at most 5 ms. */
        public static function wait(int $maxMilliseconds = 30): void {}
        /** Expose a PHP function to JavaScript as cefloom.call(name, ...args), which returns a Promise. */
        public static function expose(string $name, callable $function): void {}
'''


def php_type(kind, nullable=False):
    if isinstance(kind, Prim):
        return {"bool": "bool", "float": "float"}.get(kind.py, "int")
    if isinstance(kind, (Str, Bytes)):
        return "string"
    if isinstance(kind, Enum):
        return "int"
    if isinstance(kind, (LibRef, ClientRef)):
        return ("?" if nullable else "") + "\\%s\\%s" % (NS, py_class_name(kind.cls))
    if isinstance(kind, Struct):
        return "\\%s\\%s" % (NS, py_class_name(kind.cls))
    if isinstance(kind, (Vector, StrMap, Planes)):
        return "array"
    if isinstance(kind, Time):
        return "?\\DateTimeImmutable" if nullable else "\\DateTimeInterface|int|float|null"
    if isinstance(kind, Buffer):
        return ("?" if nullable else "") + "\\%s\\Buffer" % NS
    return "mixed"


def signature(plan, client=False):
    ins = []
    bytes_out = None
    for p in plan.params:
        if isinstance(p.kind, Ignored):
            continue
        if isinstance(p.kind, Bytes):
            if p.out:
                ins.append("int $size")
                bytes_out = True
            else:
                ins.append("string $%s" % p.name)
            continue
        if isinstance(p.kind, ItemBytes):
            if p.out:
                ins += ["int $n", "int $size"]
                bytes_out = True
            else:
                ins += ["string $data", "int $size"]
            continue
        if p.out and not p.inout:
            continue
        type_text = php_type(p.kind, getattr(p, "optional", False))
        if not client and isinstance(p.kind, Struct) and getattr(p, "optional", False):
            type_text = "?" + type_text      # a struct that a library method takes by pointer may be passed as null
                                             # (a handler gets structs by reference: never null)
        ins.append("%s $%s" % (type_text, p.name))
    if bytes_out:
        ret = "string"
    else:
        results = plan.results
        if not client and success_flag(plan):
            results = results[1:]
        if not results:
            ret = "void"
        elif len(results) == 1:
            ret = php_type(results[0][1], True)
        else:
            ret = "array"
        if not client and success_flag(plan):
            ret = "?" + ret if not ret.startswith("?") else ret
    return ", ".join(ins), ret


def emit(model, scope, listings, version, banner):
    L = ["<?php", "", "/** " + banner + " */", "/** CEF " + version + " */", "", "namespace %s {" % NS, ""]

    # structs
    for k in sorted(model.structs):
        si = model.structs[k]
        L.append("    final class %s {" % py_class_name(si.cls))
        for f in si.fields:
            if f.string:
                t = "string"
            elif f.time:
                t = "?\\DateTimeImmutable"
            elif f.struct:
                t = "\\%s\\%s" % (NS, py_class_name(f.struct))
            elif f.py == "bool":
                t = "bool"
            elif f.py == "float":
                t = "float"
            else:
                t = "int"
            L.append("        public %s $%s;" % (t, f.name))
        args = []
        for f in si.fields:
            if f.string:
                args.append('string $%s = ""' % f.name)
            elif f.time:
                args.append("\\DateTimeInterface|int|float|null $%s = null" % f.name)
            elif f.struct:
                args.append("?\\%s\\%s $%s = null" % (NS, py_class_name(f.struct), f.name))
            elif f.py == "bool":
                args.append("bool $%s = false" % f.name)
            elif f.py == "float":
                args.append("float $%s = 0.0" % f.name)
            else:
                args.append("int $%s = 0" % f.name)
        L.append("        public function __construct(%s) {}" % ", ".join(args))
        L.append("    }")
        L.append("")

    # library and client classes
    for name, (side, listing) in sorted(listings.items()):
        if side == "functions":
            continue
        cls = name
        parent = scope.python_parent(cls) if side == "library" else None
        methods = [(p, s) for p, s in listing if s in ("generated", "handwritten")]
        if side == "client":
            methods = [(p, s) for p, s in methods if (cls, p.cef_name) not in NATIVE_ONLY]
            abstract = any(model.is_pure_virtual(model.classes[cls], p.node) for p, _ in methods)
            head = "    %sclass %s {" % ("abstract " if abstract else "", py_class_name(cls))
        else:
            head = "    %sclass %s%s {" % ("" if scope.python_children(cls) else "final ", py_class_name(cls),
                                           " extends %s" % py_class_name(parent) if parent else "")
            if False:
                pass
        L.append(head)
        if side == "library":
            L.append("        private function __construct() {}")
        for p, _ in methods:
            args, ret = signature(p, client=side == "client")
            pure = side == "client" and model.is_pure_virtual(model.classes[cls], p.node)
            L.append("        public %s%sfunction %s(%s): %s %s" % (
                "abstract " if pure else "", "static " if p.static else "", p.name, args, ret,
                ";" if pure else "{}"))
        L.append("    }")
        L.append("")

    # the global functions and the runtime
    L.append("    final class Cef {")
    for p, s in listings["Cef (global functions)"][1]:
        if s == "generated":
            args, ret = signature(p)
            L.append("        public static function %s(%s): %s {}" % (p.name, args, ret))
    L.append(CORE_STUB.rstrip("\n"))
    L.append("    }")
    L.append("")
    L.append("    /** A window on memory CEF owns, valid during the callback that received it. */")
    L.append("    final class Buffer {")
    L += ["        public function getSize(): int {}", "        public function isValid(): bool {}",
          "        public function isWritable(): bool {}",
          "        public function read(int $offset = 0, ?int $length = null): string {}",
          "        public function write(string $data, int $offset = 0): int {}", "    }", "}", ""]

    # enumerations
    L.append("namespace %s\\Types {" % NS)
    for cname in sorted(model.enum_defs):
        info = model.enum_defs[cname]
        L.append("    final class %s {" % info.py_name)
        for member, value in info.members:
            import re
            name = re.sub(r"\W", "_", member)
            if name.lower() == "class":
                name += "_"
            L.append("        public const %s = %d;" % (name, value))
        L.append("    }")
    L += ["}", ""]
    return "\n".join(L)
