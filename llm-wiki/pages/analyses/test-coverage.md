---
title: 테스트 커버리지 분석
type: analysis
sources:
  - cefloom/tools/coverage.sh
  - cefloom/tools/coverage_report.py
  - cefloom/tools/build.sh
  - cefloom/ext/config.m4
  - cefloom/ext/src/async.cc
  - cefloom/ext/src/core.cc
  - cefloom/bin/cefloom-runtime
  - cefloom/tools/package.sh
  - cefloom/tests/run.sh
  - cefloom/tests/coverage-baseline.json
updated: 2026-10-12
---

# 테스트 커버리지 분석

구현만 되어 있고 테스트가 없는 부분이 어디인지 측정했습니다. 감으로 꼽지 않고 **전체 테스트가 실행하는 함수를 세었습니다**. 이 분석을 바탕으로 한 계획은 [테스트 보강 계획](../reference/test-plan.md)에 있습니다. 보강 전 측정(2026-10-12)은 테스트 17개(검사 434개)였고, 보강 뒤 측정(같은 날)은 테스트 53개입니다. 아래 "결과 1"에서 "결과 4"까지는 **보강 전**의 값이고, 보강 뒤의 값은 바로 아래 "보강 뒤 결과"에 있습니다.

## 측정 방법

- `CEFLOOM_COVERAGE=1 tools/build.sh`가 `gcov` 계측을 넣은 확장(`--coverage`, 최적화 없음)을 만듭니다. `tools/coverage.sh`가 이 빌드로 전체 테스트를 **폴링 모드와 외부 펌프 모드로 두 번** 하나씩 순차 실행하고, `tools/coverage_report.py`가 `gcov -n -f -m`의 결과를 모아 보고합니다.
- **PHP에서 보이는 메서드**를 단위로 셉니다. 라이브러리 클래스와 전역 함수는 테스트가 그 메서드(`zim_*`)를 부른 경우, 클라이언트 클래스(핸들러와 델리게이트)는 CEF가 프록시 메서드를 부른 경우를 "실행"으로 칩니다. 손으로 쓴 파일(`ext/src`, `ext/runtime`)은 줄 단위 커버리지를 봅니다.
- 이 수치는 **실행 여부**입니다. 실행했다고 검사가 충분하다는 뜻은 아닙니다. 반대로 0이면 어떤 검사도 그 코드에 도달하지 못했다는 확실한 신호입니다.

## 두 번째 보강 뒤 결과

첫 보강 뒤 남은 부분 가운데 환경(소리 장치, 창 관리자, 실제 기기)이 있는 이 컴퓨터에서 할 수 있는 것을 더 시험했습니다(`t_lib_more`, `t_views_widgets`, `t_host_more`). 요청한 상한은 70%였지만 한 번의 측정으로 84.0%가 되었습니다. 상한을 넘겼으므로 더 늘리지 않고 여기서 멈췄습니다.

전체 메서드 1,256개 중 **1,055개 실행(84.0%)**입니다. 라이브러리 클래스 89.9%, 클라이언트 클래스 66.8%입니다.

| 영역 | 첫 보강 뒤 | 두 번째 보강 뒤 |
| --- | --- | --- |
| 값과 데이터 객체 | 92% | 98% |
| 브라우저와 프레임 | 60% | 98% |
| 기본 핸들러 | 80% | 83% |
| 네트워크와 리소스 | 70% | 96% |
| 쿠키, 요청 컨텍스트, 설정 | 72% | 81% |
| Views | 44% | 79% |
| 다운로드와 렌더링 핸들러 | 63% | 70% |

이어서 Views를 79%에서 **89%**로 올렸습니다(`t_views_rest`: 델리게이트의 크기 힌트, 포커스, 키, 단축키, 메뉴 명령, 버튼 클릭, 오버레이, 레이아웃). 그 결과 전체는 **1,094개, 87.1%**입니다. Views에서 남은 것은 팝업 델리게이트(K42)와 창 관리자가 있어야 도착하는 창 델리게이트 질문들입니다.

그대로인 영역은 Cast와 미디어 19%(실제 기기, K26), 인쇄 70%(K39), 입력과 상호작용 핸들러 66%입니다. 손으로 쓴 파일의 줄 커버리지는 변하지 않았습니다.

## 첫 보강 뒤 결과

[테스트 보강 계획](../reference/test-plan.md)을 실행한 뒤 같은 방법으로 다시 쟀습니다(테스트 52개, 폴링 모드와 외부 펌프 모드에서 각각 모두 통과, 예상된 실패 2개 포함).

전체 메서드 1,256개 중 **826개 실행(65.8%)**입니다(보강 전 260개, 20.7%). 라이브러리 클래스 66.8%, 클라이언트 클래스 60.1%, 전역 함수 87.2%입니다.

| 영역 | 보강 전 | 보강 뒤 | 마일스톤 목표 |
| --- | --- | --- | --- |
| 값과 데이터 객체 | 60% | **92%** | 90% 이상, 달성 |
| 브라우저와 프레임 | 27% | **60%** | 60% 이상, 달성 |
| 기본 핸들러 | 48% | **80%** | 80% 이상, 달성 |
| 입력과 상호작용 핸들러 | 0% | 66% | |
| 다운로드와 렌더링 핸들러 | 0% | 63% | |
| 네트워크와 리소스 | 19% | **70%** | 55% 이상, 달성 |
| 쿠키, 요청 컨텍스트, 설정 | 0% | 72% | |
| 인증서와 SSL | 0% | 84% | |
| Views | 15% | 44% | |
| Cast와 미디어 | 19% | 19% | 실제 기기 없이는 한계(K26, K27) |
| 인쇄 | 3% | 70% | |
| 스트림, 압축, XML | 0% | **100%** | 70% 이상, 달성 |
| 스레드와 작업 | 4% | 88% | |
| 앱, 명령줄, 업데이터, 리소스 | 14% | 78% | |
| 전역 함수 | 15% | **87%** | 80% 이상, 달성 |

손으로 쓴 코드의 줄 커버리지:

| 파일 | 보강 전 | 보강 뒤 |
| --- | --- | --- |
| `ext/src/async.cc` | 57.8% | **87.8%** |
| `ext/src/core.cc` | 85.8% | **94.2%** |
| `ext/runtime/cl_runtime.cc` | 66.7% | **85.5%** |
| `ext/src/bridge.cc` | 80.6% | 84.0% (목표 85%에 1포인트 모자람) |
| `ext/src/cefloom.cc` | 66.7% | 66.7% (`phpinfo`와 비공개 생성자 오류) |
| `ext/src/lazy_stubs.cc` | 5.0% | 5.0% (런타임을 불러온 뒤 CEF가 로그를 남길 때만) |

**마일스톤 평가.** M1은 `bridge.cc`만 85%에 1포인트 못 미칩니다(렌더 프로세스 종료 처리는 시험했지만 `OnQuery`의 PHP 종료 중 경로가 남음). 나머지 기준은 달성했습니다. M2의 여섯 영역 기준은 모두 달성했습니다. M3의 전체 45% 기준은 65.8%로 달성했습니다.

여전히 메서드가 하나도 실행되지 않는 클래스 30개 중 5개는 메서드가 없거나(`FillLayout`, `MenuButtonPressedLock`, `Registration`) 손으로 쓴 프록시(`ResourceHandler`, `SchemeHandlerFactory`)라서 영역 표에는 0으로 나오지만 실제로는 시험됩니다. 나머지는 환경 때문에 시험하지 못한 것들입니다: 실제 Cast 기기(`MediaObserver`, `MediaRoute`, `MediaSink` 등), 소리 장치(`AudioHandler`, [K37](../reference/known-constraints.md)), 인쇄(`PrintHandler`와 콜백, [K39](../reference/known-constraints.md)), `getUserMedia`(`MediaAccessCallback`), 창 관리자가 필요한 `CommandHandler`와 Views 델리게이트 일부(`ViewDelegate` 11개는 Views 위젯의 일반 델리게이트라서 `Panels`, `Windows` 등 파생 델리게이트로만 불림), 드래그 앤 드롭(`DragHandler`).

보강 중에 시험이 찾아서 고친 결함은 [테스트 보강 계획](../reference/test-plan.md)의 진행 표와 [알려진 제약](../reference/known-constraints.md) K30에서 K40에 있습니다. 측정은 `tools/coverage.sh`이고, 결과를 `tests/coverage-baseline.json`과 비교해 숫자가 줄어들면 종료 상태 1로 끝납니다.

## 결과 1, 생성된 API (보강 전, 메서드 1,256개)

전체 실행 260개(**20.7%**). 라이브러리 클래스 78개의 901개 중 189개(21.0%), 클라이언트 클래스 60개의 316개 중 65개(20.6%), 전역 함수 39개 중 6개(15.4%)입니다. 라이브러리 클래스 57개와 클라이언트 클래스 49개는 **메서드가 하나도 실행되지 않았습니다**.

| 영역 | 클래스 | 메서드 | 실행 | 실행 0인 클래스 |
| --- | --- | --- | --- | --- |
| 값과 데이터 객체 | 11 | 162 | 97 (60%) | 5 |
| 브라우저와 프레임 | 10 | 135 | 37 (27%) | 6 |
| 기본 핸들러 | 8 | 64 | 31 (48%) | 2 |
| 입력과 상호작용 핸들러(키보드, 포커스, 컨텍스트 메뉴, JS 대화상자, 파일 대화상자, 드래그, 찾기, 권한) | 15 | 53 | 0 | 15 |
| 다운로드와 렌더링 핸들러(다운로드, 오프스크린 렌더링, 오디오, 접근성, 명령, 프레임) | 9 | 60 | 0 | 9 |
| 네트워크와 리소스 | 13 | 83 | 16 (19%) | 11 |
| 쿠키, 요청 컨텍스트, 설정 | 9 | 47 | 0 | 9 |
| 인증서와 SSL | 5 | 25 | 0 | 5 |
| Views | 24 | 389 | 59 (15%) | 15 |
| Cast와 미디어 | 8 | 27 | 5 (19%) | 6 |
| 인쇄 | 5 | 33 | 1 (3%) | 4 |
| 스트림, 압축, XML | 6 | 64 | 0 | 6 |
| 스레드와 작업 | 6 | 26 | 1 (4%) | 5 |
| 앱, 명령줄, 업데이터, 리소스 | 9 | 49 | 7 (14%) | 8 |
| 전역 함수 | 1 | 39 | 6 (15%) | 0 |

잘 시험된 것은 값 컨테이너(`Value`, `DictionaryValue`, `ListValue`, `BinaryValue`는 전부), `Browser`(21개 중 20개), `LoadHandler`, `DisplayHandler`입니다. 표의 숫자가 가장 낮은 곳이 사용자가 가장 먼저 부딪힐 기능과 겹칩니다: 쿠키, 다운로드, 컨텍스트 메뉴, JS 대화상자, 키보드와 포커스, 오프스크린 렌더링.

구체적으로 실행되지 않은 것의 예:

- `BrowserHost` 70개 중 66개: 줌, 찾기, 개발자 도구와 `DevToolsMessageObserver`, 전체 화면, 음소거, 입력 메서드(IME), 드래그, 인쇄, 파일 대화상자, 렌더 프로세스 응답성.
- `Frame` 24개 중 12개: `Copy`, `Cut`, `Paste`, `Undo`, `Redo`, `SelectAll`, `ViewSource`, `SendProcessMessage`, `CreateURLRequest`.
- `Request` 23개 중 12개(`Set`, 플래그, 참조 페이지, 리소스 유형), `Response` 18개 중 13개(헤더 맵, 상태 문구, 오류, 문자셋), `CommandLine` 22개 중 15개.
- `LifeSpanHandler`의 `OnBeforePopup`과 `DoClose`, `RequestHandler`의 `OnBeforeBrowse`, `OnCertificateError`, 렌더 프로세스 종료와 무응답 콜백, `ResourceRequestHandler`의 `OnResourceRedirect`와 `OnProtocolExecution`.
- 전역 함수 39개 중 33개: Base64, URI 인코딩, URL 파싱과 조합, MIME 유형, 경로와 임시 디렉터리, ZIP, 교차 출처 허용 목록, 추적, 크래시 키.

## 결과 2, 손으로 쓴 코드 (보강 전)

| 파일 | 줄 커버리지 | 실행되지 않은 의미 있는 부분 |
| --- | --- | --- |
| `ext/src/async.cc` | 57.8% (370줄) | `processRequest` 구형 경로, `skip`(범위 요청), `readResponse`, `StagedCallback::cancel`, `DeferredResourceHandler`의 같은 경로들, `onResourceLoadComplete`, `getAuthCredentials` |
| `ext/runtime/cl_runtime.cc` | 66.7% (402줄) | `Buffer`의 `read`, `isValid`, `isWritable`, 생성자, 시간 변환(`ret_time`, `time_from`), `buffer_fill`, 문자열 변환 일부, 오류 보고(`fatal`, `throw_error`), `ProxyBase::find` |
| `ext/src/cefloom.cc` | 66.7% (21줄) | `phpinfo`(`php --ri cefloom`)의 정보 출력, 비공개 생성자 오류 |
| `ext/src/bridge.cc` | 80.6% (160줄) | 렌더 프로세스 종료 처리, `createBrowserSync`, 비공개 생성자 |
| `ext/src/core.cc` | 85.8% (353줄) | `Cef::cefVersion()`, 외부 펌프의 `OnScheduleMessagePumpWork`(두 번째 실행에서 일부), 브라우저 수 함수 |
| `ext/src/lazy_stubs.cc` | 5.0% (20줄) | 로그 함수 전달 3개(런타임을 불러온 뒤 CEF가 로그를 남길 때만 실행됨) |

## 결과 3, 측정되지 않는 구성요소 (보강 전)

보강 뒤에는 아래 항목 모두에 자동 시험이 있습니다: `bin/cefloom-runtime`은 `t_runtime_tool`, `tools/package.sh`는 `t_package`, `tools/build.sh`는 `t_build`, `tools/gen`은 `tests/gen/test_generator.py`와 `t_generated_fresh`, `llm-wiki/lint.py`는 `t_lint`, `docs/`는 `t_docs`, `tests/run.sh`는 `t_runner`입니다. `subprocess/cefloomsubprocess.cc`는 여전히 간접 확인뿐입니다.

gcov는 C++ 확장만 잰다. 아래는 **자동 시험이 없습니다**(손으로 한 번 확인한 것은 위키의 F와 K 항목에 있음).

| 구성요소 | 현재 상태 |
| --- | --- |
| `bin/cefloom-runtime` | 성공 경로를 손으로 한 번 확인(F17). 체크섬 불일치, CEF 버전 불일치, 사이드카 없음, 이미 설치됨, `--force`, `--dir`, `status`, `path`, 깨진 압축 파일, `phar` 없음은 시험하지 않음 |
| `tools/package.sh` | 산출물 이름, 내용, 거부 조건(링크한 모듈, ZTS, 디버그, 비glibc)을 시험하지 않음 |
| `tools/build.sh` | 모드 전환(지연 로딩, 커버리지), 컴파일 실패 시 중단, 다른 파일 시스템으로의 복사를 시험하지 않음 |
| `tools/gen` (Python) | 단위 시험 없음. 생성 결과는 빌드와 테스트로만 간접 확인. `generate.py --check`(생성 파일이 최신인지)는 통과하지만 테스트로 묶여 있지 않음 |
| `llm-wiki/lint.py` | 일부러 틀린 값을 넣은 사본으로 한 번 확인(5건 감지)했고 자동 시험은 없음 |
| `docs/` | 링크와 참조 경로 점검, 문서 속 코드 두 개의 실행을 임시 스크립트로 한 번 했을 뿐 자동화되어 있지 않음 |
| `tests/run.sh` | 실행기 자체(시간 초과, 종료하지 않는 프로세스, 통과 판정)의 시험이 없음 |
| `subprocess/cefloomsubprocess.cc` | 별도 실행 파일이라 gcov 대상이 아님. 사용자 정의 스킴과 JS 브리지를 통해서만 간접 확인 |
| `tests/known_failures/` | 비어 있는 디렉터리가 남아 있고 쓰이지 않음 |

## 결과 4, 문서가 약속하지만 시험이 없던 동작 (보강 전)

보강 뒤에는 모두 시험이 있습니다(`t_core`, `t_exceptions`, `t_requests`, `t_thread_tasks`, `t_runtime_tool`).

`docs/`는 아래를 사실로 서술합니다. 어떤 테스트도 직접 확인하지 않습니다. 문서의 사실 주장은 시험이 있어야 안전합니다. 실제로 문서를 쓰는 동안 스텁의 `Cef::run` 선언이 구현과 달랐던 것을 발견했습니다.

- `Cef::run($tick)`: `$tick`이 `false`를 반환하면 멈춤, `Cef::quit()`, `quitWhenLastBrowserCloses`(예제를 통해서만 실행됨).
- 핸들러가 던진 예외는 CEF로 들어가지 않고 보류되었다가 `run()` 또는 `doMessageLoopWork()`가 반환할 때 던져지며, 그 사이의 콜백은 PHP를 실행하지 않음. 핸들러 안의 `exit()`도 같음.
- `Cef::initialize()`를 두 번 부르면 오류, 초기화 뒤 `addSwitch()`와 `registerCustomScheme()`는 오류, `shutdown()` 뒤의 CEF 객체 사용은 `Error`.
- `Cef::run()`과 `Cef::wait()`가 폴링 모드에서 대기를 5ms로 제한함.
- 스크립트가 `shutdown()` 없이 끝나면 요청 종료 단계에서 CEF를 정리함.
- 동기 콜백 목록(`getResourceRequestHandler` 등)과 그 밖의 비동기 콜백(`getAuthCredentials`, `onResourceLoadComplete`)의 동작.
- `Cef::postTask`로 다른 스레드를 지정해도 PHP는 PHP 스레드에서 실행됨(`UI`, `IO`, 파일 스레드 3종은 시험됨, `PROCESS_LAUNCHER`와 `RENDERER`는 아님).
- 런타임 설치 도구의 체크섬 검증과 CEF 버전 검증.

## 측정의 한계

- 템플릿과 기반 클래스(`CefRefCount` 등)의 함수는 계측에 함께 잡히므로 손으로 쓴 파일의 "실행되지 않은 함수" 목록에는 잡음이 많습니다. 이 페이지의 표는 이를 걸러 낸 값입니다.
- 클라이언트 프록시는 CEF가 메서드를 불렀는지만 알고 PHP 훅이 있었는지는 모릅니다. `ResourceHandler`와 `SchemeHandlerFactory`는 프록시를 손으로 썼으므로(`CUSTOM_PROXY`) 영역 표에는 0으로 나오지만, 실제로는 `async.cc`의 줄 커버리지(57.8%)로 봅니다.
- 테스트가 종료하지 않아 실행기가 강제로 멈춘 프로세스의 횟수는 저장되지 않아 약간 낮게 잡힐 수 있습니다.
- `tests/t_examples.php`가 띄운 자식 프로세스의 횟수는 합산됩니다. 합산은 `.gcda`가 같은 빌드 디렉터리에 쌓이기 때문입니다.

## 관련 페이지

- [테스트 보강 계획](../reference/test-plan.md)
- [알려진 제약과 미검증 항목](../reference/known-constraints.md)
- [실험으로 확인한 사실](../reference/verified-findings.md)
- [생성기 커버리지 보고서](../reference/coverage-report.md)
