---
title: 실험으로 확인한 사실
type: reference
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefloom/experiments/ffi-precheck/mkcdef.py
  - cefloom/experiments/ffi-precheck/run.sh
updated: 2026-10-12
---

# 실험으로 확인한 사실

직접 실행해서 확인한 사실입니다. 날짜는 모두 2026-10-12입니다. 환경은 Linux x86_64(6.17), PHP 8.5.4 CLI(NTS), gcc, 가상 X 서버(Xvfb)이고, `libcef.so`는 `cef_version_info`가 154.0.34.3633, 커밋 `14c5a089e8452874cb1a556dc3fde26ed2d87723`이라고 보고했습니다. 런타임은 `cefweaver_clone/cefweaver/build/lib.linux-x86_64-cpython-313/cefweaver`, 헤더는 `cefweaver_clone/cefweaver/build/native/cef`입니다. 재현은 [사전 검증 재현 절차](../procedures/run-ffi-precheck.md)를 따릅니다. 추정이 섞인 부분은 그렇게 밝힙니다.

실행 횟수: 창 모드 4회(최초 1회, 반복 2회, `cefloom`으로 옮긴 `run.sh` 재현 1회)와 오프스크린 모드 3회(최초 1회, 수정 후 1회, `run.sh osr` 재현 1회)를 실행했고, 최종 상태에서 모두 통과했습니다. 오프스크린 첫 실행의 실패는 F10에 적었습니다. 횟수 계측값(F4, F5)은 실행마다 조금씩 달라집니다.

## F1. C API 헤더를 기계적으로 FFI 선언으로 바꿀 수 있다

- **방법**: 최초 시험에서는 11개 C API 헤더(app, client, browser, life span, load, browser process, frame, task, process message, api hash, version info)를 `gcc -E`로 전처리했고(`run.sh`는 display, render, command line 헤더를 더하고 task, process message를 뺀 12개 구성), 줄 표지를 이용해 CEF 헤더에서 온 줄만 남긴 뒤(`mkcdef.py`) `typedef int wchar_t; typedef unsigned short char16_t; typedef int pid_t; typedef long time_t;`를 앞에 붙여 `FFI::cdef`에 넣었습니다.
- **결과**: 약 4,800줄이 파싱되었고 `sizeof(cef_settings_t)`는 448, `sizeof(cef_window_info_t)`는 88이었습니다. 처음에는 `__attribute__((visibility("default")))`를 지우는 정규식이 중첩 괄호를 잘못 처리해 파싱에 실패했고, 고정 문자열 치환을 먼저 하도록 고쳤습니다.
- **영향**: C API 구조체 정의는 기계가 읽을 수 있습니다([바인딩 생성기 계획](../concepts/binding-generator-plan.md)).

## F2. FFI에서 구조체의 함수 포인터 필드는 메서드 문법으로 부를 수 없다

- **방법**: `$cmd->append_switch_with_value($cmd, ...)`로 호출했습니다.
- **결과**: `Object of type FFI\CData does not support method calls`. `($cmd->append_switch_with_value)($cmd, ...)` 형태로 고치자 동작했습니다.
- **영향**: FFI 방식에만 해당하는 제약입니다. 확장 모듈에는 영향이 없습니다.

## F3. 참조 카운트 콜백을 PHP 클로저로 만들면 PHP가 종료된다

- **방법**: `add_ref`, `release`, `has_one_ref`, `has_at_least_one_ref`를 빈 PHP 클로저로 대입하고 `cef_initialize`를 호출했습니다.
- **결과**: `Maximum call stack size of 16728064 bytes (zend.max_allowed_stack_size - zend.reserved_stack_size) reached. Infinite recursion?`가 첫 `add_ref` 클로저에서 발생했고, 이어 `Throwing from FFI callbacks is not allowed` 치명 오류와 `Segmentation fault (core dumped)`로 끝났습니다.
- **해석(추정)**: 원인은 PHP 코드가 PHP 메인 스레드가 아닌 스레드에서 실행되면서 스택 한도 검사가 오작동한 것으로 봅니다. F4가 해당 함수들이 다른 스레드에서 호출됨을 보여 줍니다. 클로저 안에서 스레드 번호를 직접 기록해 확인하지는 못했습니다(진입 시점에 이미 오류가 나기 때문).
- **영향**: 참조 카운트는 네이티브로 구현해야 합니다([수명 관리](../concepts/object-lifetime.md)).

## F4. 참조 카운트 함수는 메인 스레드 밖에서도 호출된다

- **방법**: 네이티브 shim(`refshim.c`)이 호출마다 `gettid()`를 `shim_init()` 시점의 스레드 번호와 비교해 원자적으로 셉니다.
- **결과**: 창 모드 375회 중 16회, 오프스크린 모드 442회 중 16회(재현에서는 447회 중 16회)가 메인 스레드 밖이었습니다. 창 모드의 세 번의 실행과 `cefloom`에서의 재현 모두 같은 375회와 16회였습니다.
- **영향**: F3의 근거입니다.

## F5. `on_schedule_message_pump_work`는 대부분 메인 스레드 밖에서 호출된다

- **방법**: F4와 같은 방식으로 shim에 구현해 셉니다. PHP 클로저로 구현했을 때의 동작도 보았습니다.
- **결과**: 창 모드 실행 4회에서 44회 중 42회, 44회 중 42회, 54회 중 52회, 53회 중 51회가 메인 스레드 밖이었습니다. 오프스크린 모드는 15회 중 13회, `run.sh osr` 재현에서 11회 중 8회였습니다. 클로저로 구현했을 때는 메인 스레드가 `usleep()`으로 대기하는 중에 F3과 같은 스택 한도 오류가 발생했고 이어서 세그멘테이션 오류로 종료되었습니다(오류 추적에 `[internal function]`의 클로저가 `usleep()` 아래에 찍힘).
- **영향**: 이 콜백은 원자적 변수만 갱신하는 네이티브 함수여야 합니다([스레드와 메시지 루프](../concepts/threading-and-message-loop.md)).

## F6. PHP 프로세스에서 CEF 초기화와 외부 메시지 펌프가 동작한다

- **방법**: `cef_api_hash(999999, 2)`로 API 버전을 설정한 뒤 `cef_execute_process`, `cef_initialize`(`external_message_pump=1`, `multi_threaded_message_loop=0`, `no_sandbox=1`, `browser_subprocess_path`는 `cefweaver`의 `cefsubprocess`, `resources_dir_path`와 `locales_dir_path`는 런타임 디렉터리)를 호출하고, PHP 루프에서 `cef_do_message_loop_work()`를 2밀리초 `usleep` 간격으로 호출했습니다.
- **결과**: `cef_execute_process`는 -1(브라우저 프로세스), `cef_initialize`는 성공(0.19초에서 0.20초). 한 실행에서 메시지 루프를 14,323회 순환했습니다.
- **영향**: [목표 아키텍처](../concepts/target-architecture.md)의 프로세스 모델과 펌프 방식은 이 환경에서 성립합니다.

## F7. PHP에서 실행한 JS의 결과가 PHP 콜백으로 돌아온다

- **방법**: `on_load_end`에서 `frame->execute_java_script`로 `console.log('PHPOK ' + (1 + 2))`를 실행하고 `on_console_message`를 PHP 콜백으로 받습니다.
- **결과**: `[console] PHPOK 3`이 모든 실행에서 수신되었습니다.
- **영향**: PHP에서 JS로 가는 방향과 브라우저 프로세스 안의 콜백 수신은 확인되었습니다. 렌더러를 거치는 JS에서 PHP로의 호출은 확인하지 않았습니다([프로세스 모델과 JS 브리지](../concepts/process-model-and-js-bridge.md)).

## F8. 핸들러 콜백은 메시지 루프 호출 중 PHP 메인 스레드에서 실행된다

- **방법**: 콜백마다 `gettid()`를 기록하고 PHP 메인 스레드의 번호와 비교했습니다.
- **결과**: 창 모드에서 5종(`on_console_message`, `on_load_end`, `on_after_created`, `on_before_close`, `on_context_initialized`), 오프스크린 모드에서 7종(앞의 5종에 `get_view_rect`, `on_paint`)이 모두 메인 스레드에서 실행되었습니다.
- **한계**: 기록한 콜백에 한정한 결과입니다. UI 스레드가 아닌 스레드에서 불리는 핸들러(리소스 등)는 검증하지 않았습니다.

## F9. 종료 순서와 종료 코드

- **방법**: JS 결과를 받으면 `close_browser(force=1)`, `on_before_close` 수신까지 루프, 50회 추가 순환, `cef_shutdown()`.
- **결과**: 모든 실행에서 종료 코드가 0이었습니다.
- **한계**: shim이 객체를 해제하지 않으므로 해제를 동반한 종료는 검증하지 못했습니다.

## F10. 오프스크린 모드의 `on_paint`

- **방법**: 오프스크린 모드에서 `get_view_rect`와 `on_paint`를 구현하고 첫 페인트를 세었습니다.
- **결과**: 첫 실행에서는 `on_paint`가 0회로 실패 표시가 났습니다. JS 결과를 받자마자 브라우저를 닫는 검증 스크립트의 순서 때문이었습니다. 첫 페인트를 기다리도록 고친 뒤 `on_paint` 1회를 수신하고 통과했습니다.
- **한계**: 픽셀 내용은 확인하지 않았습니다. 수신 횟수만 셌습니다.

## F11. 런타임 `libcef.so`와 헤더 배포본의 버전이 일치한다

- **방법**: `cef_version_info(0..3)`과 `cef_api_hash(..., 2)`(커밋 해시)를 배포본의 `README.txt`와 비교했습니다.
- **결과**: 런타임은 154.0.34.3633, 커밋 `14c5a089e8452874cb1a556dc3fde26ed2d87723`. 배포본 `README.txt`의 CEF 커밋 `14c5a089e8452874cb1a556dc3fde26ed2d87723`과 같습니다. 구조체 크기가 맞아 초기화가 성공한 것과 일치합니다.

## F12. 콜백 안의 PHP 예외는 프로세스를 종료시킨다

- **방법**: 검증 스크립트를 고치는 동안 콜백 안에서 의도하지 않은 예외가 네 번 났습니다(F2의 메서드 호출 문법 오류, `FFI::cast`의 타입 이름 오류, `pack('U')`의 지원하지 않는 형식, 그리고 F3의 스택 한도 오류).
- **결과**: 모두 `PHP Fatal error: Throwing from FFI callbacks is not allowed`로 끝났습니다. 이 가운데 `pack('U')`와 F3의 경우에는 `Segmentation fault (core dumped)`가 함께 출력되었습니다. 나머지는 치명 오류 메시지 뒤에 세그멘테이션 오류가 출력되지 않았습니다. 차이가 생기는 이유는 확인하지 않았습니다.
- **영향**: 확장의 콜백 진입점은 예외를 잡아야 합니다([규칙 4](../concepts/threading-and-message-loop.md)). 이 동작은 FFI 콜백에서 관찰한 것이며 확장 모듈에서 어떨지는 구현 후에 확인해야 합니다.

## 관련 페이지

- [알려진 제약과 미검증 항목](known-constraints.md)
- [주의 사항](cautions.md)
- [검증 하네스](../components/ffi-precheck-harness.md)
