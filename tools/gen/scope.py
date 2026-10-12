"""What is generated for PHP.

Everything of the browser process API is generated: every class implemented by CEF (library
classes, wrapped as PHP objects), every class implemented by the application (client classes,
PHP classes the user extends, proxied to C++), the value structs, the enumerations and the global
functions. What is left out is left out for a reason, listed here:

 * the classes of the renderer process (V8, the DOM): PHP runs in the browser process only;
 * the classes CEF owns through a unique pointer (CefBaseScoped);
 * the test headers of CEF (include/test);
 * the functions that exist for tests, and the ones the runtime writes by hand (below).

Pipeline role: second stage. ``generate.py`` builds a :class:`Scope` from the model; ``typesys`` asks it which
classes are generated (``is_library``, ``is_client``), the emitters take the work lists (``library_classes``,
``client_classes``, ``functions``) from it, and ``emit_lib``, ``emit_client`` and ``emit_stub`` import the tables below.

Origin: adapted from tools/gen/scope.py of the cefweaver checkout (../cefweaver_clone/cefweaver).
"""

# Classes that only exist in the renderer process (V8 contexts and the DOM). PHP does not run there.
RENDERER_ONLY = {
    "CefV8Accessor", "CefV8ArrayBufferReleaseCallback", "CefV8BackingStore", "CefV8Context", "CefV8Exception",
    "CefV8Handler", "CefV8Interceptor", "CefV8StackFrame", "CefV8StackTrace", "CefV8Value",
    "CefDOMDocument", "CefDOMNode", "CefDOMVisitor", "CefRenderProcessHandler",
}

# Methods written by hand in ext/ (the generator still declares them, so the stub and the report list them):
# {(class, method): why}
HANDWRITTEN_METHODS = {
    ("CefBrowserHost", "CreateBrowser"): "the router of the JS bridge is attached to the client",
    ("CefBrowserHost", "CreateBrowserSync"): "the router of the JS bridge is attached to the client",
}

# Static factories that take a client for the browser they create: the client is wrapped as for CreateBrowser, so the
# JS bridge reaches the browser and Cef::shutdown() can close it (a Views window left open crashes CefShutdown).
WRAP_CLIENT = {("CefBrowserView", "CreateBrowserView")}

# Global functions written by hand: the PHP process owns CEF's lifetime and message loop.
HANDWRITTEN_FUNCTIONS = {
    "CefInitialize": "Cef::initialize",
    "CefShutdown": "Cef::shutdown",
    "CefDoMessageLoopWork": "Cef::doMessageLoopWork",
    "CefRunMessageLoop": "Cef::run",
    "CefQuitMessageLoop": "Cef::quit",
    "CefExecuteProcess": "Cef::executeProcess",
    "CefSetNestableTasksAllowed": "not meaningful with an external message pump",
}

# Methods of client classes that C++ implements itself and PHP never sees: {(class, method): C++ statement}.
# CEF calls OnScheduleMessagePumpWork on any thread; it must not run PHP (see cautions.md, rules 1 and 2).
NATIVE_ONLY = {
    ("CefBrowserProcessHandler", "OnScheduleMessagePumpWork"): "cl_core_schedule_pump_work(delay_ms);",
}

# Client classes whose C++ proxy is written by hand (ext/src/async.cc): the generator emits the PHP class only. The
# proxy must not make the thread that CEF calls it on wait for PHP: CEF's IO thread also carries the IPC of the
# browser process, and while it waits for the PHP thread, the PHP thread can wait for an IPC reply (llm-wiki, F14).
CUSTOM_PROXY = {"CefResourceHandler", "CefSchemeHandlerFactory"}

# Methods of the other client classes whose proxy hands the call to PHP without waiting for it (the function
# cl_async::<Class>_<Method> of ext/src/async.cc, declared in the generated file). The PHP method is unchanged.
ASYNC_METHODS = {
    ("CefResourceRequestHandler", "OnBeforeResourceLoad"),
    ("CefResourceRequestHandler", "OnResourceLoadComplete"),
    ("CefRequestHandler", "GetAuthCredentials"),
    # CEF calls these on the audio threads, which hold locks that its UI thread needs: waiting there for the PHP thread, which
    # is inside CefDoMessageLoopWork(), waits for ever (llm-wiki, K37).
    ("CefAudioHandler", "OnAudioStreamStarted"),
    ("CefAudioHandler", "OnAudioStreamPacket"),
    ("CefAudioHandler", "OnAudioStreamError"),
}

# Functions and methods that CEF refuses on its UI and IO threads (they return false: CefDirectoryExists says false for a
# directory that exists, WaitableEvent::TimedWait false for a signaled event), and PHP runs on the UI thread: the
# generated code runs the call on a helper thread of its own and waits for it (cl::off_ui_thread). They do not call PHP.
# {(class, method)}; "" is a global function.
OFF_UI_THREAD = {
    ("", "CefCreateDirectory"), ("", "CefGetTempDirectory"), ("", "CefCreateNewTempDirectory"),
    ("", "CefCreateTempDirectoryInDirectory"), ("", "CefDirectoryExists"), ("", "CefDeleteFile"), ("", "CefZipDirectory"),
    ("CefWaitableEvent", "Wait"), ("CefWaitableEvent", "TimedWait"),
}

# Statements run before a library method, after its arguments are converted: {(class, method): statement}. They refuse a call that
# CEF itself answers with a crash.
BEFORE_CALL = {
    ("CefPanel", "RemoveChildView"): "if (cl::refuse_live_browser_view(c_0)) { RETURN_THROWS(); }",
    ("CefPanel", "RemoveAllChildViews"): "if (cl::refuse_live_browser_views_of(cl_self)) { RETURN_THROWS(); }",
}

# Static functions (and global functions) that end the process when CEF is not running: the generated code throws an
# Error with the given message instead. {(class, method): message}
NEEDS_CEF_RUNNING = {
    ("CefImage", "CreateImage"): "an Image can only be made after Cef::initialize()",
    ("", "CefIsRTL"): "isRtl() can only be called after Cef::initialize()",
}


def _scoped(model, cls):
    """True for a class CEF owns through a unique pointer: CefBaseScoped is among its first 8 ancestors."""
    name = cls.get_parent_name()
    seen = 0
    while name and seen < 8:
        if name == "CefBaseScoped":
            return True
        parent = model.classes.get(name)
        name = parent.get_parent_name() if parent else None
        seen += 1
    return False


def _from_test_header(model, cls):
    """Return True if ``cls`` is declared in a CEF test header (``include/test``)."""
    return "/test/" in model.header_path(cls).replace("include/", "/", 1) or model.header_path(cls).startswith(
        "include/test/")


class Scope:
    """The set of classes and functions the generator binds, and the class hierarchy among them.

    A class is a *library* class (implemented by CEF, wrapped for PHP) or a *client* class (implemented by the
    application, proxied to C++). The ``*_classes`` properties and ``functions`` are the work list of the emitters.
    """
    def __init__(self, model):
        """Classify the classes of ``model`` (those that are not renderer-only, scoped or from a test header and are
        library side or client side) and select the global functions to bind (not ``*ForTests*``, not hand-written).

        :param model: the parsed headers, see :class:`model.Model`.
        """
        self.model = model
        self._library = set()
        self._client = set()
        for name, cls in model.classes.items():
            if name in RENDERER_ONLY or _scoped(model, cls) or _from_test_header(model, cls):
                continue
            if cls.is_library_side():
                self._library.add(name)
            elif cls.is_client_side():
                self._client.add(name)
        self.functions = sorted(
            n for n in model.functions
            if "ForTests" not in n and n not in HANDWRITTEN_FUNCTIONS)

    def python_parent(self, name):
        """The CEF parent of a library class that is generated too (CefPanel -> CefView), else None. Such a
        class is a PHP subclass of its parent and has only its own methods."""
        if name not in self._library:
            return None
        parent = self.model.classes[name].get_parent_name()
        return parent if parent in self._library else None

    def chain(self, name):
        """The classes a PHP class derives from, root first, the class itself last."""
        parent = self.python_parent(name)
        return (self.chain(parent) if parent else []) + [name]

    def depth(self, name):
        """Return the number of generated ancestors of ``name`` (0 for a root class)."""
        return len(self.chain(name)) - 1

    def python_children(self, name):
        """Return the generated subclasses of ``name``, sorted by name."""
        return [n for n in sorted(self._library) if self.python_parent(n) == name]

    def in_hierarchy(self, name):
        """Return True if ``name`` has a generated parent or generated subclasses."""
        return self.python_parent(name) is not None or bool(self.python_children(name))

    def is_library(self, name):
        """Return True if ``name`` is a library class in scope."""
        return name in self._library

    def is_client(self, name):
        """Return True if ``name`` is a client class in scope."""
        return name in self._client

    @property
    def library_classes(self):
        """Library classes, a parent before its subclasses (the parent is registered first)."""
        def depth(name):
            parent = self.python_parent(name)
            return 0 if parent is None else 1 + depth(parent)
        return [self.model.classes[n] for n in sorted(self._library, key=lambda n: (depth(n), n))]

    @property
    def client_classes(self):
        """Client classes in scope, sorted by name."""
        return [self.model.classes[n] for n in sorted(self._client)]
