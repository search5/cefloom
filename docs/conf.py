"""Sphinx configuration of the cefloom documentation.

The guide pages are the Markdown files of this directory (read by MyST); the API reference is written from
``php/cefloom.stub.php`` by ``tools/docs/gen_api.py`` before each build. The language is selected with
``-D language=ko``; the catalogs live in ``locale/<language>/LC_MESSAGES`` (see the Makefile).
"""

import os
import subprocess
import sys

# The binding generator (tools/gen) is documented from its docstrings. Its modules import each other by bare name,
# so autodoc imports them the same way.
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "tools", "gen"))

project = "cefloom"
author = "cefloom contributors"
copyright = "cefloom contributors"
language = "en"

extensions = [
    "sphinx.ext.autodoc",
    "myst_parser",
    "sphinxcontrib.phpdomain",
]

source_suffix = {".rst": "restructuredtext", ".md": "markdown"}
master_doc = "index"
exclude_patterns = ["_build", "locale", "README.md", "requirements.txt", "recipes"]
pygments_style = "sphinx"

autodoc_member_order = "bysource"
autodoc_default_options = {"members": True, "undoc-members": False, "show-inheritance": True}

# Markdown: the pages link to each other as ``page.md#heading``.
myst_heading_anchors = 3
myst_enable_extensions = ["colon_fence"]

# Internationalization: ``make gettext`` writes the templates, ``sphinx-intl`` keeps the .po files.
locale_dirs = ["locale/"]
gettext_compact = False
gettext_uuid = False

html_theme = "sphinx_rtd_theme"
html_theme_options = {"collapse_navigation": False, "navigation_depth": 3, "titles_only": False}
html_title = "cefloom"
html_show_sphinx = False


def _generate_api(app):
    """Write ``api/gen-*.rst`` from the stub."""
    here = os.path.dirname(os.path.abspath(__file__))
    tool = os.path.join(os.path.dirname(here), "tools", "docs", "gen_api.py")
    subprocess.check_call([sys.executable, "-I", tool, os.path.join(os.path.dirname(here), "php", "cefloom.stub.php"),
                           os.path.join(here, "api")])


def setup(app):
    app.connect("builder-inited", _generate_api)
