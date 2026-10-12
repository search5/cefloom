# 작업 기록

시간순으로 덧붙이는 기록입니다. 형식은 [SCHEMA.md](SCHEMA.md)를 따릅니다. 기존 항목은 고치지 않습니다.

## [2026-10-12] ingest | 초기 위키 작성

- 작업 영역(`cef_origin`, `cefpython`, `java-cef`, `cefweaver_clone`)을 바탕으로 PHP 바인딩을 만들 수 있는지 검토한 대화를 위키로 정리했습니다. 구조와 형식은 `cefweaver`의 위키(`cefweaver_clone/cefweaver/llm-wiki/`)를 따랐고, `sources`를 작업 영역 기준 경로로 해석하고 웹 URL을 허용하도록 규칙을 바꿨습니다.
- 대화에서 나온 내용:
  - 가능성 검토와 처음 제안(C++ 확장과 생성기), 이후 배포 목표를 고려한 FFI 제안과 기간 추정, 사용자의 결정(C 확장으로 가되 FFI로 사전 검증만 수행).
  - 웹 조사: PHP용 CEF 바인딩의 수요, NativePHP Desktop.
- 사전 검증을 직접 수행했습니다. PHP 8.5.4를 설치하고(`apt`), C API 헤더에서 FFI 선언을 만들고, 참조 카운트와 펌프 예약을 담당하는 네이티브 shim으로 PHP 프로세스에서 CEF를 구동했습니다. 결과는 [실험으로 확인한 사실](pages/reference/verified-findings.md)에 있습니다. 스크립트는 임시 디렉터리에서 `cefloom/experiments/ffi-precheck/`로 옮기고 처음부터 재현되는지 확인했습니다.
- 사전 검증의 핵심 결과: 메시지 루프를 PHP 메인 스레드에서 구동하면 핸들러 콜백이 메인 스레드에서 실행되지만, 참조 카운트와 `on_schedule_message_pump_work`는 CEF 내부 스레드에서 호출되어 네이티브 구현이 필요합니다.
- 대화 중 정정한 판단: FFI를 불안정하다고 본 처음 판단을 정정했고(메인 스레드 콜백 조건), 이후 순수 FFI도 네이티브 shim이 필요함을 확인했습니다([구현 방식 비교](pages/analyses/approach-comparison.md)).
- 만든 페이지: concepts 8, components 2, procedures 2, reference 6, summaries 1, analyses 3(총 22개). 목록은 [index.md](index.md)에 있습니다.
- 확인하지 못한 항목은 [known-constraints](pages/reference/known-constraints.md)에 모았습니다(K1부터 K23).
- 변경한 시스템 상태: `php8.5-cli`, `php8.5-dev` 패키지 설치. 제거 방법은 [환경 준비](pages/procedures/setup-php-environment.md)에 있습니다.
- 점검 도구 `lint.py`를 추가했습니다.

## [2026-10-12] schema | 버전 관리 절에 커밋 규칙 추가

- `cefloom`을 git 저장소로 초기화한 상태를 [SCHEMA.md](SCHEMA.md)의 "10. 버전 관리" 절에 반영했습니다.
- 커밋 메시지와 PR 설명에 공동 저작자 표시와 Claude 세션 링크를 넣지 않는 규칙을 같은 절에 추가했습니다. 이 규칙을 임시로 기록했던 `CLAUDE.md`는 삭제했습니다.

## [2026-10-12] schema | 테스트 동시 실행 금지 규칙 추가

- 테스트는 한 번에 하나만 실행한다는 사람의 지시를 [주의 사항](pages/reference/cautions.md) 25번에 기록했습니다. 멈춤의 원인이 메모리 소비라는 것은 사람의 추정이며 아직 확인하지 않았습니다.
- 이전 실행에서 5시간 넘게 남아 있던 `php` 테스트 프로세스 4개(`t_scheme`, `t_browser_nav`, `t_thread_tasks` 2개)를 종료했습니다. 이 시점에 시스템 메모리는 30Gi 중 25Gi가 가용 상태였습니다.

## [2026-10-12] ingest | 테스트 멈춤 원인 조사

- 전체 테스트가 멈추는 원인을 조사해 [실험으로 확인한 사실](pages/reference/verified-findings.md)에 F13과 F14를 추가했습니다. F13은 브라우저가 없으면 `FILE_BACKGROUND` 작업이 실행되지 않는다는 것이고, F14는 IO 스레드의 핸들러가 UI 스레드를 기다리다 교착이 일어난다는 것입니다.
- `tests/run.sh`를 고쳤습니다. 테스트마다 별도 세션으로 실행하고, 시간 초과나 종료하지 않는 프로세스를 세션 단위로 정리합니다.

## [2026-10-12] ingest | 교착의 대기 지점 확인

- 같은 빌드 ID의 심볼 포함 `libcef.so`로 멈춘 메인 스레드의 스택을 풀어 [F14](pages/reference/verified-findings.md)를 갱신했습니다. UI 스레드는 합성기가 GPU 프로세스에 보낸 동기 mojo 호출의 응답을 기다리고, IO 스레드는 PHP 호출을 기다리는 교착입니다.

## [2026-10-12] ingest | IO 스레드 핸들러의 비동기 처리

- IO 스레드에서 불리는 핸들러 중 비동기로 처리할 수 있는 것을 `ext/src/async.cc`로 옮겼습니다. 결정과 범위는 [D12](pages/reference/design-decisions.md)에, 검증 결과는 [F14](pages/reference/verified-findings.md)에 기록했습니다. 즉시 반환값이 필요한 콜백은 사용자 결정에 따라 그대로 PHP를 동기로 호출합니다.
- [주의 사항](pages/reference/cautions.md) 4번과 5번을 갱신했습니다.

## [2026-10-12] ingest | 교착 완화와 stack smashing 원인

- [F15](pages/reference/verified-findings.md)를 추가했습니다. 서브프로세스의 스택 보호 때문에 종료할 때 `stack smashing detected`가 나왔고, `-fno-stack-protector`로 빌드해 없앴습니다.
- 즉시 반환값이 필요한 콜백의 교착을 풀어 주는 타임아웃을 넣고 F14, [D12](pages/reference/design-decisions.md), [주의 사항](pages/reference/cautions.md) 5번에 반영했습니다. `t_sync_timeout`을 추가했습니다.

## [2026-10-12] lint | 점검 도구 갱신

- `lint.py`에 검사 두 가지를 추가했습니다. index.md가 다른 페이지에 대해 적은 개수(주의 사항의 규칙 수, D와 F와 K의 가장 큰 번호)가 실제 페이지와 같은지, log.md 항목의 날짜가 거꾸로 가지 않는지입니다. 일부러 틀린 값을 넣은 사본에서 다섯 가지 불일치를 모두 잡는 것을 확인했습니다.
- 생성 페이지 [생성기 커버리지 보고서](pages/reference/coverage-report.md)를 index.md에 등재하고 [바인딩 생성기 계획](pages/concepts/binding-generator-plan.md)에서 링크했습니다. 이 페이지가 등재되지 않아 나던 오류와 고아 경고가 없어졌습니다.
- [SCHEMA.md](SCHEMA.md)의 `generated` 설명과 점검 항목 목록을 현재 상태에 맞게 고쳤습니다.

## [2026-10-12] ingest | 로드맵을 구현 상태에 맞춤

- [로드맵](pages/reference/roadmap.md)의 1단계에서 3단계를 구현됨으로 고치고 "다음에 할 일"을 현재 상태에 맞게 다시 썼습니다. 근거는 코드, 커버리지 보고서(1262/1284), 통과한 테스트 11개입니다.
- JS 브리지를 검증하는 `t_bridge`를 추가했습니다. `OnQuery`가 PHP를 실행하지 못할 때 JS의 약속이 영원히 대기하지 않도록 실패로 응답하게 고쳤습니다.

## [2026-10-12] ingest | PIE와 Composer 조사

- PIE 1.6.x 문서를 읽고 [배포 전략](pages/concepts/distribution-strategy.md)에 정리했습니다. 확장은 PIE로, PHP 쪽 보조는 `composer require`로, CEF 런타임은 별도로 내려받는 구성을 제안했습니다. [K17](pages/reference/known-constraints.md)을 문서 조사까지 한 상태로 고쳤습니다.

## [2026-10-12] ingest | libcef.so 지연 로딩 실험

- `libcef.so`를 필요할 때 불러오는 빌드를 만들고 [F16](pages/reference/verified-findings.md)에 결과를 기록했습니다. [배포 전략](pages/concepts/distribution-strategy.md)의 미해결 과제를 갱신했습니다.
- `tools/build.sh`의 런타임 복사 결함과 빌드 모드 전환 때 재빌드하지 않는 문제를 고쳤습니다.

## [2026-10-12] ingest | 지연 로딩을 기본 빌드로

- `tools/build.sh`의 기본을 `libcef.so`를 필요할 때 불러오는 빌드로 바꾸었습니다([D13](pages/reference/design-decisions.md)). 링크하는 빌드는 `CEFLOOM_LAZY_LIBCEF=0`입니다.

## [2026-10-12] ingest | 패키징과 런타임 설치 도구

- PIE용 `composer.json`, `tools/package.sh`, 런타임 설치 도구 `bin/cefloom-runtime`, `README.md`를 만들고 [패키징과 릴리스 절차](pages/procedures/package-and-release.md)를 추가했습니다. 결과는 [F17](pages/reference/verified-findings.md)에, 확인하지 못한 항목은 [K24와 K25](pages/reference/known-constraints.md)에 기록했습니다.
- 확장이 런타임을 찾는 위치에 사용자 데이터 디렉터리를 추가했고, 빌드가 컴파일 실패를 숨기지 않도록 고쳤습니다.

## [2026-10-12] ingest | Views, PDF, Cast 점검

- Views, PDF 출력, Cast가 포함되어 있고 동작하는지 점검해 [F18](pages/reference/verified-findings.md)에 기록했습니다. 시험 `t_views`, `t_pdf`, `t_cast`를 추가했습니다.
- 미디어 라우터 관리자를 만든 뒤 `Cef::shutdown()`이 중단되는 문제를 [K26](pages/reference/known-constraints.md)에, 시험하지 못한 범위를 K27에 기록했습니다. 재현 스크립트는 `experiments/media-router-shutdown/`에 있습니다.

## [2026-10-12] ingest | Views, PDF, Cast 예제

- `examples/`에 `views.php`(툴바가 있는 Views 창), `print-pdf.php`, `cast.php`를 추가하고 `README.md`에 목록을 넣었습니다. `tests/t_examples.php`가 `hello.php`를 포함한 예제 네 개를 실행해 확인합니다. `cast.php --discover`는 알려진 종료 문제([K26](pages/reference/known-constraints.md))를 예제 안에 적었습니다.

## [2026-10-12] ingest | Views 입력과 메시지 펌프

- Views와 CEF 소유 창이 마우스와 키보드 입력을 받지 못하는 원인을 찾아 [F19](pages/reference/verified-findings.md)에 기록하고, 확장이 `external_message_pump`를 강제로 켜지 않도록 고쳤습니다([D14](pages/reference/design-decisions.md)). [K12와 K27](pages/reference/known-constraints.md)을 갱신했습니다.
- `t_views_input`을 추가하고, 테스트를 외부 펌프 모드로도 실행하는 `CEFLOOM_TEST_EXTERNAL_PUMP`를 추가했습니다.

## [2026-10-12] ingest | 사용자 문서 docs/

- `docs/`에 PIE와 런타임 설치 도구에 맞춘 사용자 문서 13개를 영어로 작성했습니다. 문서를 쓰다가 스텁의 `Cef::run` 선언이 구현과 달랐던 것(두 번째 인자 `quitWhenLastBrowserCloses`와 `$tick`의 `false` 반환)을 찾아 고쳤습니다. 설명은 [패키징과 릴리스 절차](pages/procedures/package-and-release.md)에 있습니다.
- Views 입력을 창 관리자 `twm`에서도 확인해 [F19](pages/reference/verified-findings.md)와 K12, K27을 갱신했습니다.

## [2026-10-12] ingest | 저장소 게시

- 저장소를 `https://github.com/search5/cefloom`(공개, 기본 브랜치 `main`)으로 게시하고 지금까지의 커밋을 푸시했습니다. 게시 전에 비밀 정보, 개인 경로, 큰 파일을 점검했습니다(비밀 정보 없음, 추적 파일 248개 2.9MB). [패키징과 릴리스 절차](pages/procedures/package-and-release.md)를 갱신했습니다. 릴리스, Packagist 등록, 라이선스는 아직입니다.

## [2026-10-12] ingest | 패키지 이름을 search5/cefloom으로

- `composer.json`의 패키지 이름을 임시 값 `cefloom/cefloom`에서 `search5/cefloom`으로 바꾸고, `README.md`와 `docs/`의 `pie install`과 `pie uninstall` 명령, 런타임 내려받기 주소, 상태 안내를 맞추었습니다. PIE가 새 이름을 인식하는 것을 다시 확인했습니다([F17](pages/reference/verified-findings.md)).

## [2026-10-12] ingest | 첫 릴리스 전 체크리스트

- 라이선스 파일 추가, 태그와 릴리스 자산 업로드, Packagist 등록, 끝까지 시험을 [패키징과 릴리스 절차](pages/procedures/package-and-release.md)에 체크리스트로 모았습니다. 라이선스 미정을 [K28](pages/reference/known-constraints.md)로 기록하고, 로드맵의 낡은 서술(이미 정한 공급자 이름과 저장소 위치를 미정으로 적은 부분)을 고쳤습니다.

## [2026-10-12] ingest | 테스트 공백 측정과 보강 계획

- 커버리지 빌드(`CEFLOOM_COVERAGE=1`)와 `tools/coverage.sh`, `tools/coverage_report.py`를 만들어 전체 테스트가 실행하는 메서드를 측정하고, 결과를 [테스트 커버리지 분석](pages/analyses/test-coverage.md)에, 이를 바탕으로 한 계획을 [테스트 보강 계획](pages/reference/test-plan.md)에 기록했습니다. 미시험 구현은 [K29](pages/reference/known-constraints.md)로, 로드맵에는 항목을 추가했습니다.

## [2026-10-12] ingest | B2와 B5 시험, 시험이 찾은 결함 다섯 가지

- `t_requests`(요청 콜백 21항목), `t_redirect_change`(기대 실패, [K30](pages/reference/known-constraints.md)), `t_runtime`(시간, 문자열 맵, Buffer, 객체 대응, 반복 생성과 종료 29항목)을 추가했습니다.
- 시험이 찾아 고친 결함: (1) Chrome 런타임의 자체 로그인 창 때문에 `getAuthCredentials`가 호출되지 않던 것(`disable-chrome-login-prompt`를 기본으로 설정), (2) 선택적 버퍼 인자(`ResponseFilter::filter`의 `dataIn`)가 무효한 Buffer로 오던 것(이제 `null`, 스텁은 `?Buffer`), (3) 구조체 생성자의 시간 인자가 변환되지 않던 것(`DateTimeImmutable`로 정규화하고 잘못된 타입은 `TypeError`), (4) `BrowserHost::getClient()`가 `null`이던 것(CEF가 들고 있는 `NativeClient`를 사용자 클라이언트에 대응), (5) 종료 때 라이브러리 객체를 해제하다 자기 자신이 해제되어 힙이 손상되던 것(`release_all_lib_objects`).

## [2026-10-12] ingest | B6에서 B10, C2 시험과 UI 스레드에서 거부되는 호출

- `t_runtime_tool`(30), `t_bridge_more`(7), `t_package`(25), `t_generator`(생성기 단위 시험 30개), `t_build`(23), `t_lib_pure`(107)를 추가했습니다. 생성기 시험은 가짜 헤더 묶음(`tests/gen/mini`)과 실제 헤더를 함께 씁니다.
- C2에서 CEF의 파일 함수와 `WaitableEvent` 대기가 UI 스레드에서 `false`만 돌려주는 것을 찾아, 생성된 코드가 별도 스레드에서 실행하도록 했습니다([K31](pages/reference/known-constraints.md)). 안 멈추는 스레드의 해제 문제와 `XmlReader` 오버로드는 [K32](pages/reference/known-constraints.md)에 적었습니다.

## [2026-10-12] ingest | C3 일부: URLRequest, 쿠키, 요청 컨텍스트와 환경설정

- `t_urlrequest`(30), `t_cookies`(30), `t_context`(29)를 추가했습니다. `URLRequest`는 `ALLOW_STORED_CREDENTIALS` 없이는 인증도 쿠키도 다루지 않고, `REPORT_UPLOAD_PROGRESS` 없이는 업로드 진행을 알리지 않습니다(시험에서 확인).
- `RequestContext::clearHttpCache(null)` 등이 CEF 안에서 프로세스를 죽이는 것을 찾아, `null`이면 아무것도 하지 않는 콜백을 넘기도록 고쳤습니다([K33](pages/reference/known-constraints.md)). 관찰자 등록의 수명은 [K34](pages/reference/known-constraints.md)에 적었습니다.

## [2026-10-12] ingest | C3 마무리: 다운로드와 서버

- `t_download`(18), `t_server`(22)를 추가했습니다. 다운로드는 저장, 거부(`canDownload`가 `false`), 진행 중 취소, 이어지는 다운로드를 확인했고, 서버는 별도 PHP 프로세스를 클라이언트로 써서 200/404/500/사용자 응답과 WebSocket 왕복을 확인했습니다.
- `Server::isRunning()`이 PHP 스레드에서 항상 `false`인 것을 [K35](pages/reference/known-constraints.md)에 적었습니다.

## [2026-10-12] ingest | C5: 브라우저 호스트 시험

- `t_browser_host`(40)를 추가했습니다: 줌, 음소거, 페이지 안 찾기, DevTools 프로토콜(`Runtime.evaluate` 결과와 이벤트), 탐색 기록과 `NavigationEntry`, 프레임의 텍스트와 소스, `onBeforeBrowse`로 탐색 막기, 클릭한 `target=_blank` 링크의 `onBeforePopup`(`xdotool`), 닫기.
- Chrome 런타임의 찾기 결과는 활성 일치 순번이 0이고, `doClose()`는 불리지 않습니다([K36](pages/reference/known-constraints.md)). 브라우저 창은 (10,10)에 있고 위쪽 약 100픽셀이 도구 모음이라 클릭은 그 안쪽에서 해야 합니다.

## [2026-10-12] ingest | C4와 C6: 대화상자, 입력, 오프스크린 렌더링

- `t_dialogs`(14), `t_input`(27), `t_osr`(17)를 추가했습니다. JS 대화상자, 권한 요청(수락한 권한은 출처별로 기억되므로 거부는 다른 출처로 시험), 파일 대화상자, 키보드와 포커스, 컨텍스트 메뉴와 `MenuModel`, `DragData`, 오프스크린 렌더링의 픽셀과 마우스, 휠, 키 입력, 크기 변경, 선택, 접근성 트리를 확인했습니다.
- K14(픽셀 내용)가 해결되었습니다. `AudioHandler`는 소리 장치가 없는 환경에서 불리지 않아 [K37](pages/reference/known-constraints.md)에 적었습니다. 키 입력을 페이지에서 막으려면 `onKeyEvent`가 아니라 `onPreKeyEvent`에서 처리해야 합니다.

## [2026-10-12] ingest | C10: 앱과 기타

- `t_app_misc`(22)를 추가했습니다: `Cef::initialize($settings, $app)`의 `App`과 `BrowserProcessHandler`(명령줄 스위치 추가, 컨텍스트 초기화, 렌더러 실행 직전 콜백), `ResourceBundle`, `ComponentUpdater`, `TaskManager`, 추적(`beginTracing`, `endTracing`의 JSON 파일).
- 팩에 없는 id를 `ResourceBundle`에 주면 프로세스가 끝나는 것을 [K38](pages/reference/known-constraints.md)에 적었습니다.

## [2026-10-12] ingest | C9: 인증서와 SSL

- `t_ssl`(24)과 `tests/fixtures/https_server.php`, 지원 함수 `start_https_server()`를 추가했습니다. `openssl`로 자체 서명 인증서를 만들고 PHP 자식 프로세스가 HTTPS로 응답합니다. `onCertificateError`(수락, 거부, 처리하지 않음), `SSLInfo`, `X509Certificate`(주체, 발급자, 조직, 국가, 유효 기간, DER와 PEM), 로드된 페이지의 `SSLStatus`, 클라이언트 인증서 요청의 `onSelectClientCertificate`, 그리고 `URLRequest`가 인증서 오류로 실패하는 것을 확인했습니다.
- 한 출처에서 내린 결정은 기억되므로(수락한 뒤 같은 출처에서 거부 시험은 핸들러가 다시 불리지 않음) 사례마다 출처를 달리합니다(`127.0.0.1`, `localhost`, `localhost.`).

## [2026-10-12] ingest | C8: 인쇄

- `t_print`(7)를 추가했습니다. `PrintSettings`는 일반 객체로 모든 설정과 쪽 범위를 확인했습니다. `print()`는 Chrome 런타임에서 `PrintHandler`를 부르지 않는다는 것을 확인해 [K39](pages/reference/known-constraints.md)에 적고 `docs/printing.md`를 고쳤습니다. K27은 인쇄 부분의 결론이 났습니다.

## [2026-10-12] ingest | 테스트 보강 완료와 커버리지 재측정

- 계획 A, B, C를 모두 실행했습니다. 테스트는 17개에서 53개로 늘었고 폴링 모드와 외부 펌프 모드에서 모두 통과합니다(예상된 실패 2개: K26, K30). `tools/coverage.sh`로 다시 쟀더니 전체 메서드 실행 비율이 20.7%에서 65.8%가 되었고, `async.cc` 87.8%, `core.cc` 94.2%, `cl_runtime.cc` 85.5%, `bridge.cc` 84.0%입니다. [분석](pages/analyses/test-coverage.md)에 보강 전과 뒤를 비교했습니다.
- `tests/coverage-baseline.json`과 `coverage_report.py --check`로 후퇴를 잡습니다. `docs/development.md`에 시험 구성과 쓰는 법을 적었습니다.
- 시험이 찾은 결함: 로그인 창 때문에 `getAuthCredentials`가 불리지 않던 것, 선택적 버퍼 인자, 구조체 생성자의 시간 변환, `getClient()`가 `null`이던 것, 종료 때 힙 손상, 널 콜백에서 CEF가 죽던 것, UI 스레드에서 거부되는 파일 함수와 대기. 기록한 제약은 K30에서 K40입니다.

## [2026-10-12] ingest | 두 번째 커버리지 보강

- `t_lib_more`(37), `t_views_widgets`(53), `t_host_more`(18)를 추가해 전체 메서드 실행 비율이 65.8%에서 84.0%가 되었습니다(요청한 상한 70%를 넘겨 여기서 멈춤). 외부 펌프 모드에서 붙여넣기 계열 명령 뒤에 IME와 드래그 입력이 멈추는 것을 [K41](pages/reference/known-constraints.md)에 적었습니다. 새 시험의 검증은 사용자의 요청대로 수정 뒤 3회 통과로 했습니다.

## [2026-10-12] ingest | Views 보강

- `t_views_rest`(26)를 추가해 Views를 79%에서 89%로, 전체를 84.0%에서 87.1%로 올렸습니다. 팝업 델리게이트는 [K42](pages/reference/known-constraints.md)에 적었습니다.

## [2026-10-12] ingest | 남은 항목 확인

- K37, K39, K42, 창 델리게이트 질문, `bridge.cc`, K26을 다시 확인했습니다. 대부분 환경이 아니라 조건의 문제였습니다: 오디오와 인쇄 핸들러는 Alloy 스타일(`runtimeStyle = 2`)에서 동작하고, 팝업은 정상이었으며, 종료 `SIGSEGV`는 `createBrowserView`의 브라우저가 닫히지 않던 결함이었습니다.
- 고친 결함: `createBrowserView`가 클라이언트를 감싸지 않아 종료 때 브라우저가 닫히지 않고 JS 브리지도 동작하지 않던 것, 노출한 PHP 함수가 `NAN`이나 UTF-8이 아닌 문자열을 돌려주면 `0`이 JS로 가던 것, 오디오 스레드가 PHP를 기다리다 끝나지 않던 것(스트림 콜백 세 가지를 기다리지 않게 변경).
- 시험 `t_bridge_rest`, `t_views_popup`, `t_print_alloy`, `t_audio`를 추가했고 `t_views_rest`를 고쳤습니다. 전체 메서드 실행 비율은 87.1%에서 88.6%(1,113개), 인쇄는 97%, Views는 90%입니다. 제약 K37, K39, K42를 고쳐 쓰고 K43을 추가했으며 K26에 관찰 결과를 더했습니다.
- `tests/coverage-baseline.json`이 지금까지 전부 0으로 저장되어 있어 `--check`가 아무것도 막지 못하고 있었습니다. 상대 경로로 부르면 gcov가 다른 디렉터리에서 돌아 아무것도 찾지 못한 탓입니다. `coverage_report.py`가 경로를 절대 경로로 바꾸고, 실행된 메서드가 0이면 기준선을 쓰지 않도록 고친 뒤 실제 값(1,113개)으로 다시 썼습니다.

## [2026-10-12] ingest | 외부 펌프와 BrowserView 제거 수정

- K43의 원인을 찾아 고쳤습니다: 외부 펌프 모드에서 GLib 기본 메인 컨텍스트(X11 연결)를 돌리는 곳이 없었습니다. `core.cc`가 컨텍스트를 한 바퀴 돌리고 대기에 그 fd를 포함합니다. 입력이 안 오던 문제(F19, D14)와 K41도 같은 원인이었고, 입력 시험이 두 모드에서 돕니다. 전체 61개 시험이 두 모드에서 통과합니다(예상된 실패 2개).
- K42의 남은 제약(살아 있는 브라우저의 `BrowserView`를 떼면 나중에 닫을 때 `SIGTRAP`)은 CEF 쪽 동작이라 `Panel::removeChildView()`, `removeAllChildViews()`가 `Error`를 던져 막도록 했습니다(`BEFORE_CALL`).


## [2026-10-12] ingest | 코드 주석 정비

- 나중에 Sphinx로 API 문서를 만들 수 있도록 손으로 쓴 코드(`ext/src`, `ext/runtime`, `subprocess`)에 Doxygen 주석을, 생성기(`tools/gen`)에 reST docstring을, PHP 보조 파일(`tests/support.php`, `bin/cefloom-runtime`)에 phpDocumentor 주석을 영어로 달았습니다.
- 생성 스텁 `php/cefloom.stub.php`는 생성기가 CEF 헤더의 설명을 phpdoc으로 옮겨 씁니다(모든 클래스, 메서드, 구조체, 열거형). 헤더에 설명이 없는 항목에는 `@see`로 C++ 선언을 답니다.
- 누락을 기계적으로 찾는 `tools/check-comments.py`를 추가했습니다(처음 약 3,400건, 지금 0건). 규칙과 범위, 진행 대장은 [코드 주석 작성 규칙과 점검 절차](pages/procedures/code-comments.md)에 있습니다.

## [2026-10-12] lint | 주석의 사실 확인

- 첫 작성본의 주석에 추정이 섞여 있어, 코드와 CEF 헤더(스레드 문장)에 대조해 다시 확인했습니다. 틀리거나 근거 없는 주장 약 85곳을 고치거나 지웠습니다(C++ 12곳, Python과 PHP 약 75곳).
- 확인하지 못한 항목: 인증 흐름의 `Fetcher`와 HTTPS 서버(CEF 실행이 필요), `cefloom-runtime install`의 압축 파일 구성(실제 런타임 압축 파일 필요). 규칙은 [코드 주석 작성 규칙과 점검 절차](pages/procedures/code-comments.md)의 "사실 확인"에 추가했습니다.

## [2026-10-13] ingest | 문서 사이트

- cefweaver의 Sphinx 구성(RTD 테마, `-W` Makefile, gettext)을 따라 `docs/`에 `conf.py`, `index.rst`, `Makefile`, `requirements.txt`를 추가했습니다. 가이드는 기존 Markdown(MyST), API 명세는 `tools/docs/gen_api.py`가 스텁에서 생성하는 phpdomain reST입니다.
- 쿡북 `docs/cookbook.md`와 원본 프로그램 `docs/recipes/cookbook.php`를 더했습니다. 프로그램은 3회 연속 같은 출력을 냈고 `t_docs`가 실행합니다.
- phpdomain이 `NAME = 값` 시그니처에서 멈추는 문제를 확인해 값을 본문으로 옮겼습니다. 내용은 [문서 사이트](pages/procedures/docs-site.md)에 있습니다.

## [2026-10-13] ingest | autodoc과 한국어 번역

- `tools/gen`의 독스트링을 autodoc으로 사이트에 넣었습니다(`docs/api/generator.rst`). reST 경고가 난 독스트링 몇 곳(`|이름|`, 목록 앞 빈 줄)을 고쳤고 `generate.py --check`는 그대로 통과합니다.
- 한국어 카탈로그(`docs/locale/ko`)를 가이드 12쪽, 쿡북, 목차에 채웠습니다. 한국어 빌드가 `-W`로 통과합니다. API 설명은 번역하지 않았습니다.
- C++ 주석(Doxygen, Breathe)은 `doxygen`이 설치되어 있지 않아 설정하지 못했습니다.

## [2026-10-13] ingest | 가이드 주제 맞추기

- 사용자 요청으로 cefloom과 cefweaver의 가이드 주제를 맞췄습니다. cefloom에 컨텍스트 메뉴, 소리와 마이크와 카메라, Wayland와 GPU, 한계 4쪽을, cefweaver에 개념, 메시지 루프, JS 브리지, 인쇄, 구성, 문제 해결 6쪽을 더했습니다. 대응표는 [문서 사이트](pages/procedures/docs-site.md)에 있습니다.
- 주장은 소스를 읽거나 실제 실행으로 확인했고, 확인하지 못한 항목은 쪽에 "not tried"로 적었습니다. 새 쪽의 한국어 번역도 더했습니다.

## [2026-10-13] ingest | GitHub Pages 배포 준비
`.github/workflows/docs.yml`과 `make -C docs site`를 더했다. 로컬에서 `site` 빌드가 성공했고 워크플로는 GitHub에서 아직 실행하지 않았다. 푸시와 Pages 설정은 사용자의 결정을 기다린다. 내용은 [문서 사이트](pages/procedures/docs-site.md).

## [2026-10-13] ingest | GitHub Pages 배포 완료
사용자 요청으로 푸시하고 Pages(Source: GitHub Actions)를 켰다. 첫 실행이 성공했고 https://search5.github.io/cefloom/ 이 200으로 응답한다. 같은 방식으로 cefweaver도 배포했다.

## [2026-10-13] ingest | 워크플로를 수동 실행으로, 액션을 Node 24로
사용자 요청으로 docs 워크플로의 푸시 트리거를 없애고 `workflow_dispatch`만 남겼다. 액션을 Node 24 기반 최신 메이저로 올렸다. [문서 사이트](pages/procedures/docs-site.md) 갱신.
