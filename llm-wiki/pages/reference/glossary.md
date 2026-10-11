---
title: 용어집
type: reference
sources:
  - cef_origin/include/capi/cef_base_capi.h
  - cefloom/experiments/ffi-precheck/verify.php
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/process-model-and-threads.md
updated: 2026-10-12
---

# 용어집

이 위키에서 쓰는 용어입니다. 원문 표기를 유지하는 용어는 그대로 적었습니다.

| 용어 | 설명 |
| --- | --- |
| CEF | Chromium Embedded Framework. Chromium을 응용에 내장하게 해 주는 프레임워크 |
| libcef | CEF의 공유 라이브러리(Linux에서 `libcef.so`) |
| 브라우저 프로세스 | 창과 브라우저를 만들고 UI 스레드 콜백을 실행하는 프로세스. cefloom에서는 PHP 프로세스 자신 |
| 렌더러 프로세스 | 페이지와 V8을 실행하는 프로세스. 서브프로세스 실행 파일이 맡음 |
| 서브프로세스 | `browser_subprocess_path`로 지정하는 별도 실행 파일. 렌더러, GPU, 유틸리티 프로세스를 실행함 |
| UI 스레드 | CEF의 핵심 스레드. 외부 메시지 펌프 방식에서는 `cef_initialize()`를 호출한 스레드 |
| 외부 메시지 펌프 | `external_message_pump=1`. 응용이 직접 `cef_do_message_loop_work()`를 호출해 CEF를 구동하는 방식 |
| `cef_do_message_loop_work` | CEF의 메시지 루프를 한 번 처리하는 함수 |
| `on_schedule_message_pump_work` | CEF가 "일정 시간 뒤에 펌프를 돌려 달라"고 요청하는 콜백. 임의 스레드에서 호출됨 |
| 참조 카운트 | `add_ref`, `release`로 객체의 수명을 정하는 CEF의 방식. `cef_base_ref_counted_t` |
| `CefRefPtr` | C++ API의 참조 카운트 스마트 포인터 |
| C API | `include/capi/cef_*_capi.h`의 구조체와 함수 포인터로 된 CEF 인터페이스 |
| C++ API | `CefRefPtr`와 클래스로 된 CEF 인터페이스. `libcef_dll_wrapper`가 C API에 연결함 |
| `cef_api_hash` | CEF API 버전을 설정하고 해시를 돌려주는 함수. 다른 API보다 먼저 호출해야 함 |
| OSR | Off-Screen Rendering. 창 없이 버퍼에 그리는 모드(`windowless_rendering_enabled`) |
| 프로세스 메시지 | 브라우저 프로세스와 렌더러 프로세스가 주고받는 메시지. JS 브리지의 전달 수단 |
| 메시지 라우터 | JS의 `cefQuery`와 브라우저 프로세스 핸들러를 잇는 CEF의 질의 응답 장치 |
| 핸들러 | 응용이 구현해서 CEF에 주는 콜백 객체(`LoadHandler`, `LifeSpanHandler` 등) |
| 트램펄린 | CEF의 콜백을 받아 PHP 호출로 바꾸는 네이티브 중개 함수. 예외 처리와 스레드 확인을 맡음(제안) |
| FFI | PHP의 Foreign Function Interface. C 라이브러리를 PHP에서 직접 호출함. 이 프로젝트에서는 사전 검증에만 씀 |
| cdef | `FFI::cdef`에 넘기는 C 선언 문자열 |
| shim | 이 프로젝트에서는 `refshim.c`. FFI가 못 하는 일(네이티브 콜백)을 대신하는 작은 C 라이브러리 |
| NTS, ZTS | PHP의 스레드 비안전 빌드와 스레드 안전 빌드 |
| SAPI | PHP가 실행되는 환경(CLI, FPM, Apache 모듈 등) |
| Zend API | PHP 확장이 쓰는 C API |
| Xvfb | 가상 X 서버. 실제 화면 없이 창을 띄워 시험할 때 씀 |
| ozone | Chromium의 윈도 시스템 추상층. `--ozone-platform=x11`로 X11을 고름 |
| 사전 검증 | 이 프로젝트에서 FFI로 C 확장의 전제를 확인한 작업. `experiments/ffi-precheck/` |
| cefweaver | 작업 영역의 Python 바인딩 프로젝트. 생성기와 래퍼의 재사용 후보 |

## 관련 페이지

- [목표 아키텍처](../concepts/target-architecture.md)
- [스레드와 메시지 루프](../concepts/threading-and-message-loop.md)
