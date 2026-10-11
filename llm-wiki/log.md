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
