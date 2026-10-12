---
title: 테스트 보강 계획
type: reference
sources:
  - cefloom/tools/coverage.sh
  - cefloom/tools/coverage_report.py
  - cefloom/tests/run.sh
  - cefloom/tests/support.php
  - cefloom/ext/src/async.cc
  - cefloom/ext/src/core.cc
  - cefloom/bin/cefloom-runtime
  - cefloom/tools/package.sh
updated: 2026-10-12
---

# 테스트 보강 계획

[테스트 커버리지 분석](../analyses/test-coverage.md)이 찾은 공백을 메우는 계획입니다. 이 페이지는 계획이며, 아래 "진행 상황"에 실행한 결과를 적습니다. 규모는 S(반나절 안팎), M(1에서 2일), L(3일 이상)의 대략적 추정이고 실제 소요로 검증한 값이 아닙니다.

## 원칙

1. **위험도와 비용으로 순서를 정합니다.** 내가 직접 쓴 코드의 시험되지 않은 경로(B)가 가장 위험합니다. 저비용으로 효과가 큰 검사(A)를 먼저 하고, 많은 API를 한꺼번에 덮는 순수 라이브러리 시험(C2)을 일찍 합니다.
2. **생성된 API는 메서드마다 시험하지 않습니다.** 같은 틀로 생성된 코드이므로 **매핑 종류(인자와 반환의 종류)마다 대표 메서드**를 시험하고(C1), 그다음 사용자 시나리오 단위로 영역을 시험합니다.
3. **100%를 목표로 삼지 않습니다.** 환경이 없거나 부작용이 있는 것은 "시험 제외 목록"에 **사유와 함께** 적습니다(예: 실제 Cast 기기, 프로세스를 종료시키는 알려진 문제).
4. **시험의 완료 기준**: 같은 시험을 10회 반복해 실패와 멈춤이 없고, 끝난 뒤 남는 프로세스가 없으며, 외부 네트워크를 쓰지 않고(로컬 서버만), 입력이 필요한 것이 아니면 두 펌프 모드에서 모두 통과합니다. 한 번에 하나씩 순차 실행합니다.
5. **문서의 사실 주장은 시험이 있어야 합니다.** 문서에 쓴 동작은 대응하는 시험을 둡니다.
6. **회귀를 막습니다.** 커버리지 기준선을 저장소에 두고 줄어들면 실패시킵니다(A1).

## A. 기반과 저비용 검사

| ID | 내용 | 규모 | 비고 |
| --- | --- | --- | --- |
| A1 | 커버리지 기준선: 영역별 실행 메서드 수와 손으로 쓴 파일의 줄 커버리지를 `tests/coverage-baseline.json`에 저장하고 `coverage_report.py --check`가 후퇴를 잡음 | S | 현재값이 출발점(전체 20.7%) |
| A2 | 지원 함수 확충: 로컬 HTTP 서버 도우미(`php -S` 하위 프로세스, 빈 포트 선택, 쿠키, 리다이렉트, 기본 인증, 다운로드, 큰 본문, Range 응답을 하는 라우터 `tests/fixtures/server.php`), `xdotool` 도우미(`t_views_input`의 `xdo`와 `pump`를 옮김), 자식 프로세스 실행 도우미(`t_examples`의 것을 옮김), 임시 디렉터리 도우미 | S | C3 이후의 전제 |
| A3 | 스텁과 실제 확장의 일치 시험 `t_stub_consistency`: `php/cefloom.stub.php`를 토큰으로 파싱해 `Reflection`과 비교(클래스, 상속, `final`과 추상, 메서드, 정적 여부, 인자 이름과 형과 기본값, 반환형) | S | 문서를 쓰다 발견한 `Cef::run` 불일치를 잡았을 검사 |
| A4 | 생성 파일 최신성: `generate.py --check`를 시험으로(CEF 배포본이 없으면 건너뜀) | S | 지금도 통과 |
| A5 | 문서 시험: `docs/`의 링크, 앵커, 참조 경로를 점검하는 `tools/check-docs.py`와, 문서의 PHP 예제를 추출해 실행하는 `t_docs_snippets` | S | 이번에 임시로 한 것을 정식화 |
| A6 | 도구 시험: `lint.py`의 양성과 음성 예, `run.sh`의 정상, 실패, 시간 초과, 종료하지 않는 프로세스(가짜 시험 파일 사용) | S | |
| A7 | `tests/known_failures/` 정리: 쓰이지 않는 빈 디렉터리를 없애고, 알려진 문제는 **예상된 실패(xfail) 시험**으로 둔다는 규칙을 `run.sh`에 넣음 | S | K26을 현상 그대로 고정하는 데 씀(C11) |

## B. 손으로 쓴 코드의 미시험 경로 (위험도 높음)

| ID | 내용 | 규모 |
| --- | --- | --- |
| B1 | **`async.cc`의 리소스 경로**: `processRequest` 구형 경로(`open` 없이), `skip`과 Range 요청, `readResponse`, 취소(탐색 도중), `open`이 `[false, ...]`을 반환할 때 요청 실패(`StagedCallback::cancel`), `open`, `getResponseHeaders`, `read`가 예외를 던질 때, `redirectUrl`과 302, 404와 500 응답, 1MB가 넘는 본문(`read` 여러 번), 병렬 요청 50개, 팩토리가 `null`을 돌려줄 때(`DeferredResourceHandler`) | M |
| B2 | **나머지 비동기와 동기 콜백**: `onResourceLoadComplete`(상태, 바이트 수), `getAuthCredentials`(로컬 서버의 기본 인증, 계속과 취소), `onBeforeResourceLoad`(취소, 요청 수정, `CONTINUE_ASYNC`), 동기 콜백 각 1개 이상(`onResourceRedirect`, `onResourceResponse`, `getCookieAccessFilter`, `getResourceResponseFilter`, `onProtocolExecution`) | M |
| B3 | **`core.cc`**: `Cef::run`(`$tick`, `false`로 멈춤, `quit`, `quitWhenLastBrowserCloses`), 이중 `initialize`, 초기화 뒤 `addSwitch`와 `registerCustomScheme`, `shutdown` 이중 호출과 열린 브라우저나 창이 있을 때의 `shutdown`, `shutdown` 뒤 객체 사용 오류, 스크립트가 `shutdown` 없이 끝날 때(별도 프로세스), `createBrowserSync`, `cefVersion`과 런타임 디렉터리 함수, 외부 펌프의 `OnScheduleMessagePumpWork`와 `wait`의 조기 깨움, `Settings` 기본값 적용 결과(캐시 경로, 하위 프로세스 경로, 사용자 지정 경로 보존) | M |
| B4 | **예외와 종료 의미론**: 핸들러의 예외가 보류되었다가 `run`과 `doMessageLoopWork` 반환 때 던져지는지, 그 사이 다른 콜백이 PHP를 실행하지 않는지, 핸들러 안의 `exit()`, 반환형이 틀린 값, 라이브러리 메서드 호출 중의 보류 예외 | M |
| B5 | **`cl_runtime`**: `Buffer`의 `read`, `isValid`, `isWritable`와 범위 밖 접근, 시간 변환(`DateTimeImmutable`, 정수, 실수, `null`), 문자열 맵 입출력(`Response`의 헤더 맵), `ProxyBase::find`(`BrowserHost::getClient`가 같은 PHP 객체를 돌려주는지), 수명(순환 참조, 해제 순서, 브라우저를 반복 생성하고 닫은 뒤 메모리, K10) | S |
| B6 | **런타임 설치 도구 `t_runtime_tool`**: 올바른 CEF 버전의 매니페스트가 든 가짜 런타임을 압축해 설치 성공, 체크섬 불일치, CEF 버전 불일치, `--sha256` 직접 지정, 사이드카 없음, 이미 설치됨과 `--force`, `--dir`, `status`와 `path`의 출력과 종료 코드, `phar` 없음 안내, 깨진 압축 파일, `file://` | M |
| B7 | **브리지**: 브라우저 여러 개, iframe에서의 `cefloom.call`, 1MB 인자와 결과, 호출 도중 브라우저 닫기, `OnQuery` 실패 경로(PHP 종료 중), 렌더 프로세스 종료 | S |
| B8 | **패키징 스크립트 `t_package`**: 가짜 dist로 `tools/package.sh` 실행, 산출물 이름을 PIE 규칙과 비교, 압축 파일 내용(`cefloom.so`가 최상위, 매니페스트의 파일 목록과 일치, `PharData`로 풀림), 거부 조건(링크한 모듈, ZTS와 디버그 PHP는 가짜 `php`로 흉내), 체크섬 | M |
| B9 | **생성기 단위 시험**(`python3 -m unittest`): 헤더 조각의 파싱, 매핑 종류별(`Prim`, `Str`, `Enum`, `Struct`, `Vector`, `StrMap`, `Time`, `Buffer`, `ClientRef`, `LibRef`, `Planes`, `Ignored`) 기대 계획, 작은 가짜 클래스의 출력 골든 파일(C++과 스텁), `scope` 규칙(`NATIVE_ONLY`, `CUSTOM_PROXY`, `ASYNC_METHODS`), 지원하지 않는 형이 "생성하지 못함" 사유로 기록되는지 | L |
| B10 | **빌드 스크립트**: 모드 전환(지연 로딩, 커버리지)과 컴파일 실패 시 중단, 다른 파일 시스템으로의 런타임 복사(`locales/locales` 회귀) | M |

## C. 생성된 API의 영역별 시험

| ID | 영역 | 내용 | 환경 | 규모 |
| --- | --- | --- | --- | --- |
| C1 | 매핑 종류별 계약 | 인자와 반환의 종류마다 메서드를 골라 시험: 기본형 입출력, 문자열, 열거형, 구조체 입출력, 벡터, 문자열 맵, 시간, 버퍼, 클라이언트 객체 인자, 라이브러리 객체 반환, 출력 인자의 배열 반환, 잘못된 형의 `TypeError` | 브라우저 일부 | M |
| C2 | 순수 라이브러리(브라우저 불필요) | 전역 함수 33개 중 순수한 것(Base64, URI, URL 파싱과 조합과 해석, MIME, 경로, 임시 디렉터리, ZIP, 교차 출처 허용 목록, 크래시 키), `CommandLine` 15개, 스트림(`StreamReader`, `StreamWriter`, `ReadHandler`, `WriteHandler`), `ZipReader`, `XmlReader`, `WaitableEvent`, `Thread`, `TaskRunner`, `PostData`, `Image`, `Value` 나머지 | 없음 | S |
| C3 | 네트워크와 쿠키 | `CookieManager`(방문, 설정, 삭제, 콜백), `RequestContext`, `PreferenceManager`와 옵저버, `URLRequest`와 `URLRequestClient`, 다운로드(`DownloadHandler`, `BeforeDownloadCallback`, `DownloadItem`), 인증, `ResolveCallback`, `Server`와 `ServerHandler` | 로컬 HTTP 서버(A2) | L |
| C4 | 입력과 상호작용 핸들러 | `KeyboardHandler`, `FocusHandler`, `ContextMenuHandler`와 `ContextMenuParams`와 콜백, JS 대화상자(`alert`, `confirm`, `prompt`, `onbeforeunload`), 파일 대화상자, `FindHandler`, `DragHandler`와 `DragData`, `PermissionHandler`, `BrowserHost`의 입력 전송 메서드 | `xdotool` | L |
| C5 | 브라우저와 프레임 | `BrowserHost` 66개 중 줌, 음소거, 개발자 도구와 `DevToolsMessageObserver`, 찾기, 전체 화면, 렌더 프로세스 응답성, `Frame`의 편집 명령과 소스 보기, `NavigationEntry`, 팝업(`onBeforePopup`, `target=_blank`는 사용자 동작이 필요해 `xdotool`), `DoClose`, `RequestHandler`의 `onBeforeBrowse`와 `onOpenURLFromTab`과 렌더 프로세스 종료 | 일부 `xdotool` | M |
| C6 | 렌더링 | 오프스크린 모드(`windowlessRenderingEnabled`, `RenderHandler`의 `getViewRect`와 `onPaint` 픽셀 검증(K14), 입력 전달), `AudioHandler`, 접근성 핸들러 | 가상 화면 | M |
| C7 | Views 나머지 | `Textfield`(입력과 델리게이트), `ScrollView`, `MenuButton`과 Views 팝업 메뉴, `OverlayController`, `Display`, `FillLayout`, `PanelDelegate`, `BrowserViewDelegate`(`onBrowserCreated`, 팝업 델리게이트), `WindowDelegate`의 전체 화면과 모달 대화상자와 크기 제한 | `xdotool` | M |
| C8 | 인쇄 | `PrintSettings`, `PrintHandler`와 대화상자와 작업 콜백을 가짜로 구현해 `print()`가 부르는 순서 확인(K27) | 가상 화면 | S |
| C9 | 인증서와 SSL | `openssl`로 자체 서명 인증서를 만든 로컬 HTTPS 서버, `onCertificateError`, `SSLInfo`, `SSLStatus`, `X509Certificate`와 `X509CertPrincipal`, 클라이언트 인증서 선택 | `openssl` | M |
| C10 | 앱과 기타 | `Cef::initialize($settings, $app)`로 `CefApp`과 `BrowserProcessHandler`의 콜백(`onBeforeCommandLineProcessing`, `onContextInitialized` 등), `ResourceBundle`과 핸들러, `ComponentUpdater`, `TaskManager`, 추적(`beginTracing`, `endTracing`), 크래시 보고 | 없음 | S |
| C11 | Cast 옵저버 | K26이 풀릴 때까지 **예상된 실패 시험**으로 현상(옵저버를 등록하면 `shutdown`이 `SIGTRAP`)을 고정하고, 풀리면 정상 시험으로 바꿈 | 가상 화면 | S |

## 진행 상황 (2026-10-12)

| ID | 상태 | 시험 | 찾아서 고친 결함 또는 기록한 제약 |
| --- | --- | --- | --- |
| A2, A3, A4, A5, A6, A7 | 완료 | `t_fixtures`, `t_stub_consistency`, `t_generated_fresh`, `t_docs`, `t_runner`, `t_lint` | 스텁과 확장의 불일치 여럿(생성기 수정), `check-docs.py` |
| A1 | 완료 | `tools/coverage.sh`, `tests/coverage-baseline.json`, `coverage_report.py --check` | 전체 20.7%에서 65.8%로. 마일스톤 평가는 [분석](../analyses/test-coverage.md)의 "보강 뒤 결과" |
| B1 | 완료 | `t_resources` | |
| B2 | 완료 | `t_requests`, `t_redirect_change`(기대 실패) | 로그인 창 때문에 `getAuthCredentials`가 불리지 않던 것, 선택적 버퍼가 무효한 `Buffer`로 오던 것, [K30](known-constraints.md) |
| B3, B4 | 완료 | `t_core`, `t_exceptions` | 시작 전 `createBrowser`와 종료 뒤 `initialize` 충돌 |
| B5 | 완료 | `t_runtime` | 시간 인자 변환, `getClient()`가 `null`이던 것, 종료 때 힙 손상 |
| B6 | 완료 | `t_runtime_tool` | |
| B7 | 완료 | `t_bridge_more` | |
| B8 | 완료 | `t_package` | |
| B9 | 완료 | `tests/gen/test_generator.py`(`t_generator`) | |
| B10 | 완료 | `t_build` | |
| C1 | 완료 | `t_mapping` | |
| C2 | 완료 | `t_lib_pure` | 파일 함수와 `WaitableEvent` 대기가 UI 스레드에서 거부됨([K31](known-constraints.md), [K32](known-constraints.md)) |
| C3 | 완료 | `t_urlrequest`, `t_cookies`, `t_context`, `t_download`, `t_server` | 널 콜백에서 CEF가 죽던 것([K33](known-constraints.md)), [K34](known-constraints.md), [K35](known-constraints.md) |
| C4 | 완료 | `t_dialogs`, `t_input` | |
| C5 | 완료 | `t_browser_host` | [K36](known-constraints.md) |
| C6 | 완료 | `t_osr` | K14 해결, [K37](known-constraints.md)(오디오) |
| C7 | 완료 | `t_views_more` | 창 관리자가 없어 `maximize()`는 시험 못함 |
| C8 | 완료 | `t_print` | [K39](known-constraints.md)(`print()`는 `PrintHandler`를 부르지 않음) |
| C9 | 완료 | `t_ssl` | |
| C10 | 완료 | `t_app_misc` | [K38](known-constraints.md) |
| C11 | 완료 | `t_cast_observer`(기대 실패) | K26 |
| 추가 | 완료 | `t_streams_more`, `t_handlers_more`, `t_callbacks_more` | 마일스톤 영역의 빈 곳을 메우려고 더함. [K40](known-constraints.md) |
| 추가 2 | 완료 | `t_lib_more`, `t_views_widgets`, `t_host_more` | 전체 84.0%. [K41](known-constraints.md) |

시험 제외 목록(D)에 더해 환경 때문에 시험하지 못한 것: 창 관리자가 필요한 최대화와 최소화, 소리 장치가 필요한 `AudioHandler`, 진짜 프린터와 인쇄 미리보기, 실제 Cast 기기, 클라이언트 인증서 선택(목록이 비어 있는 경우만 시험).

## D. 현재 환경에서 시험할 수 없는 것

이 항목은 시험 제외 목록의 출발점입니다. 각각 필요한 환경과 대안을 적습니다.

| 항목 | 필요한 것 | 대안 |
| --- | --- | --- |
| PHP 8.3과 8.4, ZTS, arm64, 비Ubuntu glibc 배포판, 컨테이너 | 해당 환경의 빌드와 실행 | 공개 저장소이므로 GitHub Actions 매트릭스(CEF 런타임 내려받기와 `xvfb`가 필요하므로 CI 설계가 따로 필요) |
| Wayland, 데스크톱 환경의 창 관리자 | 실제 세션 | 수동 점검 절차를 문서에 둠 |
| 실제 Cast 기기와의 세션, 실제 프린터와 인쇄 대화상자, 오디오 출력 | 하드웨어 | 가짜 구현으로 호출 순서만 시험 |
| Windows와 macOS | 해당 플랫폼 | 미착수([로드맵](roadmap.md)) |
| PHP-FPM | 지원하지 않기로 함(D7) | 해당 없음 |

## 마일스톤과 목표

| 단계 | 포함 | 완료 기준(측정은 `tools/coverage.sh`) |
| --- | --- | --- |
| M1 | A1에서 A7, B1에서 B10 | 손으로 쓴 파일(`async.cc`, `core.cc`, `bridge.cc`, `cl_runtime.cc`)의 줄 커버리지 85% 이상, [분석](../analyses/test-coverage.md)의 "문서가 약속하지만 시험이 없는 동작"이 모두 시험됨, 스텁 일치 시험과 문서 시험 통과, 커버리지 기준선이 저장소에 있음 |
| M2 | C1, C2, C3, C5 | 값과 데이터 90% 이상, 브라우저와 프레임 60% 이상, 기본 핸들러 80% 이상, 네트워크와 쿠키 55% 이상, 스트림과 XML과 ZIP 70% 이상, 전역 함수 80% 이상 |
| M3 | C4, C6에서 C11 | 전체 메서드 커버리지 45% 이상(2026-10-12에는 20.7%), 실행 0인 클래스는 시험 제외 목록에 사유가 있는 것뿐 |

순서의 이유: B는 사용자에게 곧바로 영향을 주고 내가 쓴 코드라 위험이 가장 큽니다. A는 비용이 낮고 B와 C의 도구가 됩니다(특히 A2). C2는 비용이 낮은데 메서드 수가 많습니다. C3은 실제 사용 빈도가 높고 로컬 서버만 있으면 됩니다.

## 위험과 제약

- 테스트 수가 약 3배가 되면 전체 실행 시간이 길어집니다(지금은 약 35초). 이름의 접두사로 묶음을 나누어 일부만 실행하는 방법(`run.sh`의 이름 인자는 이미 있음)을 유지합니다.
- 입력과 대화상자는 `xdotool`과 타이밍에 의존해 불안정하기 쉽습니다. 10회 반복 기준(원칙 4)을 지킵니다.
- 알려진 문제(K26 미디어 라우터, 드물게 종료하지 않는 프로세스)는 시험을 불안정하게 만들 수 있으므로 격리하거나 예상된 실패로 둡니다.
- 같은 CEF 프로세스는 한 번만 시작할 수 있어서 시험마다 별도 PHP 프로세스를 씁니다. 시험이 많아질수록 시작 비용이 커집니다.

## 관련 페이지

- [테스트 커버리지 분석](../analyses/test-coverage.md)
- [알려진 제약과 미검증 항목](known-constraints.md)
- [로드맵](roadmap.md)
- [패키징과 릴리스 절차](../procedures/package-and-release.md)
