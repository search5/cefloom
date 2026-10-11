---
title: FFI 사전 검증 하네스
type: component
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefloom/experiments/ffi-precheck/mkcdef.py
  - cefloom/experiments/ffi-precheck/run.sh
updated: 2026-10-12
---

# FFI 사전 검증 하네스

`cefloom/experiments/ffi-precheck/`는 C 확장 설계의 전제를 확인하려고 만든 검증 자료입니다. 제품 코드가 아니며, PHP FFI로 CEF C API를 구동합니다. 확장 모듈을 만든 뒤에는 같은 검증을 확장으로 다시 할 수 있는 기준 시나리오가 됩니다.

## 구성

| 파일 | 역할 |
| --- | --- |
| `run.sh` | 전체 과정을 한 번에 재현합니다: 헤더 전처리, 선언 생성, shim 컴파일, 가상 X 서버에서 실행 |
| `mkcdef.py` | `gcc -E` 출력에서 CEF 헤더에서 온 줄만 남기고 `__attribute__` 등 FFI가 못 읽는 구문을 지웁니다. |
| `refshim.c` | 네이티브 C 함수 모음: 참조 카운트 네 함수와 `on_schedule_message_pump_work`. 호출 횟수와 메인 스레드 밖 호출 횟수를 원자적으로 셉니다. |
| `verify.php` | 핵심 시나리오: 초기화, 핸들러 구성, 브라우저 생성, 메시지 루프, JS 실행, 종료. 결과를 항목별로 통과 또는 실패로 보고합니다. |

## 검증 시나리오 (verify.php)

1. `cef_api_hash`로 API 버전을 설정하고 `cef_version_info`로 버전을 읽습니다.
2. 핸들러 구조체를 만듭니다: display(`on_console_message`), load(`on_load_end`), life span(`on_after_created`, `on_before_close`), render(`get_view_rect`, `on_paint`), browser process(`on_context_initialized`, `on_schedule_message_pump_work`), client, app.
3. `cef_execute_process`가 -1을 돌려주는지 확인합니다(브라우저 프로세스).
4. `cef_initialize`를 `external_message_pump=1`, `no_sandbox=1`, 서브프로세스 경로 지정으로 호출합니다. `on_before_command_line_processing`에서 `--ozone-platform=x11`을 추가합니다.
5. 브라우저를 만들고 PHP 루프에서 `cef_do_message_loop_work()`를 2밀리초 간격으로 호출합니다.
6. `on_load_end`에서 `console.log('PHPOK ' + (1 + 2))`를 실행하고, `on_console_message`로 `PHPOK 3`이 돌아오면 브라우저를 닫습니다.
7. `on_before_close`를 확인하고 `cef_shutdown()`을 호출합니다.
8. 각 콜백이 실행된 스레드 번호(`gettid`)를 기록해 PHP 메인 스레드와 같은지 비교합니다.

모드는 `windowed`(기본)와 `osr`(오프스크린)이며 환경 변수 `MODE`나 `run.sh`의 첫 인자로 고릅니다.

## 한계

- 참조 카운트 shim은 객체를 해제하지 않습니다. 수명과 소멸은 검증 대상이 아닙니다.
- 핸들러 수가 적고 한 번에 브라우저 하나만 만듭니다.
- 렌더러 프로세스 쪽 코드는 없습니다(`cefweaver`의 서브프로세스를 그대로 사용).
- 일반 사용자 PC의 Wayland 환경이 아닌 Xvfb에서만 실행했습니다.
- 스크립트는 FFI의 제약(함수 포인터 호출 문법, 클로저 보관)에 맞춘 것이며 확장 모듈의 코드 구조를 대표하지 않습니다.

## 관련 페이지

- [사전 검증 재현 절차](../procedures/run-ffi-precheck.md)
- [실험으로 확인한 사실](../reference/verified-findings.md)
- [스레드와 메시지 루프](../concepts/threading-and-message-loop.md)
