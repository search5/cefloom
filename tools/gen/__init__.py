"""Binding generator of cefloom: reads the CEF headers and writes the PHP extension sources and stub.

Run it as a script (``python3 tools/gen/generate.py``); the modules import each other by bare name (``generate.py``
puts its own directory on ``sys.path`` first).
"""
