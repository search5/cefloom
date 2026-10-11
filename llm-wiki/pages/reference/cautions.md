---
title: 주의 사항
type: reference
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/ext/src/async.cc
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefweaver_clone/cefweaver/llm-wiki/pages/reference/verified-findings.md
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/runtime-layout.md
  - cef_origin/include/capi/cef_base_capi.h
updated: 2026-10-12
---

# 주의 사항

구현할 때 어기면 안 되는 규칙과, 알고 있어야 할 위험을 모았습니다. 각 항목에 근거를 달았습니다. "확인"은 직접 실행해서 확인한 것, "기록"은 `cefweaver` 위키의 기록을 인용한 것(이번에 재현하지 않음), "해석"은 근거에서 추론한 것입니다.

## 스레드와 PHP 실행

1. **PHP 코드는 PHP 메인 스레드에서만 실행합니다.** 다른 스레드에서 PHP 클로저가 실행되면 PHP가 스택 한도 오류를 내고 세그멘테이션 오류로 종료됩니다. (확인: [F3, F5](verified-findings.md))
2. **참조 카운트 함수와 `on_schedule_message_pump_work`는 PHP를 건드리지 않는 네이티브 코드여야 합니다.** CEF 내부 스레드에서 호출됩니다. (확인: F4, F5)
3. **콜백의 예외를 CEF로 전파하지 않습니다.** 진입점에서 모두 잡아 PHP 오류 처리기로 보고합니다. (확인: F12, FFI에서 관찰)
4. **UI 스레드가 아닌 스레드에서 불리는 핸들러를 PHP로 바로 부르지 않습니다.** 리소스, 스킴 핸들러 같은 것이 해당합니다. (기록: `cefweaver`의 위키가 시험으로 확인. PHP에서는 미검증) 새 핸들러를 만들 때는 [D12](design-decisions.md)의 방식으로 비동기 처리할 수 있는지 먼저 확인합니다.
5. **UI 스레드로 넘기고 기다리는 구조는 교착에 주의합니다.** UI 스레드가 호출 스레드를 기다리는 경로가 있으면 서로 기다리게 됩니다. (확인: [F14](verified-findings.md), `Chrome_IOThread`가 PHP를 기다리는 동안 UI 스레드가 GPU 프로세스의 응답을 기다려 교착이 일어났습니다.) IO 스레드를 PHP 실행 때문에 막지 않습니다. 즉시 반환값이 필요한 콜백은 아직 이 규칙을 지키지 못하며, 5초 안에 풀리지 않으면 PHP 판단 없이 CEF 기본 동작으로 되돌아갑니다([D12](design-decisions.md)).

## 수명과 종료

6. **브라우저를 닫고 참조를 놓은 뒤에 `cef_shutdown()`을 호출합니다.** 순서를 어기면 세그멘테이션 오류가 났다는 기록이 있습니다. (기록: `cefweaver`)
7. **`cef_shutdown()` 뒤에 `cef_initialize()`를 다시 호출하지 않습니다.** 프로세스당 한 번만 초기화할 수 있고, 다시 시도하면 세그멘테이션 오류가 났다는 기록이 있습니다. (기록: `cefweaver`의 F20)
8. **참조 카운트 규칙을 지킵니다.** 포인터의 새 사본마다 `add_ref`, 카운트가 0이 되면 객체가 스스로 삭제됩니다. (확인: 헤더 `cef_origin/include/capi/cef_base_capi.h`) 사전 검증은 객체를 해제하지 않았으므로 실제 해제는 미검증입니다.
9. **PHP 객체, 핸들러, 브라우저 사이의 순환 참조를 끊는 규칙을 둡니다.** (해석: [수명 관리](../concepts/object-lifetime.md))

## CEF 초기화

10. **API 버전을 설정하기 전에 라이브러리 객체를 쓰지 않습니다.** `cef_api_hash(version, entry)`가 첫 호출에서 전역 버전을 정하며, 그 전에 객체를 만들면 프로세스가 중단됩니다. 사전 검증은 `cef_api_hash(999999, 2)`를 가장 먼저 호출했습니다. (기록: `cefweaver`의 F2)
11. **`icudtl.dat`와 리소스를 `libcef.so`와 같은 디렉터리에 둡니다.** `resources_dir_path`로는 `icudtl.dat` 위치를 바꾸지 못했습니다. (기록: `cefweaver`의 F1)
12. **샌드박스는 `no_sandbox=1`로 껐습니다.** 샌드박스를 켜는 배포는 미검증입니다. 보안에 영향이 있으므로 배포 전에 결정해야 합니다. (확인: 사전 검증이 `no_sandbox=1`로 동작)
13. **API 버전은 `CEF_API_VERSION_EXPERIMENTAL`(999999)로 설정했습니다.** 이 값이 안정 버전에 고정되는지, 고정해야 하는지는 정하지 못했습니다. (미결)
14. **Wayland 환경에서는 창이 실제 화면에 열릴 수 있습니다.** 사전 검증은 `WAYLAND_DISPLAY`를 해제하고 Xvfb에서 실행했으며 `--ozone-platform=x11` 스위치를 추가했습니다. (확인) 개발 중 실제 화면에 창을 여는 시험은 사용자의 허락을 받고 합니다. (기록: `cefweaver`의 `CLAUDE.md`)

## 프로세스와 배포

15. **서브프로세스는 PHP를 로드하지 않는 별도 실행 파일입니다.** 렌더러에서 PHP를 실행할 수 없습니다. JS 브리지는 프로세스 메시지를 거쳐야 합니다. (해석: [프로세스 모델과 JS 브리지](../concepts/process-model-and-js-bridge.md))
16. **CLI 전용입니다.** PHP-FPM, Apache 모듈 환경은 지원하지 않습니다. (설계 판단)
17. **CEF 런타임 크기가 큽니다.** 확인한 `libcef.so`가 272MB와 1.4GB 두 가지였습니다. 내려받는 방식과 캐시 위치를 설계해야 합니다. ([배포 전략](../concepts/distribution-strategy.md))
18. **바이너리 배포 행렬이 늘어납니다.** 운영체제, PHP 버전, NTS/ZTS 조합입니다. 처음에는 좁게 시작합니다.

## 운영과 정직성

19. **제안을 사실처럼 쓰지 않습니다.** 구현하거나 실행하지 않은 설계는 "제안", "미검증"이라고 적습니다. ([SCHEMA.md](../../SCHEMA.md))
20. **시장 수요 수치는 스냅샷입니다.** 웹 조사로 얻은 설치 수와 별 수는 조회 시점의 값이며 사이트마다 다릅니다. 투자 결정의 근거로 쓸 때는 다시 조회합니다. ([수요 조사](../analyses/demand-research.md))
21. **시스템에 설치한 PHP 패키지가 있습니다.** `php8.5-cli`, `php8.5-dev`를 `apt`로 설치했습니다. ([환경 준비](../procedures/setup-php-environment.md))
22. **검증이 의존하는 `cefweaver`의 `build/`는 빌드 산출물입니다.** 정리하면 사전 검증을 재현할 수 없습니다.
23. **검증 시간은 한 번에 수 초입니다.** 장시간 실행, 다중 브라우저, 메모리 누수는 확인하지 않았습니다. ([알려진 제약](known-constraints.md))
24. **원본 프로젝트를 수정하지 않습니다.** 형제 프로젝트는 읽기 전용입니다. 재사용이 필요하면 복사하거나 참조하는 방식을 결정 기록에 남깁니다.
25. **테스트는 한 번에 하나만 실행합니다.** 여러 테스트를 동시에 실행하면 메모리 소비가 늘어 멈출 수 있다는 것이 사람의 판단입니다. `tests/run.sh`는 순차 실행이지만 이전 실행에서 남은 `php` 프로세스가 있으면 겹치므로, 시작하기 전에 남은 프로세스를 확인하고 정리합니다. (기록: 사람의 지시, 원인은 미검증)

## 관련 페이지

- [설계 결정 기록](design-decisions.md)
- [알려진 제약과 미검증 항목](known-constraints.md)
- [스레드와 메시지 루프](../concepts/threading-and-message-loop.md)
- [로드맵](roadmap.md)
