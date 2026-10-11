---
title: 프로젝트 목표와 범위
type: concept
sources:
  - cefweaver_clone/cefweaver/README.rst
  - cefweaver_clone/cefweaver/CLAUDE.md
  - cefloom/experiments/ffi-precheck/verify.php
updated: 2026-10-12
---

# 프로젝트 목표와 범위

cefloom은 CEF를 PHP에서 쓰게 하는 **PHP 확장 모듈(C++)**과, CEF 헤더에서 그 바인딩을 만들어 내는 **생성기**를 만드는 프로젝트입니다. 현재(2026-10-12)는 설계와 사전 검증 단계이며 확장 모듈 코드는 없습니다.

## 누가 무엇을 정했는가

| 항목 | 상태 | 내용 |
| --- | --- | --- |
| 구현 방식 | **사용자가 확정** | C++ PHP 확장 모듈과 코드 생성. FFI는 사전 검증에만 쓰고 제품 방식으로는 쓰지 않습니다. |
| 프로젝트의 목적 | **사용자가 확정** | 배포와 사용자 확보가 목표입니다. |
| 대상 플랫폼 | 기본값(미확정) | Linux를 먼저 하고 Windows와 macOS는 이후에 합니다. |
| PHP 버전 | 기본값(미확정) | 8.3 이상을 가정했습니다. 검증 환경은 PHP 8.5.4(NTS)입니다. |
| 첫 구현 범위 | 기본값(미확정) | 초기화, 브라우저 창 생성, 핵심 핸들러, JS 실행, JS에서 PHP 호출까지입니다. |

"기본값"은 사용자가 따로 지정하지 않아 제안한 값입니다. 확정되면 이 표와 [설계 결정 기록](../reference/design-decisions.md)을 갱신합니다.

## 설계 목표

1. **G1. 헤더 기반 생성**: CEF API 전체를 손으로 중계하지 않고 헤더에서 생성합니다. CEF 버전이 올라가도 재생성으로 따라갈 수 있어야 합니다([생성기 계획](binding-generator-plan.md)).
2. **G2. 단일 스레드 모델**: PHP 메인 스레드가 CEF UI 스레드가 되고, `external_message_pump`로 PHP 루프에서 `CefDoMessageLoopWork()`를 호출합니다([스레드와 메시지 루프](threading-and-message-loop.md)).
3. **G3. PHP는 메인 스레드에서만 실행**: CEF의 다른 스레드에서 호출되는 콜백은 PHP를 건드리지 않고 네이티브 코드에서 처리하며, PHP로 전달해야 하면 메인 스레드로 넘깁니다.
4. **G4. 안전한 수명 관리**: 참조 카운트는 네이티브로 구현하고, PHP 객체의 소멸이 CEF 객체의 수명과 어긋나지 않게 합니다([수명 관리](object-lifetime.md)).
5. **G5. JS와 PHP의 양방향 호출**: 저수준 바인딩만으로는 사용자가 늘지 않으므로, JS에서 PHP 함수를 부르는 단순한 API를 핵심 기능으로 둡니다([프로세스 모델과 JS 브리지](process-model-and-js-bridge.md)).
6. **G6. 쉬운 설치와 5분 예제**: 설치 장벽이 가장 큰 위험이므로 사전 빌드 바이너리와 CEF 런타임 내려받기를 설계에 포함합니다([배포 전략](distribution-strategy.md)).
7. **G7. 위키와 근거 중심 개발**: 제안과 확인된 사실을 구분하고 실험으로 확인한 것을 남깁니다.

## 비목표

- PHP-FPM, Apache `mod_php` 같은 요청 단위 서버 환경 지원. CLI 전용입니다([PHP 실행 환경의 제약](php-runtime-constraints.md)).
- 헤드리스 브라우저 자동화 시장과의 경쟁. `chrome-php/chrome`이 이미 자리 잡고 있습니다([수요 조사](../analyses/demand-research.md)).
- 처음부터 세 플랫폼을 모두 지원하는 것.
- Laravel 통합 같은 상위 프레임워크. 필요하면 바인딩이 안정된 뒤에 얹는 별도 패키지로 둡니다.

## 입력 자료

PHP 바인딩은 작업 영역의 기존 프로젝트를 바탕으로 합니다. 각 프로젝트가 주는 것은 [입력 소스 프로젝트](../components/source-projects.md)에 있습니다.

## 성공 기준 (제안)

- Linux에서 `php example.php` 한 번으로 창이 뜨고, JS에서 PHP 함수가 호출되고, 종료 시 정상 종료 코드가 나옵니다.
- 생성기가 CEF 배포본의 헤더에서 같은 결과를 재현합니다.
- 설치부터 첫 창까지 5분 안에 끝나는 문서와 예제가 있습니다.

## 관련 페이지

- [목표 아키텍처](target-architecture.md)
- [주의 사항](../reference/cautions.md)
- [로드맵](../reference/roadmap.md)
- [접근 방식 비교](../analyses/approach-comparison.md)
- [용어집](../reference/glossary.md)
- [llm-wiki 패턴 요약 (카파시)](../summaries/karpathy-llm-wiki.md)
