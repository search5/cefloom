# Build the documentation. Needs `pip install -r requirements.txt`; the API pages are written from
# ../php/cefloom.stub.php by ../tools/docs/gen_api.py on every build.
SPHINXBUILD ?= sphinx-build
BUILD       ?= _build
LANGUAGES   ?= ko

.PHONY: html html-all site gettext update-po clean

html:
	$(SPHINXBUILD) -b html -W --keep-going . $(BUILD)/html/en

# one site per language: _build/html/en, _build/html/ko
html-all: html
	for lang in $(LANGUAGES); do $(SPHINXBUILD) -b html -D language=$$lang . $(BUILD)/html/$$lang; done

# the site as GitHub Pages serves it: both languages and a start page that links to them
site: html-all
	printf '%s\n' '<!doctype html><meta charset="utf-8"><title>cefloom</title><meta http-equiv="refresh" content="0; url=en/index.html"><p><a href="en/index.html">English</a> | <a href="ko/index.html">한국어</a></p>' > $(BUILD)/html/index.html
	touch $(BUILD)/html/.nojekyll

# message templates (.pot), then the catalogs of every language (.po)
gettext:
	$(SPHINXBUILD) -b gettext . $(BUILD)/gettext

update-po: gettext
	sphinx-intl update -p $(BUILD)/gettext $(addprefix -l ,$(LANGUAGES))
	# the generated API pages hold the text of the CEF headers; they are not translated
	for lang in $(LANGUAGES); do rm -f locale/$$lang/LC_MESSAGES/api/gen-*.po locale/$$lang/LC_MESSAGES/api/generator.po; done

clean:
	rm -rf $(BUILD)
