---
title: 코드 주석 작성 규칙과 점검 절차
type: procedure
sources:
  - cefloom/tools/check-comments.py
  - cefloom/tools/gen/emit_stub.py
  - cefloom/ext/runtime/cl_runtime.h
updated: 2026-10-12
---

# 코드 주석 작성 규칙과 점검 절차

코드 주석은 나중에 Sphinx로 API 문서를 만들 수 있는 형식으로 영어로 씁니다. 언어별로 Sphinx가 읽는 형식이 다르므로 아래 표의 형식을 따릅니다. 누락은 `tools/check-comments.py`가 기계적으로 찾고, 위키는 이 페이지와 아래 대장으로 어느 파일이 끝났는지 추적합니다.

## 형식

| 대상 | 주석 형식 | Sphinx 연결 |
| --- | --- | --- |
| C++ (`ext/src`, `ext/runtime`, `subprocess`) | Doxygen 블록 `/** ... */` | Doxygen XML을 Breathe가 읽습니다 |
| Python (`tools/gen`) | reST 필드 목록을 쓰는 docstring | `sphinx.ext.autodoc` |
| PHP (`php/cefloom.stub.php`, `tests/support.php`, `bin/cefloom-runtime`) | phpDocumentor 형식 `/** ... */` | phpDocumentor 또는 `sphinxcontrib-phpdomain` |
| 생성 코드 (`ext/generated`) | 직접 고치지 않습니다. `tools/gen`의 생성기가 CEF 헤더의 설명을 옮겨 씁니다 | 위와 같음 |

## 문체

- 첫 문장은 요약이며 명령형이나 3인칭 동사구로 끝을 맺습니다(`Return the...`, `Run one iteration of...`). 대상을 되풀이하지 않습니다.
- 설명조(`This function is used to...`, `Note that...`)를 쓰지 않습니다. 구현을 한 줄씩 풀어 쓰지 않고, 호출하는 쪽이 알아야 하는 계약만 적습니다: 값의 범위, 소유권, 호출할 수 있는 스레드, 던지는 예외, 실패 때의 반환값.
- 태그는 필요한 것만 씁니다. C++은 `@param`, `@return`, `@throws`, `@note`, `@see`, Python은 `:param name:`, `:returns:`, `:raises X:`, PHP는 `@param`, `@return`, `@throws`를 씁니다. 이름만 되풀이하는 태그는 달지 않습니다.
- 스레드 규칙을 지키는 함수에는 `@thread PHP`, `@thread any`처럼 한 줄로 밝힙니다(Doxygen에서는 `@par Thread` 대신 `@note Thread: ...`).
- 파일 첫머리에는 `@file`과 한 줄 요약을 둡니다. 이미 있는 설명 주석을 버리지 말고, 계약에 해당하는 내용은 Doxygen 블록으로 옮기고, 구현 방식을 설명하는 내용은 함수 내부의 일반 주석(`//`)으로 남깁니다.
- 한국어 위키 문서와 달리 코드 안의 문장은 모두 영어입니다. 규칙 번호(F14, K43 등)는 그대로 인용해도 됩니다.

## 사실 확인

주석에는 코드와 원본 자료에서 확인한 사실만 씁니다. 코드를 읽고 짐작한 동작, 스레드, 실패 때의 반환값은 쓰지 않습니다.

- 스레드는 CEF 헤더의 `Called on the ... thread` 문장으로 확인합니다(`cef_resource_handler.h`, `cef_request_handler.h`, `cef_audio_handler.h` 등).
- 반환값과 예외는 함수 본문을 읽고, 가능하면 직접 호출해서 확인합니다.
- 헤더에 근거가 없는 항목은 문서에 쓰지 않거나, `CefBrowserHost::CreateBrowser()`가 돌려주는 값처럼 사실 그대로 적습니다.
- 위키의 해석(예: F14의 GPU 응답 경로)은 해석이라고 밝힙니다.
- 날짜나 저작권처럼 확인할 수 없는 문장은 쓰지 않습니다.

2026-10-12의 첫 작성본을 이 기준으로 다시 확인했습니다. `ext/src`, `ext/runtime`, `subprocess`에서 12곳, `tools/gen`, `tests/support.php`, `bin/cefloom-runtime`에서 약 190개 주장 중 약 75개를 고쳤습니다. 고친 예는 오디오 스트림 콜백의 스레드(캡처 스레드, 스트림 스레드, UI 또는 스트림 스레드로 서로 다름), 외부 펌프에서 입력이 오지 않는다는 낡은 설명(GLib 수정 이후 사실이 아님), `Cef::wait()`가 폴링 모드에서 5ms로 제한되는 점, 생성기 타입 설명에 남아 있던 Python, Cython 용어입니다.

## 점검

```
python3 tools/check-comments.py        # 누락 개수만 출력합니다
python3 tools/check-comments.py -v     # 위치와 선언을 함께 출력합니다
python3 tools/check-comments.py <경로>  # 일부만 점검합니다
```

종료 코드가 0이어야 합니다. 점검 대상은 함수, `ZEND_METHOD`, 클래스, 구조체, 열거형, `using`, 매크로, 네임스페이스 변수, 최상위 클래스의 멤버(C++), 모듈, 클래스, 함수(Python), 함수, 클래스, 상수(PHP)입니다. `ext/generated`와 `tools/gen/vendor`는 제외합니다. 생성 코드의 주석은 생성기가 만들고, `vendor`는 외부 코드입니다.

새 코드를 쓸 때는 선언과 주석을 함께 씁니다. 점검 도구는 시험 모음의 일부로 도는 것이 아니므로, 커밋 전에 직접 돌립니다.

## 범위 밖

- `ext/generated`의 C++은 점검하지 않고 Doxygen 입력에서도 뺍니다. 사용자에게 보이는 API는 `php/cefloom.stub.php`가 담고, 그 phpdoc은 생성기(`tools/gen/emit_stub.py`)가 CEF 헤더 주석에서 옮겨 씁니다. 헤더에 설명이 없는 항목은 `@see`로 C++ 선언 이름만 답니다.
- `tools/gen/vendor`는 CEF가 배포한 외부 코드입니다.
- 템플릿 함수의 주석은 `template <...>` 줄 위에 둡니다(Doxygen이 붙이는 위치). 인클루드 가드의 `#define`은 점검에서 제외합니다.
- Sphinx 설정(`conf.py`, Doxyfile)은 아직 만들지 않았습니다. 주석 형식만 준비한 상태입니다.

## 진행 대장

이 대장은 작업이 끝날 때마다 갱신합니다.

| 묶음 | 파일 | 상태 |
| --- | --- | --- |
| 런타임 | `ext/runtime/cl_runtime.h`, `cl_runtime.cc` | 완료 (점검 0건) |
| 확장 본체 | `ext/src/*` | 완료 (점검 0건) |
| 하위 프로세스 | `subprocess/cefloomsubprocess.cc` | 완료 (점검 0건) |
| 생성기 | `tools/gen/*.py` | 완료 (점검 0건) |
| 생성 결과물 | `php/cefloom.stub.php` (생성기가 씁니다) | 완료 (점검 0건) |
| PHP 보조 | `tests/support.php`, `bin/cefloom-runtime` | 완료 (점검 0건) |

## 관련 페이지

- [개발 문서 규칙: 생성기](../concepts/binding-generator-plan.md)
- [시험 계획](../reference/test-plan.md)
- [패키징과 릴리스 절차](package-and-release.md)
