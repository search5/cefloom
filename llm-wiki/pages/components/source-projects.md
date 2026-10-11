---
title: 입력 소스 프로젝트
type: component
sources:
  - cef_origin/CHROMIUM_BUILD_COMPATIBILITY.txt
  - cef_origin/tools/cef_parser.py
  - cef_origin/docs/llm-wiki/index.md
  - cefpython/docs/llm-wiki/index.md
  - java-cef/docs/llm-wiki/index.md
  - cefweaver_clone/cefweaver/CLAUDE.md
  - cefweaver_clone/cefweaver/build/native/cef/README.txt
updated: 2026-10-12
---

# 입력 소스 프로젝트

작업 영역(`/home/jiho/cef_framework`)에는 PHP 바인딩의 바탕이 되는 네 프로젝트가 있습니다. 모두 읽기 전용 자료입니다. 각 프로젝트에는 자체 llm-wiki(`docs/llm-wiki/`)가 있으며, `cefweaver`의 `CLAUDE.md`는 다른 프로젝트의 코드를 조사할 때 그 위키를 먼저 참조하라고 정합니다.

| 프로젝트 | 위치 | 역할 | cefloom에 주는 것 |
| --- | --- | --- | --- |
| CEF 원본 | `cef_origin/` | CEF 소스 저장소 | 헤더 파서, API 정의, 구현 확인용 소스 |
| cefpython | `cefpython/` | Cython 기반 Python 바인딩(`src/*.pyx` 33개) | 핸들러와 JS 바인딩의 Python식 구현 참고 |
| java-cef | `java-cef/` | JNI 기반 Java 바인딩(`native/*_N.cpp` 29개) | 네이티브 래퍼 구조, 메시지 라우터 참고 |
| cefweaver | `cefweaver_clone/cefweaver/` | 헤더에서 생성하는 Python 바인딩 | 생성기, C++ 래퍼, 서브프로세스, CEF 배포본 |

## CEF 원본 (`cef_origin`)

- `CHROMIUM_BUILD_COMPATIBILITY.txt`는 Chromium 체크아웃을 `refs/tags/154.0.8037.0`으로 지정합니다.
- `tools/cef_parser.py`는 CEF 헤더를 파싱하는 공식 도구이며 `cefweaver`가 수정 없이 복사해 씁니다.
- `libcef_dll/`에는 C API와 C++ API를 이어 주는 `cpptoc`, `ctocpp`가 있습니다.
- 소스 트리의 `include/capi/`에는 파일이 `cef_base_capi.h` 한 개뿐이었습니다(2026-10-12 확인). 나머지 C API 헤더(`cef_*_capi.h`)는 빌드 과정에서 생성되어 배포본에 들어갑니다(배포본의 `include/capi/`는 82개 파일). 따라서 **헤더 입력은 소스 트리가 아닌 배포본**을 써야 합니다.

## cefpython (`cefpython`)

Cython으로 쓴 오래된 Python 바인딩입니다. `src/`에 `.pyx` 33개(`browser.pyx`, `javascript_bindings.pyx`, `request.pyx` 등)와 `handlers/`, `client_handler/`가 있습니다. CEF 최신 버전용 생성기를 갖춘 것이 아니므로 구조 참고용입니다.

## java-cef (`java-cef`)

`native/`에 JNI 래퍼가 `*_N.cpp` 형식으로 29개 있습니다(`CefBrowser_N.cpp`, `CefMessageRouter_N.cpp` 등). 핸들러 수명 관리와 메시지 라우터의 JNI 연동을 확인할 참고 자료입니다.

## cefweaver (`cefweaver_clone/cefweaver`)

가장 직접적인 기반입니다.

- `tools/gen/`: 헤더에서 Python 바인딩을 생성하는 파이프라인([생성기 계획](../concepts/binding-generator-plan.md)).
- `native/cefwrapper/`: C++ 래퍼와 핸들러. `native/cefsubprocess/`: 서브프로세스 실행 파일.
- `cefweaver/pump.py`: 외부 메시지 펌프.
- `llm-wiki/`: 약 80개 페이지의 조사 기록(예: `verified-findings*.md`).
- `build/native/cef/`: 현재 쓰는 CEF 배포본. `README.txt` 기준 CEF 154.0.34+g14c5a08+chromium-154.0.8037.98(커밋 `14c5a089e8452874cb1a556dc3fde26ed2d87723`), 배포본 날짜 2026-10-05.
- 지원 플랫폼은 `CLAUDE.md` 기준 Linux x86_64, macOS arm64, Windows 11 x86_64입니다.
- **주의**: `build/`는 빌드 산출물입니다. 사전 검증이 이 안의 헤더와 런타임 디렉터리(`build/lib.linux-x86_64-cpython-313/cefweaver`)를 사용하므로, 다시 빌드하거나 정리하면 검증이 깨질 수 있습니다. 런타임의 `libcef.so`가 보고한 커밋 해시(`14c5a089...`)는 배포본 헤더의 것과 일치했습니다.

## 관련 페이지

- [목표 아키텍처](../concepts/target-architecture.md)
- [프로젝트 목표와 범위](../concepts/project-goals.md)
- [검증 하네스](ffi-precheck-harness.md)
