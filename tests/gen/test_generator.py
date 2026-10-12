"""Tests of the binding generator (tools/gen). They need no CEF runtime and no build.

Two sets of headers are used: a tiny made-up one (tests/gen/mini: two classes and a renderer-only class), whose output is
checked in detail, and the real CEF headers (the same ones tools/gen/generate.py reads), whose plans are checked for the
kinds of parameters that matter to the extension. The tests on the real headers are skipped when the headers are missing.

    python3 -m unittest discover -s tests/gen
"""

import os
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, "tools", "gen"))

import emit_stub  # noqa: E402
import generate  # noqa: E402
import model as model_module  # noqa: E402
import scope as scope_module  # noqa: E402
from typesys import (Buffer, ClientRef, Enum, LibRef, Prim, Str, StrMap, Void, plan_method)  # noqa: E402

MINI = os.path.join(HERE, "mini")
REAL = os.environ.get("CEF_ROOT", generate.DEFAULT_CEF_ROOT)
HAS_REAL = os.path.isfile(os.path.join(REAL, "include", "cef_version.h"))


class Naming(unittest.TestCase):
    def test_class_names_lose_the_cef_prefix(self):
        self.assertEqual(model_module.py_class_name("CefResourceHandler"), "ResourceHandler")
        self.assertEqual(model_module.py_class_name("Other"), "Other")

    def test_methods_begin_with_a_lower_case_letter(self):
        self.assertEqual(model_module.py_method_name("GetMainFrame"), "getMainFrame")
        self.assertEqual(model_module.py_method_name("GetURL"), "getURL")
        self.assertEqual(model_module.py_method_name("OnLoadEnd"), "onLoadEnd")

    def test_parameters_are_camel_case(self):
        self.assertEqual(model_module.py_param_name("popup_id"), "popupId")
        self.assertEqual(model_module.py_param_name("url"), "url")
        self.assertEqual(model_module.py_param_name("data_in_read"), "dataInRead")

    def test_snake_case(self):
        self.assertEqual(model_module.snake_case("GetURL"), "get_url")
        self.assertEqual(model_module.snake_case("OnLoadEnd"), "on_load_end")


class Mini(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.model, cls.scope, cls.files, cls.listings, cls.version = generate.build_all(MINI)
        cls.stub = emit_stub.emit(cls.model, cls.scope, cls.listings, cls.version, generate.BANNER)

    def test_the_version_comes_from_the_headers(self):
        self.assertEqual(self.version, "1.2.3+g0+chromium-9.9.9")
        for name in ("lib_CefMiniThing.cc", "client_CefMiniHandler.cc", "register_all.cc"):
            self.assertIn("// CEF 1.2.3+g0+chromium-9.9.9", self.files[name])

    def test_classes_are_sorted_into_library_and_client(self):
        self.assertEqual([c.get_name() for c in self.scope.library_classes], ["CefMiniThing"])
        self.assertEqual([c.get_name() for c in self.scope.client_classes], ["CefMiniHandler"])

    def test_renderer_only_classes_are_left_out(self):
        self.assertIn("CefV8Handler", scope_module.RENDERER_ONLY)
        self.assertFalse(self.scope.is_client("CefV8Handler"))
        self.assertNotIn("client_CefV8Handler.cc", self.files)
        self.assertNotIn("V8Handler", self.stub)

    def test_every_file_has_the_banner_except_sources(self):
        for name, text in self.files.items():
            self.assertIn("DO NOT EDIT", text.split("\n")[0] + text.split("\n")[1] if name != "sources.m4" else text, name)

    def test_register_all_registers_every_class(self):
        text = self.files["register_all.cc"]
        for name in ("cl_register_enums();", "cl_register_structs();", "cl_register_CefMiniThing();",
                     "cl_register_CefMiniHandler();", "cl_register_Cef();"):
            self.assertIn(name, text)
        self.assertLess(text.index("cl_register_enums"), text.index("cl_register_CefMiniThing"))

    def test_sources_list_every_generated_cc_file(self):
        listed = self.files["sources.m4"]
        for name in self.files:
            if name.endswith(".cc"):
                self.assertIn("generated/" + name, listed)

    def test_the_library_class_has_methods_with_php_names(self):
        text = self.files["lib_CefMiniThing.cc"]
        for entry in ('"getName"', '"getCount"', '"setCount"', '"create"'):
            self.assertIn("ZEND_RAW_FENTRY(" + entry, text)
        self.assertIn("ZEND_ACC_PUBLIC | ZEND_ACC_STATIC", text)           # create() is static
        self.assertIn("ZEND_ACC_PRIVATE | ZEND_ACC_CTOR", text)             # objects are made by CEF, not by new

    def test_the_client_class_has_a_proxy(self):
        text = self.files["client_CefMiniHandler.cc"]
        self.assertIn("Proxy_CefMiniHandler", text)
        self.assertIn("OnThing", text)
        self.assertIn("OnDecide", text)

    def test_the_stub_declares_the_classes_with_types(self):
        self.assertIn("final class MiniThing {", self.stub)
        self.assertIn("private function __construct() {}", self.stub)
        self.assertIn("public function getName(): string {}", self.stub)
        self.assertIn("public function setCount(int $count, bool $loud): void {}", self.stub)
        self.assertIn("public static function create(string $name): ?\\Cefloom\\MiniThing {}", self.stub)
        self.assertIn("class MiniHandler {", self.stub)
        self.assertIn("public function onThing(\\Cefloom\\MiniThing $thing, string $text): void {}", self.stub)
        self.assertIn("public function onDecide(int $value): bool {}", self.stub)

    def test_a_type_that_cannot_be_mapped_is_listed_as_not_generated(self):
        status = dict((plan.cef_name, s) for plan, s in self.listings["CefMiniHandler"][1])
        self.assertTrue(status["OnRaw"].startswith("not generated"), status)
        self.assertEqual(status["OnDecide"], "generated")
        self.assertNotIn("onRaw", self.stub)
        self.assertNotIn("OnRaw", self.files["client_CefMiniHandler.cc"])

    def test_the_output_is_the_same_every_time(self):
        again = generate.build_all(MINI)[2]
        self.assertEqual(again, self.files)

    def test_the_report_counts(self):
        report = generate.report_text(self.model, self.scope, self.listings, self.version)
        self.assertIn("생성하지 못함 1개", report)
        self.assertIn("CefMiniThing", report)


class Scope(unittest.TestCase):
    def test_the_rules_name_real_cef_methods(self):
        # a rule that names something that is not there is dead; checked against the real headers in WithHeaders
        self.assertIn(("CefResourceRequestHandler", "OnBeforeResourceLoad"), scope_module.ASYNC_METHODS)
        self.assertIn("CefResourceHandler", scope_module.CUSTOM_PROXY)
        self.assertIn(("CefBrowserProcessHandler", "OnScheduleMessagePumpWork"), scope_module.NATIVE_ONLY)

    def test_the_message_loop_is_written_by_hand(self):
        for name in ("CefInitialize", "CefShutdown", "CefDoMessageLoopWork", "CefRunMessageLoop", "CefQuitMessageLoop"):
            self.assertIn(name, scope_module.HANDWRITTEN_FUNCTIONS)


@unittest.skipUnless(HAS_REAL, "needs the CEF headers (CEF_ROOT)")
class WithHeaders(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.model, cls.scope, cls.files, cls.listings, cls.version = generate.build_all(REAL)

    def plan(self, cls, method, client=False, static=False):
        c = self.model.classes[cls]
        found = [m for m in c.get_virtual_funcs() + c.get_static_funcs() if m.get_name() == method][0]
        return plan_method(self.model, self.scope, cls, found, client_side=client, static=static)

    def test_scope_sorts_the_real_classes(self):
        self.assertTrue(self.scope.is_library("CefBrowser"))
        self.assertTrue(self.scope.is_library("CefFrame"))
        self.assertTrue(self.scope.is_client("CefResourceHandler"))
        self.assertTrue(self.scope.is_client("CefLoadHandler"))
        self.assertFalse(self.scope.is_library("CefV8Value") or self.scope.is_client("CefV8Value"))

    def test_every_rule_of_the_scope_names_a_real_method(self):
        for cls, method in scope_module.ASYNC_METHODS | set(scope_module.NATIVE_ONLY):
            c = self.model.classes[cls]
            self.assertIn(method, [m.get_name() for m in c.get_virtual_funcs()], (cls, method))
        for cls in scope_module.CUSTOM_PROXY:
            self.assertTrue(self.scope.is_client(cls), cls)
        for cls, method in scope_module.HANDWRITTEN_METHODS:
            self.assertIn(method, [m.get_name() for m in self.model.classes[cls].get_static_funcs()], (cls, method))
        for name in scope_module.HANDWRITTEN_FUNCTIONS:
            self.assertIn(name, self.model.functions)
        for cls, method in scope_module.OFF_UI_THREAD:
            if cls:
                self.assertIn(method, [m.get_name() for m in self.model.classes[cls].get_virtual_funcs()], (cls, method))
            else:
                self.assertIn(method, self.model.functions)

    def test_the_calls_that_cef_refuses_on_its_ui_thread_run_on_a_helper_thread(self):
        text = self.files["cef_functions.cc"]
        self.assertIn("cl::off_ui_thread([&]() { return CefDirectoryExists(", text)
        self.assertIn("cl::off_ui_thread([&]() { cl_self->Wait(); return true; });", self.files["lib_CefWaitableEvent.cc"])

    def test_primitive_string_and_void(self):
        self.assertIsInstance(self.plan("CefBrowser", "GetIdentifier").ret, Prim)
        load = self.plan("CefFrame", "LoadURL")
        self.assertIsInstance(load.ret, Void)
        self.assertIsInstance(load.params[0].kind, Str)

    def test_an_object_returned_by_a_library_method(self):
        self.assertIsInstance(self.plan("CefBrowserHost", "GetClient").ret, ClientRef)

    def test_a_map_goes_out_or_in(self):
        get = self.plan("CefRequest", "GetHeaderMap").params[0]
        put = self.plan("CefRequest", "SetHeaderMap").params[0]
        self.assertIsInstance(get.kind, StrMap)
        self.assertTrue(get.out)
        self.assertFalse(put.out)

    def test_a_handler_gets_library_objects_and_enums(self):
        console = self.plan("CefDisplayHandler", "OnConsoleMessage", client=True)
        kinds = {p.name: type(p.kind) for p in console.params}
        self.assertIs(kinds["browser"], LibRef)
        self.assertIs(kinds["level"], Enum)
        self.assertIs(kinds["message"], Str)

    def test_a_buffer_that_cef_may_leave_out_is_optional(self):
        flt = self.plan("CefResponseFilter", "Filter", client=True)
        data_in = flt.params[0]
        self.assertIsInstance(data_in.kind, Buffer)
        self.assertTrue(data_in.optional)
        self.assertFalse(flt.params[2].optional)       # the output buffer is always there

    def test_a_value_in_and_out_is_an_output_parameter(self):
        redirect = self.plan("CefResourceRequestHandler", "OnResourceRedirect", client=True)
        new_url = redirect.params[-1]
        self.assertTrue(new_url.out)
        self.assertIsInstance(new_url.kind, Str)

    def test_nothing_generated_is_unsupported_without_a_reason(self):
        for name, (side, listing) in self.listings.items():
            for plan, status in listing:
                if status.startswith("not generated"):
                    self.assertTrue(status[len("not generated: "):].strip(), (name, plan.cef_name))

    def test_most_of_the_api_is_generated(self):
        total = generated = 0
        for side, listing in self.listings.values():
            for plan, status in listing:
                total += 1
                generated += status == "generated"
        self.assertGreater(total, 1000)
        self.assertGreater(generated / total, 0.9)

    def test_every_generated_class_is_registered(self):
        register = self.files["register_all.cc"]
        for name in self.files:
            if name.startswith(("lib_", "client_")) and name.endswith(".cc"):
                cls = name.split("_", 1)[1][:-3]
                self.assertIn("cl_register_%s();" % cls, register)


if __name__ == "__main__":
    unittest.main()
