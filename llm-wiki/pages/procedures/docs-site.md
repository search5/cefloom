---
title: 문서 사이트(Sphinx)
type: procedure
sources:
  - cefloom/docs/conf.py
  - cefloom/tools/docs/gen_api.py
  - cefloom/docs/recipes/cookbook.php
  - cefweaver_clone/cefweaver/docs/conf.py
updated: 2026-10-13
---

# 문서 사이트(Sphinx)

`docs/`는 cefweaver의 `docs/`와 같은 도구로 사이트를 만듭니다: Sphinx와 `sphinx_rtd_theme`(`html_theme_options`도 같은 값), `-W --keep-going`으로 경고를 오류로 다루는 Makefile, gettext와 `sphinx-intl`을 쓰는 번역 구조입니다. 가이드, 쿡북, API 명세가 한 사이트에 들어갑니다.

## cefweaver와 다른 점

| 항목 | cefweaver | cefloom | 이유 |
| --- | --- | --- | --- |
| 가이드 원문 | reST | Markdown(MyST) | `t_docs`와 `tools/check-docs.py`가 `docs/*.md`를 읽으므로 형식을 바꾸지 않았습니다. |
| API 명세 | autodoc이 docstring을 읽음 | `tools/docs/gen_api.py`가 `php/cefloom.stub.php`를 reST로 바꿈(`sphinxcontrib-phpdomain`) | 공개 API는 PHP이며 그 phpdoc은 스텁에 있습니다. |
| 번역 | 한국어 카탈로그 있음 | 있음(`docs/locale/ko`: 가이드 12쪽, 쿡북, 목차 3개) | API 설명은 CEF 헤더의 영어 문장이라 번역하지 않았고, `update-po`가 그 카탈로그를 지웁니다. |
| Python 문서 | autodoc | autodoc(`docs/api/generator.rst`, `tools/gen`의 독스트링) | 같은 방식입니다. 모듈이 서로 맨 이름으로 import하므로 `conf.py`가 `tools/gen`을 `sys.path`에 넣습니다. |

## 구성

- `docs/index.rst`: 목차(가이드, 참조). 가이드 페이지는 기존 `docs/*.md`입니다.
- `docs/cookbook.md`와 `docs/recipes/cookbook.php`: 조리법과 그 원본 프로그램입니다. 프로그램은 `tests/t_docs.php`가 실행해 출력을 비교합니다.
- `docs/api/index.rst`: 참조 목차. `docs/api/gen-*.rst`는 빌드 때마다 `builder-inited`에서 생성하며 git에 넣지 않습니다.
- `docs/requirements.txt`: sphinx, sphinx-rtd-theme, sphinx-intl, sphinxcontrib-phpdomain, myst-parser.

## 확인한 사실

- `sphinxcontrib-phpdomain` 0.15.2의 시그니처 정규식은 `.. php:const:: NAME = 값`처럼 값을 시그니처에 넣으면 끝없이 되돌아가(백트래킹) 빌드가 멈춥니다(밑줄이 많은 이름에서 재현). 생성기는 값을 본문에 씁니다.
- 번역문에서 ``리터럴`` 바로 뒤에 조사를 붙이면 reST가 리터럴을 닫지 못합니다(`\\ `로 띄워 씁니다).
- 번역은 서브에이전트 4개가 나눠 작성했고, 저는 빈 항목 0, 한국어 `-W` 빌드 통과까지 확인했습니다. 용어 일관성은 일부만 맞췄습니다(예: Configuration은 "구성").
- 본문의 `|이름|`은 reST 치환 참조로 읽히므로 생성기가 리터럴로 바꾸고, 남은 `|`는 이스케이프합니다.
- 클래스를 페이지에 나누는 기준(핸들러, 뷰, 값, 구조체, 상수, 그 밖)은 이름에 대한 규칙이며 CEF 헤더의 분류가 아닙니다.
- 빌드: `make -C docs html`과 `-D language=ko`가 `-W`로 성공합니다(Sphinx 9.1.0, sphinx-rtd-theme 3.1.0, myst-parser 5.1.0).

## cefweaver와 맞춘 주제

2026-10-13에 두 프로젝트 가이드가 같은 주제를 다루도록 맞췄습니다(사용자 요청). 주제 대응은 아래와 같습니다.

| 주제 | cefloom | cefweaver |
| --- | --- | --- |
| 설치 | `installation.md` | `guide/installation.rst` |
| 시작하기 | `getting-started.md` | `guide/quickstart.rst` |
| 쿡북 | `cookbook.md` | `guide/cookbook.rst` |
| 개념 | `concepts.md` | `guide/concepts.rst`(새로 씀) |
| 메시지 루프 | `message-loop.md` | `guide/message-loop.rst`(새로 씀) |
| 핸들러와 이벤트 | `handlers.md` | `guide/events.rst` |
| JavaScript 브리지 | `javascript-bridge.md` | `guide/javascript-bridge.rst`(새로 씀) |
| Views | `views.md` | `guide/views.rst` |
| 컨텍스트 메뉴 | `context-menu.md`(새로 씀) | `guide/context-menu.rst` |
| 소리, 마이크, 카메라 | `media.md`(새로 씀) | `guide/media.rst` |
| 인쇄 | `printing.md` | `guide/printing.rst`(새로 씀) |
| Cast | `cast.md` | `guide/cast.rst` |
| 구성 | `configuration.md` | `guide/configuration.rst`(새로 씀) |
| Wayland와 GPU | `wayland-gpu.md`(새로 씀) | `guide/wayland-gpu.rst` |
| 문제 해결 | `troubleshooting.md` | `guide/troubleshooting.rst`(새로 씀) |
| 한계 | `limitations.md`(새로 씀) | `guide/limitations.rst` |
| 개발 | `development.md` | `guide/development.rst` |

대응이 없는 것은 cefweaver의 `embedding.rst`와 `toolkits.rst`입니다. GUI 툴킷에 브라우저를 넣는 내용이며 PHP에는 툴킷 어댑터가 없습니다.

새 쪽의 문장은 서브에이전트가 소스와 직접 실행으로 확인해 썼고, 확인하지 못한 것은 쪽에 "not tried"로 적혀 있습니다. 새 쪽의 예제는 `docs/recipes/`(`context-menu.php`, `audio.php`, `media.php`, `gpu-probe.php`)에 있으며 각각 같은 출력을 3회 냈습니다. `t_docs`는 `cookbook.php`만 실행합니다.

## GitHub Pages 배포

`.github/workflows/docs.yml`이 `main`에 푸시될 때(`docs/`, 스텁, `tools/docs`, `tools/gen`, 워크플로 자신이 바뀐 경우)와 수동 실행으로 `make -C docs site`를 돌려 `docs/_build/html`을 Pages에 올립니다. `site` 대상은 영어와 한국어를 빌드하고 시작 페이지(`index.html`, `en/`으로 이동)를 씁니다. 사이트에 CEF 런타임이 필요하지 않아서(API 쪽은 스텁에서 생성) 일반 러너에서 빌드됩니다.

- 확인한 것: `make -C docs site`가 이 컴퓨터에서 성공하고 워크플로의 YAML이 구문상 맞습니다.
- 첫 실행(2026-10-13): 빌드 58초, 배포 10초로 성공했습니다. https://search5.github.io/cefloom/ 의 주요 주소(`en/`, `ko/`, `en/limitations.html`, `ko/wayland-gpu.html`, `en/api/gen-cef.html`)가 200으로 응답합니다.
- 설정: 저장소 Pages의 Source를 `gh api`로 GitHub Actions(`build_type=workflow`)로 켰습니다.
- 두 사이트가 같은 방식입니다. cefweaver는 wheel을 먼저 빌드해야 해서 약 7분이 걸립니다(`cefweaver/.github/workflows/docs.yml`).

## 아직 하지 않은 것

- C++(Doxygen) 주석을 사이트에 넣는 일: 이 컴퓨터에 `doxygen`이 없어서(`which doxygen`이 비어 있음) Breathe를 설정하지 않았습니다.
- API 설명과 독스트링의 한국어 번역.
- 사이트의 외부 공개(Read the Docs 등).

## 관련 페이지

- [코드 주석 작성 규칙과 점검 절차](code-comments.md)
- [패키징과 릴리스 절차](package-and-release.md)
