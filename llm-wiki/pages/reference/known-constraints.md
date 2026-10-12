---
title: 알려진 제약과 미검증 항목
type: reference
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefweaver_clone/cefweaver/CLAUDE.md
  - cef_origin/include/capi/cef_base_capi.h
updated: 2026-10-12
---

# 알려진 제약과 미검증 항목

원본을 끝까지 추적해도 근거를 얻을 수 없거나, 이번 조사에서 확인하지 못한 항목을 모읍니다. 항목마다 이유와 확인 방법을 적습니다. 해결되면 이 페이지에서 지우고 log.md에 기록합니다.

## 기술 검증

| 번호 | 항목 | 확인하지 못한 이유 | 확인 방법 |
| --- | --- | --- | --- |
| K1 | 실제 객체 해제와 소멸 순서 | 사전 검증의 shim이 `release`에서 0을 돌려주고 객체를 해제하지 않음 | 확장에서 카운터를 구현한 뒤 종료와 재시작 시험 |
| K2 | `get_*` 핸들러 getter가 돌려주는 포인터의 참조 규약 | 헤더에 명시가 없고 shim은 카운트를 세지 않음 | `cef_origin/libcef_dll/ctocpp`와 `cpptoc` 소스를 읽어 확인 |
| K3 | UI 스레드가 아닌 스레드의 핸들러를 UI 스레드로 넘기는 구조 | 설계만 있고 구현하지 않음 | 리소스 핸들러 등으로 구현해 교착과 지연 측정 |
| K4 | 렌더러 프로세스를 거치는 JS에서 PHP로의 호출 | 사전 검증은 `cefweaver`의 서브프로세스를 그대로 썼고 렌더러 코드를 만들지 않음 | JS 브리지를 구현해 시험 |
| K5 | Windows와 macOS | Linux에서만 실행함 | 각 플랫폼에서 하네스 실행. `cefweaver`가 지원하는 플랫폼이므로 기반은 있음 |
| K6 | Wayland 환경의 실제 화면 | Xvfb에서만 실행함. 실제 화면에 창이 열리는 시험은 허락이 필요함 | 사용자 허락 후 `WAYLAND_DISPLAY`를 유지하고 실행 |
| K7 | ZTS PHP | NTS 빌드 한 가지만 설치함 | ZTS 빌드로 확장을 만들어 시험 |
| K8 | PHP 8.5 외의 버전 | 설치 가능한 패키지가 8.5뿐이었음(후보 `php-cli 2:8.5+99ubuntu1`) | 다른 버전 설치 후 확장 빌드 |
| K9 | 샌드박스를 켠 상태 | `no_sandbox=1`로만 실행함 | `chrome-sandbox`의 권한 설정과 함께 시험 |
| K10 | 장시간 실행, 다중 브라우저, 메모리 누수 | 한 번에 브라우저 하나, 수 초 실행 | 반복 생성과 종료, 메모리 측정 |
| K11 | 지연 시간을 존중하는 펌프 대기의 효과 | 2밀리초 폴링만 사용하고 CPU 사용량을 측정하지 않음 | `on_schedule_message_pump_work`의 지연 값으로 대기하는 구현과 비교 |
| K12 | Views(CEF 소유 창) 모드의 입력 처리 | 해결: 외부 펌프를 끄면 입력이 옴([F19](verified-findings.md), [D14](design-decisions.md)). 최소 창 관리자 `twm`에서는 확인함. 데스크톱 환경의 창 관리자와 Wayland는 시험하지 않음. `cefweaver`의 기록은 외부 펌프로는 입력이 처리되지 않았다는 것 | 하네스에 Views 모드를 추가 |
| K13 | PHP의 요청 종료 단계와 `cef_shutdown()` 순서 | FFI 스크립트는 명시적으로 종료함 | 확장의 RSHUTDOWN과 객체 파괴 순서를 실험 |
| K14 | 오프스크린 모드의 픽셀 내용 | 해결: `t_osr`가 `onPaint`의 BGRA 버퍼를 읽어 배경과 버튼의 색을 확인하고, 클릭 뒤 다음 프레임에서 색이 바뀐 것까지 확인합니다 | |
| K15 | 두 `libcef.so`의 크기 차이(272MB와 1.4GB)의 원인 | 이번에 조사하지 않음. 디버그 정보 유무로 추정 | 두 파일을 `readelf`나 `file`로 비교 |

## 조사 필요 항목

| 번호 | 항목 | 이유 |
| --- | --- | --- |
| K16 | PHP 확장에서 클래스 등록과 인자 정보를 생성하는 표준 방법 | 생성기 설계에 영향. 조사하지 않음 |
| K17 | PHP 확장 전용 설치 도구(PIE 등)의 현재 상태 | 문서만 읽음(2026-10-12, [배포 전략](../concepts/distribution-strategy.md)). PIE를 실행해 보지 않았고 바이너리 일치 규칙의 세부는 문서에 없음 |
| K18 | `cefweaver`의 코드를 재사용할 때의 라이선스와 결합도 | 읽기만 했고 라이선스 파일을 확인하지 않음 |
| K19 | `java-cef`의 메시지 라우터와 `cefpython`의 JS 바인딩 내부 | 존재만 확인하고 정독하지 않음 |

## 시장 조사의 한계

| 번호 | 항목 | 이유 |
| --- | --- | --- |
| K20 | PHP Desktop의 현재 유지보수 상태 | 검색 결과의 튜토리얼 한 건에서만 확인함. 저장소를 직접 조회하지 않음 |
| K21 | NativePHP의 내부 구조(Electron 여부) | 문서 본문에 구조 설명이 없고 크레딧과 저장소 토픽으로 추정만 함 |
| K22 | 설치 수와 별 수 | 웹 검색에 노출된 스냅샷이며 사이트마다 값이 다름. 조회일 2026-10-12 |
| K23 | CEF 바인딩에 대한 실제 수요 | 직접 증거를 찾지 못함. 인접 수요(PHP 데스크톱 앱)만 확인함 |
| K24 | 게시한 릴리스로 `pie install`을 끝까지 실행한 결과 | 릴리스가 없어 시험하지 못함. 오프라인 흐름과 PIE의 메타데이터 인식만 확인([F17](verified-findings.md)) | 저장소를 게시하고 릴리스를 만든 뒤 격리된 환경에서 `pie install`을 실행 |
| K25 | CEF 런타임을 재배포할 때 필요한 라이선스 고지 | 런타임 압축 파일에 CEF와 Chromium의 라이선스 파일을 넣지 않았고 요건을 조사하지 않음 | CEF 배포본의 `LICENSE.txt`와 `README.txt`, Chromium의 고지 요건을 읽고 압축 파일에 포함 |
| K26 | 미디어 라우터를 쓴 뒤 `Cef::shutdown()`이 중단되는 원인과 해결 | 중단 위치만 확인([F18](verified-findings.md)). PHP 없이 CEF만으로 재현하지 않았고 우회 방법을 찾지 못함 | C++ 최소 예제로 재현해 CEF에 보고하거나, 종료 순서를 바꾸는 방법을 시험 |
| K27 | 실제 Cast 기기, 데스크톱 환경의 창 관리자와 Wayland에서의 Views | 창과 PDF 출력과 라우터 호출과 입력(가상 화면)만 시험함([F18](verified-findings.md), [F19](verified-findings.md)). 인쇄 핸들러는 [K39](known-constraints.md)로 결론이 났음 | 데스크톱 환경의 화면에서 입력을 보내 봄 |
| K28 | 저장소의 라이선스 | 라이선스 파일이 없음(공개 저장소이므로 기본적으로 사용 권리가 없음). 소유자가 아직 정하지 않았고, K18과 K25에 따라 선택이 달라질 수 있음 | 소유자가 선택하고, 포함한 제3자 코드(`tools/gen/vendor/`), `cefweaver` 재사용분(K18), CEF 재배포 고지(K25)를 확인해 `LICENSE`를 추가 |
| K29 | 테스트가 없는 구현 | 대부분 해소: 전체 메서드의 실행 비율이 20.7%에서 65.8%로, `async.cc`가 57.8%에서 87.8%로 올랐고 도구와 생성기와 패키징에도 자동 시험이 생겼음([테스트 커버리지 분석](../analyses/test-coverage.md)). 남은 것은 환경 때문에 시험하지 못하는 부분(K26, K27, K37, K39) | 실제 Cast 기기, 소리 장치, 창 관리자가 있는 환경에서 시험 |
| K30 | `onResourceRedirect`가 돌려준 URL이 적용되지 않음 | 핸들러는 올바른 인자로 불리고 새 URL을 돌려주지만, 로드는 서버가 알려 준 원래 URL에서 끝납니다. CEF 래퍼의 cpptoc 함수(`resource_request_handler_cpptoc.cc`)가 `new_url`을 복사본으로 받고 되돌려 쓰지 않는 것이 유력한 원인입니다. `tests/t_redirect_change.php`가 기대 실패(xfail)로 기록하며, 고쳐지면 시험이 알려 줍니다. 우회: `onBeforeResourceLoad`에서 `Request::setURL()`로 바꿉니다(시험으로 확인). |
| K31 | CEF가 UI 스레드에서 거부하는 호출 | `Cef::directoryExists()`와 파일 함수 일곱 개, `WaitableEvent::wait()`와 `timedWait()`는 CEF가 UI와 IO 스레드에서 거부하고 `false`를 돌려줍니다(존재하는 디렉터리에도 `false`). PHP는 UI 스레드에서 돌기 때문에 이 호출은 생성된 코드가 별도 스레드에서 실행하고 기다리도록 했습니다(`scope.py`의 `OFF_UI_THREAD`). PHP 콜백은 부르지 않으므로 교착은 없습니다. 같은 제약을 가진 호출이 더 있으면 이 목록에 추가합니다. |
| K32 | `stoppable=false`인 스레드는 해제하면 프로세스가 죽음 | `Thread::create(..., stoppable: false, ...)`로 만든 스레드는 CEF가 합류(join)할 수 없어서, 객체가 해제되거나 `Cef::shutdown()`이 해제할 때 CEF의 검사에 걸려 `SIGTRAP`으로 끝납니다. 시험은 `stoppable: true`만 씁니다. 같은 이유로 `XmlReader`의 이름으로 찾는 오버로드(`getAttribute(string)` 등)는 같은 이름의 오버로드는 첫 번째만 만든다는 규칙 때문에 없습니다(인덱스 버전만 있음). |
| K33 | 선택 인자인 콜백에 `null`을 주면 CEF가 프로세스를 죽임 | `RequestContext::clearHttpCache(null)`, `clearHttpAuthCredentials(null)`, `closeAllConnections(null)`는 헤더에 "NULL이면 호출하지 않는다"고 적혀 있으나, 구현은 `base::BindOnce(&CefCompletionCallback::OnComplete, callback)`로 검사 없이 호출해 `SIGSEGV`로 끝납니다. 생성된 코드는 `CompletionCallback`을 `null`로 받으면 아무것도 하지 않는 콜백을 대신 넘깁니다(`cl::noop_completion()`). `t_context`가 고정합니다. |
| K34 | 관찰자 등록은 반환된 `Registration`이 살아 있는 동안만 유효 | `PreferenceManager::addPreferenceObserver()`와 `RequestContext::addSettingObserver()`가 돌려주는 등록 객체를 버리면 관찰이 끝납니다(CEF의 규칙). 변수에 보관해야 합니다. |
| K35 | `Server::isRunning()`는 PHP 스레드에서 항상 `false` | CEF는 이 호출을 서버 스레드에서만 답하도록 정해 두었고, PHP는 UI 스레드에서 돌기 때문에 서버가 돌고 있어도 `false`입니다(`hasConnection()`도 같은 제약일 수 있음). 서버가 만들어졌는지는 `ServerHandler::onServerCreated()`와 `onServerDestroyed()`로 판단합니다. `sendHttp200Response()` 등 응답 메서드는 UI 스레드에서 정상 동작함을 시험으로 확인했습니다. 서버 스레드로 넘겨 답하는 방법은 아직 구현하지 않았습니다. |
| K36 | `LifeSpanHandler::doClose()`가 불리지 않음 | `createBrowser()`로 만든 브라우저는 CEF가 만든 최상위 창에서 돌아서 `closeBrowser()`와 `tryCloseBrowser()`가 `doClose()` 없이 곧바로 `onBeforeClose()`로 이어집니다. `doClose()`가 `true`를 돌려주어도 닫기를 막지 못합니다. 닫기를 막으려면 페이지의 `beforeunload`를 쓰거나 `closeBrowser(false)`를 부르는 쪽에서 판단합니다(`t_browser_host`로 확인). |
| K37 | `AudioHandler`가 이 환경에서 불리지 않음 | 소리 장치가 없는 가상 화면 환경에서는 `AudioContext`와 `<audio>`를 재생해도 `getAudioParameters()`조차 호출되지 않습니다(창 모드와 오프스크린 모드 모두). 실제 오디오 서버가 있는 환경에서 확인이 필요하며, 시험 제외 목록에 있습니다. |
| K38 | 없는 id를 `ResourceBundle`에 물으면 프로세스가 죽음 | `getLocalizedString()`과 `getDataResource()`에 팩 파일에 없는 id를 주면 CEF 안의 검사에 걸려 `SIGTRAP`으로 끝납니다. 확인할 방법이 없어 id는 `cef_pack_strings.h`와 `cef_pack_resources.h`의 것만 써야 합니다. `getDataResource()`는 resources.pak에 없는 id에는 `null`을 돌려줍니다. |
| K39 | `BrowserHost::print()`가 `PrintHandler`를 부르지 않음 | 이 빌드의 Chrome 런타임은 인쇄를 Chrome의 인쇄 미리보기로 처리하고 `PrintHandler`에는 묻지 않습니다. `onPrintStart` 등은 불리지 않고 가짜 프린터로 흐름을 시험할 수 없습니다. 프로그램에서 인쇄하려면 `printToPDF()`를 씁니다. `PrintSettings`는 일반 객체로 쓸 수 있습니다. `t_print`가 현상을 고정하고, CEF가 바뀌어 핸들러를 부르면 시험이 실패해서 알려 줍니다. |
| K40 | `RequestContextHandler::getResourceRequestHandler()`가 요청마다 대기 한도(기본 5초)를 채움 | 이 메서드는 CEF의 IO 스레드에서 동기로 불리는데, 컨텍스트를 만들고 첫 탐색이 진행되는 동안 UI 스레드가 그 스레드를 기다려서 PHP가 받지 못하고, 한도가 지나서야 CEF의 기본값으로 넘어갑니다(F14와 같은 구조). 콜백은 한도 뒤에 뒤늦게 실행됩니다. 같은 일을 하는 `RequestHandler::getResourceRequestHandler()`(브라우저 단위)는 정상이므로 그쪽을 쓰거나 `CEFLOOM_SYNC_WAIT_MS`를 줄입니다. `t_callbacks_more`는 한도를 300ms로 줄여서 확인합니다. |
| K41 | 이벤트 구동 루프에서 붙여넣기 계열 명령 뒤의 입력이 멈춤 | 외부 펌프 모드에서 `Frame::paste()`, `undo()`, `redo()`, `pasteAndMatchStyle()`, `delete()`를 부른 뒤에는 IME와 드래그 앤 드롭 이벤트가 페이지에 도착하지 않습니다(폴링 모드는 정상). 클립보드 읽기와 관련이 있을 것으로 보지만 원인은 확인하지 못했습니다. `t_host_more`는 이 명령들을 마지막에 부릅니다. |

## 문서와 소스의 불일치

현재 없습니다.

## 관련 페이지

- [실험으로 확인한 사실](verified-findings.md)
- [주의 사항](cautions.md)
- [수요 조사](../analyses/demand-research.md)
