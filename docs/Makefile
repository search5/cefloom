# Build the documentation. Needs `pip install -r requirements.txt`; the API pages are written from
# ../php/cefloom.stub.php by ../tools/docs/gen_api.py on every build.
SPHINXBUILD ?= sphinx-build
BUILD       ?= _build
LANGUAGES   ?= ko

.PHONY: html html-all gettext update-po clean

html:
	$(SPHINXBUILD) -b html -W --keep-going . $(BUILD)/html/en

# one site per language: _build/html/en, _build/html/ko
html-all: html
	for lang in $(LANGUAGES); do $(SPHINXBUILD) -b html -D language=$$lang . $(BUILD)/html/$$lang; done

# message templates (.pot), then the catalogs of every language (.po)
gettext:
	$(SPHINXBUILD) -b gettext . $(BUILD)/gettext

update-po: gettext
	sphinx-intl update -p $(BUILD)/gettext $(addprefix -l ,$(LANGUAGES))
	# the generated API pages hold the text of the CEF headers; they are not translated
	for lang in $(LANGUAGES); do rm -f locale/$$lang/LC_MESSAGES/api/gen-*.po locale/$$lang/LC_MESSAGES/api/generator.po; done

clean:
	rm -rf $(BUILD)
