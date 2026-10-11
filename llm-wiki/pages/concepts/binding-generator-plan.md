---
title: 바인딩 생성기 계획
type: concept
sources:
  - cef_origin/tools/cef_parser.py
  - cefweaver_clone/cefweaver/tools/gen/generate.py
  - cefweaver_clone/cefweaver/tools/gen/typesys.py
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/binding-generator.md
  - cefloom/experiments/ffi-precheck/mkcdef.py
  - cefloom/experiments/ffi-precheck/run.sh
updated: 2026-10-12
---

# 바인딩 생성기 계획

CEF API는 클래스가 185개, 가상 메서드가 약 1,440개에 이릅니다(`cefweaver`의 위키가 154 배포본의 헤더에서 읽은 수치: `cefweaver_clone/cefweaver/llm-wiki/pages/concepts/binding-generator.md`). 손으로 중계하면 CEF 버전이 올라갈 때마다 뒤따르기 어려우므로, 헤더에서 PHP 확장의 연결 코드를 생성하는 것이 목표 G1입니다. 이 페이지는 **계획**이며 생성기 코드는 아직 없습니다.

## 재사용할 수 있는 것

`cefweaver`는 이미 같은 문제를 Python 쪽으로 풀었습니다.

| 단계 | cefweaver의 구현 | cefloom에서의 쓰임 (제안) |
| --- | --- | --- |
| 헤더 파싱 | CEF 공식 `tools/cef_parser.py`를 `tools/gen/vendor/`에 수정 없이 복사 | 같은 파서를 입력으로 씁니다. 원본은 `cef_origin/tools/cef_parser.py`입니다. |
| 모델 | 클래스, 메서드, 인자, 열거형, 값 타입 구조체 | 그대로 재사용 후보 |
| 타입 분류 | `typesys.py`의 `classify()`가 타입을 `Prim`, `Str`, `Enum`, `LibRef`, `ClientRef`, `Struct`, `Vector`, `StrMap`, `Time`, `Bytes`, `Buffer` 등으로 분류하고 지원하지 못하는 타입은 이유와 함께 기록 | 분류 체계를 재사용하고 PHP 쪽 변환 규칙을 새로 만듭니다. |
| 출력 | C++ 프록시, Cython 선언과 래퍼, `.pyi` 스텁, 커버리지 보고서 | Zend 클래스 등록 코드, 인자 정보, PHP 스텁(IDE용), 커버리지 보고서 |

입력은 **사용 중인 CEF 배포본의 헤더**로 하는 방침이 `cefweaver`의 경험상 안전합니다. 생성 결과가 항상 링크하는 `libcef`와 같은 버전이 됩니다.

## 타입 변환 제안

PHP로 옮기는 규칙은 구현 전이며 제안입니다.

| 종류 | PHP 표현 (제안) |
| --- | --- |
| `Prim` | `bool`, `int`, `float` |
| `Str` | `string`(UTF-8) |
| `Enum` | PHP 8.1 이상의 `enum`(정수 기반). 플래그 열거형은 정수 상수와 비트 연산 |
| `LibRef` | Zend 객체(CEF 객체를 감싼 래퍼). 널이면 `null` |
| `ClientRef`(핸들러) | 사용자가 구현하는 인터페이스 또는 기본 구현을 가진 추상 클래스 |
| `Struct`(`CefRect` 등) | 읽기 전용 값 객체 |
| `Vector`, `StrMap` | `array` |
| `Time` | `DateTimeImmutable` |
| `Bytes`, `Buffer` | `string`(바이너리). 쓰기 가능한 버퍼는 별도 설계 필요 |

PHP 확장에서 인자 정보(arginfo)와 클래스 등록 코드를 만드는 표준 방법이 있는지는 아직 조사하지 않았습니다. PHP 소스의 스텁 생성 도구가 쓸 만한지 확인이 필요합니다([알려진 제약](../reference/known-constraints.md)).

## C API 헤더를 기계적으로 다룰 수 있다는 증거

사전 검증에서 C API 헤더를 `gcc -E`로 전처리하고 CEF 헤더에서 온 줄만 남기는 필터(`mkcdef.py`)를 만들었습니다. 결과 4,805줄이 PHP FFI의 선언 파서에서 그대로 파싱되었고, `cef_settings_t`는 448바이트, `cef_window_info_t`는 88바이트로 계산되었습니다([F1](../reference/verified-findings.md)). 이 결과는 C API 구조체 정의를 기계가 읽을 수 있다는 점을 보여 줄 뿐이며, 확장 생성기의 입력으로 C API를 쓸지 C++ API를 쓸지는 별개의 결정입니다([목표 아키텍처](target-architecture.md)).

## 생성기가 다룰 어려운 부분 (예상)

- 값을 돌려주는 출력 인자(`CefString&`, `CefRect&`)와 버퍼 인자
- 임의 스레드에서 호출되는 핸들러 콜백 표시(규칙 3의 분류 근거, [스레드와 메시지 루프](threading-and-message-loop.md)). 헤더 주석에 스레드 정보가 있으나 기계가 읽을 형식이 아니라서, 수작업 표가 필요할 가능성이 큽니다.
- 플랫폼별 구조체(`cef_window_info_t` 등)
- CEF API 버전(`CEF_API_VERSION_EXPERIMENTAL`)과 `cef_api_hash` 처리. [주의 사항](../reference/cautions.md)을 보십시오.

## 관련 페이지

- [목표 아키텍처](target-architecture.md)
- [검증 하네스](../components/ffi-precheck-harness.md)
- [입력 소스 프로젝트](../components/source-projects.md)
- [로드맵](../reference/roadmap.md)
