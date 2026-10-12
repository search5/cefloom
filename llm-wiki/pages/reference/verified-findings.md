---
title: 실험으로 확인한 사실
type: reference
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefloom/experiments/ffi-precheck/mkcdef.py
  - cefloom/experiments/ffi-precheck/run.sh
  - cefloom/ext/runtime/cl_runtime.cc
  - cefloom/tests/t_thread_tasks.php
  - cefloom/tests/t_scheme.php
  - cefloom/tests/run.sh
  - cefloom/ext/src/async.cc
  - cefloom/tests/t_sync_timeout.php
  - cefloom/subprocess/cefloomsubprocess.cc
  - cefloom/tools/build.sh
  - cefloom/ext/src/lazy_stubs.cc
  - cefloom/tests/t_no_runtime.php
  - cefloom/tests/t_pdf.php
  - cefloom/tests/t_views.php
  - cefloom/tests/t_cast.php
  - cefloom/tests/t_views_input.php
  - cefloom/ext/src/core.cc
  - cefloom/experiments/media-router-shutdown/repro.php
  - cefloom/tools/package.sh
  - cefloom/bin/cefloom-runtime
  - cefloom/composer.json
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

## F13. 브라우저가 없으면 `FILE_BACKGROUND` 작업이 실행되지 않는다

- **방법**: 확장 모듈(`cefloom.so`)에서 `Cef::postTask`로 스레드별 작업을 보내고 PHP 메인 스레드에서 실행되기를 기다렸습니다. 브라우저를 만들기 전과 만든 뒤를 비교했습니다. 같은 조건에서 `FILE_USER_VISIBLE`, `FILE_USER_BLOCKING`, `IO` 스레드로 보낸 작업은 즉시 실행되었습니다.
- **결과**: 브라우저가 없을 때 `FILE_BACKGROUND` 작업은 4초와 5초 동안 실행되지 않았습니다. 브라우저를 만든 뒤에는 약 2.5초에서 2.9초 사이에 실행되었습니다. 보류된 작업이 남은 상태에서 `t_thread_tasks`가 17분 넘게 끝나지 않았는데, `Cef::shutdown()`이 그 작업을 기다리기 때문으로 추정합니다.
- **해석**: Chromium이 우선순위가 낮은 작업을 브라우저 시작이 끝날 때까지 보류하는 것으로 해석합니다. 소스에서 확인하지는 않았습니다.
- **영향**: `FILE_BACKGROUND`를 검증하는 테스트는 브라우저를 먼저 만들어야 합니다. 라이브러리 사용자에게는 브라우저 없이 이 스레드로 작업을 보내면 실행이 늦어질 수 있다고 안내해야 합니다.

## F14. IO 스레드의 핸들러가 UI 스레드를 기다리면 교착이 일어난다

- **방법**: `t_scheme`와 `t_browser_nav`가 전체 실행 중 무작위로 멈추는 현상을 추적했습니다. `gdb` 아래에서 `t_scheme`를 반복 실행하다가 멈춘 순간에 모든 스레드의 호출 스택을 받았습니다. 30회 안팎의 실행에서 1회에서 3회 멈췄습니다. `libcef.so`에는 심볼이 없으므로, 같은 빌드 ID(`77be730099aeb3ce0aa9af524f13eecdb69b04e4`)를 가진 심볼 포함본(`cefweaver_clone/cefweaver/third_party/cef/cef_binary_154.0.34+g14c5a08+chromium-154.0.8037.98_linux64/Release/libcef.so`)으로 메인 스레드 스택의 반환 주소를 `addr2line`으로 풀었습니다.
- **결과**: `Chrome_IOThread`는 `ResourceHandler::GetResponseHeaders`의 프록시에서 `cl::run_on_php_thread`(`cl_runtime.cc`)로 진입해 UI 스레드가 작업을 실행하기를 기다렸습니다. 같은 순간 PHP 메인 스레드는 `CefDoMessageLoopWork()` 안에서 다음 순서로 멈춰 있었습니다. 합성기의 `cc::Scheduler::OnBeginImplFrameDeadline`이 `SingleThreadProxy::DoComposite`와 `LayerTreeHostImpl::DrawLayers`를 거쳐 `GpuChannelHost::VerifyFlush`를 부르고, 이것이 GPU 프로세스에 동기 mojo 호출(`GpuChannelProxy::GetSharedMemoryForFlushId`)을 보낸 뒤 `WaitableEvent::Wait`로 응답을 기다렸습니다.
- **해석**: Chromium의 IO 스레드는 브라우저 프로세스의 mojo 통신도 처리합니다. IO 스레드가 PHP 호출 때문에 막히면 GPU 프로세스의 응답을 받지 못하고, UI 스레드는 그 응답을 기다리느라 IO 스레드가 기다리는 작업을 실행하지 못합니다. 두 스레드가 서로를 기다립니다. 화면을 그리는 시점과 사용자 정의 스킴이나 리소스 요청이 겹칠 때만 일어나므로 무작위로 보입니다.
- **한계**: 위 호출 순서는 한 번의 멈춤에서 얻은 것입니다. 표식을 넣은 사본에서는 15회 모두 통과해 멈춤을 재현하지 못했습니다. 응답을 보내지 못하는 구체적 경로(IO 스레드가 막혀서 GPU 응답을 처리하지 못한다는 부분)는 소스로 확인하지 않은 해석입니다.
- **영향**: [주의 사항](cautions.md) 5번의 우려가 실제로 일어난다는 증거입니다. IO 스레드에서 불리는 핸들러는 PHP의 실행을 기다리며 IO 스레드를 막으면 안 됩니다. 현재 구조(`run_on_php_thread`)는 수정이 필요합니다. - **조치와 결과**: IO 스레드에서 불리는 핸들러 가운데 비동기로 처리할 수 있는 것을 `ext/src/async.cc`에서 비동기로 바꾸었습니다([설계 결정 D12](design-decisions.md)). 바꾼 뒤 `t_scheme` 30회, `t_browser_nav`와 `t_handlers` 각 20회, 전체 9개 테스트 5회를 모두 하나씩 순차로 실행했고 실패와 멈춤이 없었습니다. 바꾸기 전에는 같은 방식의 `t_scheme` 실행 27회 안팎에서 멈춤이 3회 있었습니다. 실행 횟수가 적으므로 해결을 증명한 것은 아니며, 즉시 반환값이 필요한 콜백이 남아 있으므로 교착 가능성이 없어진 것도 아닙니다.
- **완화**: 즉시 반환값이 필요한 콜백에서 CEF 스레드가 PHP 스레드를 기다릴 때, PHP 스레드가 `CefDoMessageLoopWork()` 안에 `CEFLOOM_SYNC_WAIT_MS`(기본 5000)밀리초 넘게 머물면서 호출을 가져가지 않으면 기다림을 포기하고 CEF의 기본 동작을 씁니다. 이때 표준 오류에 경고를 한 번 출력합니다. `t_sync_timeout`이 UI 스레드를 콜백 안에서 붙잡아 이 경로를 검증하며, 타임아웃을 크게 한 사본에서는 실패하는 것을 확인했습니다. 교착을 막는 것이 아니라 풀어 주는 방식이므로, 그 사이의 요청은 PHP의 판단 없이 기본 동작으로 처리됩니다.

## F15. 서브프로세스의 스택 보호가 종료할 때 `stack smashing detected`를 일으킨다

- **방법**: `t_thread_tasks`와 브라우저를 쓰는 실험 스크립트가 끝날 때 `*** stack smashing detected ***: terminated`를 두 번 출력했습니다. `strace -f -ff -e trace=execve,tgkill`로 `SIGABRT`를 보낸 프로세스를 찾았습니다.
- **결과**: `SIGABRT`를 낸 것은 `cefloomsubprocess --type=zygote`가 fork한 자식 프로세스 2개였고, 둘 다 `__stack_chk_fail`로 종료했습니다(PHP 본체와 확장 코드는 아니었습니다). 서브프로세스의 `main`에는 스택 보호값(canary) 검사가 들어 있었고(`objdump`로 확인), `CefExecuteProcess`에는 없었습니다. 서브프로세스를 `-fno-stack-protector`로 빌드하자 같은 테스트 3회에서 메시지가 사라졌고, 전체 테스트에서도 나오지 않았습니다.
- **해석**: Chromium의 zygote는 `--change-stack-guard-on-fork=enable`로 fork한 프로세스의 스택 보호값을 바꿉니다. fork 이전부터 호출 스택에 있던 `main`은 이전 값을 저장하고 있으므로, 프로세스가 종료하며 `main`에서 반환할 때 검사에 실패하는 것으로 해석합니다. 이 인과 관계는 소스로 확인하지 않았습니다.
- **영향**: 서브프로세스(`tools/build.sh`)는 스택 보호 없이 빌드합니다. 우분투의 기본 컴파일러 옵션은 `-fstack-protector-strong`이므로 다른 환경에서 서브프로세스를 빌드할 때도 같은 옵션이 필요합니다.

## F16. `libcef.so`를 필요할 때 불러오는 확장이 동작한다

- **방법**: 빌드 옵션 `CEFLOOM_LAZY_LIBCEF=1`로 `libcef.so`에 링크하지 않는 `cefloom.so`를 만들었습니다. 모듈이 있는 디렉터리(또는 `CEFLOOM_HOME`)의 `libcef.so`를 `dlopen(RTLD_NOW | RTLD_GLOBAL)`로 처음 필요할 때 불러온 뒤 `cef_api_hash`를 설정합니다. 런타임이 없는 디렉터리에 `cefloom.so`만 복사한 환경과 런타임이 옆에 있는 환경에서 시험했습니다.
- **결과 1, 로드를 막는 심볼 3개**: 처음에는 `undefined symbol: cef_get_min_log_level`로 모듈이 로드되지 않았습니다. 래퍼 라이브러리의 `cef_logging.cc.o`가 로그 함수 3개(`cef_log`, `cef_get_min_log_level`, `cef_get_vlog_level`)의 주소를 구조체에 저장해서 로드 시점에 즉시 해석되었기 때문입니다(`readelf -r`로 확인: 즉시 해석 3개, 지연 해석 157개). 이 3개를 CEF 헤더를 포함하지 않는 파일(`ext/src/lazy_stubs.cc`)에서 숨김 가시성(hidden)의 전달 함수로 정의해 해결했습니다.
- **결과 2, 런타임이 없을 때**: 모듈이 로드되고(`php -n -d extension=...`), `Cef::initialize()`는 런타임이 없다는 `Error`를 던집니다. 설정 구조체 같은 순수 PHP 객체는 그대로 동작합니다. 그러나 CEF 함수를 부르는 정적 함수(`Cef::parseJSON`, `Cef::postTask`, `Request::create` 등)는 `symbol lookup error`로 프로세스를 종료시켰습니다(종료 코드 127). 생성기가 정적 함수와 전역 함수의 맨 앞에 런타임 확인을 넣도록 고쳐 모두 `Error`가 되었습니다.
- **결과 3, 비용**: PHP 프로세스 하나의 시작 시 최대 메모리 사용량은 확장이 없을 때 약 16MB, 일반 방식(링크)은 약 77MB와 0.02초, 지연 로딩은 약 20.5MB와 0.01초였습니다(런타임이 옆에 있어도 사용하지 않으면 같습니다). 측정은 각 한 번에서 세 번이며 통계적 처리는 하지 않았습니다.
- **결과 4, 검증**: 지연 로딩 빌드에서도 전체 테스트가 통과했고(12개, 3회 반복), `t_no_runtime`(런타임 없이 로드하는 경로)이 10회 반복에서 실패하지 않았습니다.
- **발견한 결함**: 지연 로딩 시험 중 `tools/build.sh`가 런타임 파일을 다른 파일 시스템으로 복사할 때 `locales/`가 `locales/locales/`로 중첩 복사되어 로케일 파일이 빠지는 결함을 찾았습니다. 그 결과 CEF가 `LoadLocalState`에서 `SIGTRAP`으로 중단되었습니다. 하드 링크에 실패하면 불완전한 디렉터리를 지우고 복사하도록 고쳤습니다.
- **한계**: 리눅스 x86_64, glibc, PHP 8.5 CLI에서만 시험했습니다. PHP-FPM이나 ZTS에서는 시험하지 않았습니다. 런타임을 나중에 설치하는 경우나 `dlopen`이 실패하는 다른 사유(권한, 버전 불일치)에서의 동작은 확인하지 않았습니다.
- **영향**: [배포 전략](../concepts/distribution-strategy.md)의 미해결 과제가 해결되었습니다. 이 방식은 이후 기본 빌드가 되었습니다([D13](design-decisions.md)).

## F17. PIE가 패키지를 인식하고, 모듈과 런타임을 따로 설치해도 테스트가 통과한다

- **방법**: PIE 1.5.2의 `pie.phar`를 내려받아 GitHub 릴리스의 SHA-256 다이제스트와 일치하는 것을 확인한 뒤 격리된 홈 디렉터리에서 사용했습니다. 설치(`install`)는 하지 않았습니다. `tools/package.sh`로 만든 모듈 압축 파일과 런타임 압축 파일로, `cefloom.so`만 있는 디렉터리와 `bin/cefloom-runtime`이 사용자 데이터 디렉터리에 푼 런타임을 조합해 전체 테스트를 실행했습니다.
- **결과 1, PIE**: `pie repository:add path`로 저장소를 등록하고 `pie info cefloom/cefloom:dev-main`을 실행하자, 확장 이름 `cefloom`, 유형 php-ext, NTS와 리눅스 지원, `php >=8.5`가 맞게 인식되었고 설정 옵션은 없다고 나왔습니다. `pie download`는 `pre-packaged-binary` 방식을 골랐고, GitHub에서 태그로 릴리스를 찾지 못해 멈추었습니다. 릴리스가 없으므로 예상된 결과입니다.
- **결과 2, 오프라인 흐름**: 런타임 설치에 약 1.5초가 걸렸고(압축 150MB), 설치한 런타임으로 12개 테스트가 모두 통과했습니다.
- **발견한 문제 3개**: ① `tar ... .`로 만든 런타임 압축 파일은 `PharData`가 `.` 항목을 풀지 못했습니다(항목을 이름으로 나열해 해결). ② PHP 8.5에서 `PHP_ZTS`와 `PHP_DEBUG`가 `bool`이라 `echo`하면 빈 문자열이 되어 패키징 스크립트의 판별이 틀렸습니다(정수로 변환). ③ `php -n`에서는 `phar`가 로드되지 않아 설치 도구가 실패했습니다(시작할 때 안내하도록 보강).
- **한계**: 실제 GitHub 릴리스로 `pie install`을 끝까지 실행하지 않았습니다. 그래서 PIE가 우리 압축 파일을 자산으로 찾아 푸는 부분(자산 이름은 PIE 소스로, 압축 파일 안의 `cefloom.so` 위치는 PIE의 `UnixBuild`로 확인한 것이며 실행하지는 않았습니다), `php.ini` 활성화, 버전 태그(`0.1.0`과 `v0.1.0`)에 따른 자산 이름 차이는 확인하지 못했습니다. 리눅스 x86_64, PHP 8.5 NTS에서만 시험했습니다.

## F18. Views, PDF 출력, Cast가 실제로 동작하고, 미디어 옵저버는 종료 때 CEF가 중단된다

- **방법**: 생성기가 만든 클래스의 커버리지를 확인하고(생성하지 못한 메서드 0개), 세 영역을 실제로 실행하는 테스트 `t_views`, `t_pdf`, `t_cast`를 작성해 각각 10회 반복했습니다. 환경은 가상 X 서버(Xvfb), 창 관리자 없음입니다.
- **결과, Views**: `Window::createTopLevelWindow`에 `WindowDelegate`를 주고 `onWindowCreated`에서 `BrowserView`를 `addChildView`한 뒤 `show`하자, 창이 보이고(`isVisible`), 제목과 크기가 설정되고, 페이지가 창 안에서 로드되었으며, `close()` 뒤에 `canClose`와 `onWindowDestroyed`가 호출되었습니다. 창 없이 만드는 `MenuModel`(항목 추가, 활성 상태 변경)과 `LabelButton`도 동작했습니다. 10회 반복에서 실패가 없었습니다.
- **결과, PDF 출력**: `printToPDF`로 쓴 파일이 `%PDF-`로 시작하고 1000바이트 넘게 채워졌으며 `PdfPrintCallback`이 성공을 알렸습니다.
- **결과, Cast**: `MediaRouter::getGlobalMediaRouter`, `getSource`가 동작했습니다. DIAL 소스의 스킴은 `dial:`이 아니라 `cast-dial:`입니다(`dial:`로 만든 소스는 `isCastSource()`가 참). Cast 기기가 없는 환경에서도 옵저버를 등록하고 `notifyCurrentSinks`와 `notifyCurrentRoutes`를 부르면 `onSinks`가 2에서 3회, `onRoutes`가 3에서 4회 호출되었습니다.
- **결과, 종료 때 중단**: 미디어 라우터 관리자가 만들어진 뒤(`addObserver` 또는 `notifyCurrentSinks` 호출) `Cef::shutdown()`을 부르면 프로세스가 `SIGTRAP`으로 중단됩니다. 옵저버 등록을 해제하거나 1초 동안 메시지 루프를 돌려도 같았고, 옵저버를 등록하지 않으면(`getSource`만 사용) 종료가 정상이었습니다. 심볼 포함 `libcef.so`로 풀어 보니 `CefBrowserContext::Shutdown`이 `CefMediaRouterManager`를 지우면서 `CefMediaRoutesObserver`의 소멸자(`media_router::MediaRoutesObserver::~MediaRoutesObserver`)에서 멈췄습니다.
- **해석**: `CefMediaRouterManager`의 생성자가 `MediaRoutesObserver`를 만들고, 종료 때 이를 지우는 시점에 미디어 라우터 서비스가 이미 사라져 있어 확인에 실패하는 것으로 추정합니다. CEF 단독(PHP 없이)으로는 재현하지 않았으므로 CEF 쪽 문제인지 확정하지 못했습니다. 재현 스크립트는 `experiments/media-router-shutdown/repro.php`입니다.
- **한계**: 실제 Cast 기기와의 세션(`createRoute`)은 시험하지 못했습니다. Views에서 키보드와 마우스 입력, 창 관리자가 있는 환경은 시험하지 않았습니다. 인쇄 대화상자(`PrintHandler`)도 시험하지 않았고 PDF 출력만 확인했습니다.

## F19. 외부 메시지 펌프에서는 CEF 소유 창이 X11 입력을 받지 못한다

- **방법**: 가상 X 서버(Xvfb)에서 `xdotool`(XTEST)로 마우스 이동, 클릭, 휠, 키 입력을 보내고, 툴바 버튼의 `onButtonPressed`와 페이지의 `mousedown`, `wheel`, `keydown`, `input` 이벤트가 오는지 시험했습니다(`t_views_input`). 같은 환경에서 `cefweaver`의 Views 스모크 시험(13개)을 실행해 비교 기준으로 삼았습니다.
- **결과 1, 증상**: `cefloom` 기본 설정(`external_message_pump = true`)에서는 창이 보이고 활성 상태이며 포커스가 있는데도(`isActive`, `hasFocus`가 참, X 창 `IsViewable`) 페이지가 마우스 이동 이벤트를 하나도 받지 못했고 버튼도 눌리지 않았습니다. `WindowInfo`로 만든 일반 창에서도 같았습니다. 메시지 루프를 5밀리초 대기, 2밀리초 대기, 쉬지 않고 돌려도, `xdotool`을 비동기로 보내도, `disable-gpu`를 빼도, 화면을 계속 다시 그리게 해도 같았습니다.
- **결과 2, 원인**: `cefweaver`의 Views 예제는 `external_message_pump`를 켜지 않고 `do_message_loop_work()`를 5밀리초마다 호출합니다(같은 환경에서 13개 통과). CEF 소스(`libcef/browser/browser_message_loop.cc`)에서 외부 펌프의 `MessagePumpExternal::Run`은 `DirectRunWork`로 작업만 실행하며 파일 디스크립터(X 연결의 소켓)를 폴링하지 않습니다. 이 때문에 CEF가 소유한 창의 X11 이벤트가 처리되지 않는 것으로 해석합니다.
- **결과 3, 수정**: 확장이 `external_message_pump`를 강제로 켜지 않도록 고쳤습니다([D14](design-decisions.md)). 수정 뒤 `t_views_input`의 검사 6개(버튼 클릭, 페이지의 마우스 클릭, 휠, 키보드 입력)가 통과했고 10회 반복에서 실패가 없었습니다. 전체 테스트는 두 모드 모두 17개가 통과했습니다(외부 펌프 모드에서 입력 시험은 건너뜁니다).
- **한계**: 창 관리자가 없는 가상 화면에서만 확인했습니다. 창 관리자가 있는 환경과 실제 화면(Wayland 포함)은 시험하지 않았습니다. 원인은 소스 읽기와 실험의 일치로 해석한 것이며, 외부 펌프에서 어떤 경로로도 입력이 오지 않는지는 확인하지 못했습니다.

## 관련 페이지

- [알려진 제약과 미검증 항목](known-constraints.md)
- [주의 사항](cautions.md)
- [검증 하네스](../components/ffi-precheck-harness.md)
