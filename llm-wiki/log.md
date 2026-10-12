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
