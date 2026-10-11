---
title: 목표 아키텍처
type: concept
sources:
  - cefweaver_clone/cefweaver/native/cefwrapper/library.cpp
  - cefweaver_clone/cefweaver/native/cefsubprocess/cefsubprocess.cc
  - cefweaver_clone/cefweaver/cefweaver/pump.py
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/process-model-and-threads.md
  - cefloom/experiments/ffi-precheck/verify.php
updated: 2026-10-12
---

# 목표 아키텍처

이 페이지의 구조는 **제안**입니다. 프로세스 모델과 메시지 펌프에 관한 부분만 사전 검증으로 확인했고([실험으로 확인한 사실](../reference/verified-findings.md)), 나머지는 구현 전입니다.

## 계층

```
PHP 스크립트 (CLI)
   |  Cefloom\* 클래스 (생성됨) + 손으로 쓴 핵심 클래스
PHP 확장 모듈 cefloom.so (C++, Zend API)
   |-- 생성된 바인딩: 헤더에서 만든 클래스, 메서드, 열거형, 값 타입
   |-- 손으로 쓰는 핵심: 초기화, 메시지 펌프, 스레드 간 큐, 참조 카운트, JS 브리지
   |-- CEF C++ API (libcef_dll_wrapper)
libcef.so (CEF 154)
   |  프로세스 간 통신
cefloomsubprocess (렌더러, GPU, 유틸리티 프로세스. C++만 실행, PHP 없음)
```

## 프로세스 모델

- **브라우저 프로세스는 PHP 프로세스 자신입니다.** `CefInitialize()`를 PHP가 호출하고 창과 브라우저를 만들며 UI 스레드 콜백을 PHP에서 처리합니다.
- **렌더러, GPU, 유틸리티 프로세스는 별도 실행 파일**이며 PHP를 로드하지 않습니다. `CefSettings.browser_subprocess_path`로 지정합니다. 사전 검증에서 `cefweaver`의 `cefsubprocess`를 그대로 지정해 동작을 확인했습니다([F6](../reference/verified-findings.md)).
- 이 구조는 `cefweaver`가 Python에서 이미 쓰는 구조와 같습니다. 서브프로세스가 Python 없이 C++만 실행하는 이유와 한계는 `cefweaver_clone/cefweaver/llm-wiki/pages/concepts/process-model-and-threads.md`에 있습니다.

## 재사용 후보

| 후보 | 위치 | 용도 | 검토 상태 |
| --- | --- | --- | --- |
| C++ 래퍼와 핸들러 | `cefweaver_clone/cefweaver/native/cefwrapper/` | CefApp, 브라우저 프로세스 핸들러, 렌더러 핸들러, JS 바인딩 메시지 | 읽기만 했고 결합도와 라이선스는 미검토 |
| 서브프로세스 | `cefweaver_clone/cefweaver/native/cefsubprocess/` | 렌더러 등 자식 프로세스 진입점 | 사전 검증에서 실행 확인 |
| 메시지 펌프 | `cefweaver_clone/cefweaver/cefweaver/pump.py` | 외부 펌프의 호출 시점 처리 | 구조만 확인 |
| 생성기 | `cefweaver_clone/cefweaver/tools/gen/` | 헤더 파싱, 타입 분류, 코드 생성 | [생성기 계획](binding-generator-plan.md) 참고 |

재사용 여부와 방식(복사, 공유 서브모듈, 참조만)은 아직 정하지 않았습니다. 정하면 [설계 결정 기록](../reference/design-decisions.md)에 남깁니다.

## C++ API와 C API 중 무엇에 붙는가

| 선택지 | 장점 | 단점 |
| --- | --- | --- |
| C++ API(`CefRefPtr`, `libcef_dll_wrapper` 정적 라이브러리) | 참조 카운트와 핸들러 구현이 클래스로 정리되어 있고, `cefweaver`와 `java-cef`가 이 방식입니다. | 래퍼 라이브러리를 함께 빌드해야 하고 ABI가 CEF 버전에 묶입니다. |
| C API(`cef_*_capi.h`의 구조체) | 래퍼 없이 `libcef.so`에 직접 붙습니다. 사전 검증이 이 방식이었습니다. | 모든 구조체의 참조 카운트와 콜백 표를 직접 구현해야 합니다. |

**제안**: 확장은 C++ API에 붙입니다. 이유는 구현할 핸들러 수가 많아 참조 카운트를 클래스로 한 번만 구현하는 편이 안전하고, 재사용 후보가 모두 이 방식이기 때문입니다. 사전 검증이 C API를 쓴 것은 FFI가 C 선언만 읽을 수 있어서이며, 설계 선택의 근거는 아닙니다.

## 관련 페이지

- [스레드와 메시지 루프](threading-and-message-loop.md)
- [프로세스 모델과 JS 브리지](process-model-and-js-bridge.md)
- [수명 관리](object-lifetime.md)
- [입력 소스 프로젝트](../components/source-projects.md)
