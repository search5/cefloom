---
title: 스레드와 메시지 루프
type: concept
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefweaver_clone/cefweaver/cefweaver/pump.py
  - cefweaver_clone/cefweaver/native/cefwrapper/library.cpp
  - cefweaver_clone/cefweaver/build/native/cef/include/capi/cef_browser_process_handler_capi.h
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/process-model-and-threads.md
updated: 2026-10-12
---

# 스레드와 메시지 루프

이 페이지는 cefloom 설계에서 가장 중요한 제약인 **PHP가 실행될 수 있는 스레드**를 다룹니다. 사전 검증이 확인한 사실과 그로부터 나온 설계 제안을 구분해서 적습니다.

## 확인된 사실

[실험으로 확인한 사실](../reference/verified-findings.md)의 F3부터 F8까지가 근거입니다.

- `multi_threaded_message_loop`를 끄고(사전 검증은 `external_message_pump`도 켰습니다. 확장은 이후 이를 기본으로 켜지 않습니다, [D14](../reference/design-decisions.md)), PHP 메인 스레드에서 `cef_do_message_loop_work()`를 반복 호출하면 CEF가 동작합니다. 이때 UI 스레드는 `cef_initialize()`를 호출한 PHP 메인 스레드입니다.
- 이 루프 안에서 호출된 핸들러 콜백(`on_context_initialized`, `on_after_created`, `on_before_close`, `on_load_end`, `on_console_message`, 오프스크린 모드의 `get_view_rect`와 `on_paint`)은 모두 PHP 메인 스레드와 같은 스레드 번호에서 실행되었습니다.
- **참조 카운트 함수(`add_ref`, `release` 등)는 메인 스레드 밖에서도 호출됩니다.** 한 번의 실행에서 375회 중 16회였습니다.
- **`on_schedule_message_pump_work`도 대부분 메인 스레드 밖에서 호출됩니다.** 실행마다 44회 중 42회, 15회 중 13회, 53회 중 51회였습니다. CEF 헤더가 이 콜백이 어느 스레드에서든 호출될 수 있다고 명시하는 것과 일치합니다.
- 위 두 종류의 콜백을 PHP 클로저로 구현하면 PHP가 "Maximum call stack size ... reached" 오류를 내고 이어서 세그멘테이션 오류로 종료됩니다. 네이티브 C 함수로 바꾸면 정상 동작합니다.

## 설계 제안

### 규칙 1. PHP 코드는 메인 스레드에서만 실행한다

CEF가 어느 스레드에서 호출하든 PHP의 Zend 엔진을 건드리지 않습니다. PHP는 이 환경에서 NTS(스레드 비안전) 빌드이므로 다른 스레드에서의 실행은 정의되지 않은 동작입니다.

### 규칙 2. 임의 스레드에서 호출되는 콜백은 네이티브로 완결한다

| 콜백 | 처리 |
| --- | --- |
| `add_ref`, `release`, `has_one_ref`, `has_at_least_one_ref` | 원자적 카운터. PHP를 건드리지 않습니다([수명 관리](object-lifetime.md)). |
| `on_schedule_message_pump_work(delay_ms)` | 원자적 변수에 "다음 펌프 시점"만 기록합니다. 필요하면 `eventfd`나 파이프로 PHP 루프를 깨웁니다. 사전 검증의 `shim_schedule`이 이 방식의 단순형입니다. |
| UI 스레드가 아닌 스레드에서 불리는 핸들러(리소스, 스킴, 네트워크 관련) | 아래 규칙 3을 따릅니다. |

### 규칙 3. UI 스레드가 아닌 핸들러는 메인 스레드로 넘긴다 (미검증)

`cefweaver`의 위키는 리소스 핸들러의 `create`, `open`, `get_response_headers`, `read`가 UI 스레드가 아닌 스레드에서 실행됨을 시험으로 확인했습니다(`cefweaver_clone/cefweaver/llm-wiki/pages/concepts/process-model-and-threads.md`). PHP에서도 같은 일이 생깁니다. 제안하는 방식은 다음과 같습니다.

1. 호출된 스레드에서 요청을 큐에 넣고 `cef_post_task(TID_UI, ...)`로 UI 스레드에 작업을 예약합니다.
2. UI 스레드는 PHP 루프가 `cef_do_message_loop_work()`를 호출하는 동안 그 작업을 실행하며 PHP 콜백을 부릅니다.
3. 결과가 필요한 콜백이면 호출 스레드가 결과를 기다립니다.

이 방식은 아직 구현하지도, 시험하지도 않았습니다. 기다리는 동안 교착이 생기는 경우(UI 스레드가 그 스레드를 기다리는 경우)를 [주의 사항](../reference/cautions.md)에 둡니다. 일부 핸들러는 PHP로 보내지 않고 네이티브 기본 응답으로 처리하는 선택지도 있습니다.

### 규칙 4. 예외를 CEF로 전파하지 않는다

사전 검증에서 FFI 콜백이 예외를 던지자 "Throwing from FFI callbacks is not allowed" 치명 오류가 나면서 프로세스가 종료되었습니다. 확장에서는 모든 콜백 진입점(트램펄린)이 PHP 예외를 잡아 PHP의 오류 처리기로 보고하고, 정해진 기본 반환값으로 CEF에 돌아가야 합니다. `cefweaver`가 Python에서 `sys.excepthook`으로 보고하는 것과 같은 방침입니다.

## 메시지 루프 구동

제안하는 PHP API의 모양은 다음과 같습니다(이름은 가칭이며 구현 전입니다).

```php
$app = new Cefloom\App(...);
$app->initialize($url);
while ($app->isRunning()) {
    $app->doMessageLoopWork();   // CefDoMessageLoopWork()
    // 필요하면 다음 펌프 시점까지 대기(원자적 변수 또는 eventfd)
}
$app->shutdown();
```

사전 검증은 2밀리초 간격의 `usleep` 폴링으로 구동했고 정상 동작했습니다(실행당 약 1만 4천 회 순환). 지연 시간을 존중하는 대기 방식이 CPU 사용량에 얼마나 유리한지는 측정하지 않았습니다. `cefweaver`의 위키는 CEF가 창을 소유하는 Views 모드에서 외부 펌프가 입력을 처리하지 못하고 폴링이 필요했다고 기록합니다(`cefweaver_clone/cefweaver/llm-wiki/pages/concepts/lifecycle-and-message-loop.md`). 창 모드 선택에 영향을 줄 수 있으나 PHP에서는 확인하지 않았습니다.

## 종료 순서

사전 검증은 `close_browser(force)`, `on_before_close` 수신, 메시지 루프 몇 회 추가, `cef_shutdown()` 순서로 종료했고 모든 실행의 종료 코드가 0이었습니다. `cefweaver`는 브라우저를 닫기 전에 `CefShutdown()`을 부르면 세그멘테이션 오류가 났다고 기록합니다. 확장에서는 PHP 요청 종료(RSHUTDOWN)나 스크립트 비정상 종료 시에도 이 순서를 지키는 안전장치가 필요합니다(미검증).

## 관련 페이지

- [수명 관리](object-lifetime.md)
- [프로세스 모델과 JS 브리지](process-model-and-js-bridge.md)
- [PHP 실행 환경의 제약](php-runtime-constraints.md)
- [검증 하네스](../components/ffi-precheck-harness.md)
