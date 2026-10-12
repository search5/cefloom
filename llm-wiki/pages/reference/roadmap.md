---
title: 로드맵
type: reference
sources:
  - cefloom/experiments/ffi-precheck/run.sh
  - cefloom/tests/run.sh
  - cefloom/tests/t_bridge.php
  - cefloom/tools/gen/generate.py
  - cefweaver_clone/cefweaver/CLAUDE.md
updated: 2026-10-12
---

# 로드맵

개발 단계와 기간 추정입니다. 기간은 **한 사람이 매일 작업한다고 가정한 대략의 추정**이며 실제 구현 경험으로 검증된 값이 아닙니다. 상태 열은 2026-10-12 기준으로 코드와 테스트를 확인한 내용입니다.

## 단계

| 단계 | 내용 | 상태 | 추정 기간 |
| --- | --- | --- | --- |
| 0. 사전 검증 | FFI로 C 확장의 전제 확인: 스레드 모델, 참조 카운트, 펌프, JS 왕복, 종료 | **완료** (2026-10-12) | 2일 이내 (실제 소요) |
| 1. 확장 골격 | `phpize` 기반 빌드, CMake 연동, CEF 초기화, 메시지 펌프, 네이티브 참조 카운트와 펌프 예약 | **구현됨** (Linux, `tools/build.sh`) | 1주에서 2주 |
| 2. 핸들러와 JS 연동 | 핵심 핸들러를 PHP 클래스로 연결, 스레드 간 큐, JS와 PHP 양방향 호출 | **구현됨** (Linux). 핸들러는 `t_handlers`, `t_scheme`, JS 브리지는 `t_bridge`로 시험. IO 스레드 콜백의 일부는 비동기([D12](design-decisions.md)) | 2주에서 3주 |
| 3. 생성기 | 헤더에서 Zend 클래스와 래퍼 코드 생성, 수명 관리 일반화, 커버리지 보고서 | **구현됨**. 1284개 메서드와 함수 중 1262개를 생성([커버리지 보고서](coverage-report.md)) | 3주에서 5주 |
| 4. 배포 | 플랫폼별 바이너리 빌드, 설치 방법, CEF 런타임 내려받기, 문서와 예제 | **일부 구현** (PIE용 패키징, 런타임 설치 도구, README). 릴리스 게시 전 | 2주에서 3주 |
| 5. 추가 플랫폼 | Windows, macOS: 창 임베딩, 입력, DPI | 미착수 | 플랫폼마다 2주에서 4주 |

- Linux 한 곳에서 공개할 만한 첫 버전까지 합계 **8주에서 13주**로 추정했습니다. 1단계에서 3단계는 구현되었으므로 남은 것은 4단계(배포)와 아래의 미해결 항목입니다. 실제 소요 시간은 기록하지 않았습니다.
- 세 플랫폼 모두 지원하는 안정 버전은 **5개월에서 8개월**로 추정합니다.
- 비교: FFI 방식은 4주에서 8주로 추정했으나 채택하지 않았습니다([접근 방식 비교](../analyses/approach-comparison.md)).

## 기간을 줄이는 요소

- `cefweaver`에 헤더 기반 생성기, 서브프로세스, 메시지 펌프, 래퍼가 이미 있습니다([입력 소스 프로젝트](../components/source-projects.md)).
- 사전 검증으로 스레드 모델의 불확실성이 줄었습니다.

## 기간을 늘릴 수 있는 요소

- C API와 C++ API 사이의 변환, 값을 돌려주는 출력 인자, 플랫폼별 구조체에서 생성기가 처리하지 못하는 예외가 많이 나올 수 있습니다.
- UI 스레드가 아닌 스레드의 핸들러 처리는 구현과 시험을 마쳤지만, 즉시 반환값이 필요한 IO 스레드 콜백은 교착 가능성이 남아 있습니다([F14](verified-findings.md), [D12](design-decisions.md)).
- 플랫폼별 창 통합이 예상보다 오래 걸릴 수 있습니다(`cefweaver`의 위키에 Windows와 macOS에서 고친 사례가 많음).
- CEF 런타임 내려받기와 배포 행렬 유지 비용.

## 다음에 할 일 (제안)

1. **배포(4단계)**: 패키징은 구현하고 오프라인으로 시험했습니다. 저장소는 `github.com/search5/cefloom`(공개)이고 패키지 이름은 `search5/cefloom`입니다. 남은 일은 라이선스 파일 추가, 태그와 릴리스 자산 업로드, Packagist 등록, 그리고 `pie install`을 끝까지 시험하는 것입니다([체크리스트](../procedures/package-and-release.md), [K24, K25, K28](known-constraints.md)). 빌드는 아직 이 작업 영역의 `cefweaver_clone` 산출물(헤더, 래퍼 라이브러리, 런타임)에 의존합니다.
2. **테스트 보강**: 완료했습니다. 계획의 A, B, C를 모두 실행해 전체 메서드의 88.6%를 시험이 실행하고(보강 전 20.7%), 손으로 쓴 파일은 85% 이상(`lazy_stubs.cc`와 `cefloom.cc` 제외)이며, 시험이 결함 아홉 가지를 찾아 고쳤습니다([테스트 보강 계획](test-plan.md), [K29](known-constraints.md)). 남은 것은 환경이 필요한 부분입니다.
3. **즉시 반환값이 필요한 IO 스레드 콜백**: 현재는 PHP를 동기로 부르며 5초 안에 풀리지 않으면 CEF 기본 동작으로 되돌아갑니다. `getResourceHandler`를 규칙 기반으로 등록하는 방식 등을 요구가 생길 때 설계합니다([D12](design-decisions.md)).
4. **확인하지 못한 멈춤**: `t_browser_nav`가 전체 실행 중 한 번 멈춘 적이 있으나 핸들러를 쓰지 않는 테스트라서 [F14](verified-findings.md)와 같은 원인인지 확인하지 못했습니다. 이후 반복 실행에서는 재현되지 않았습니다.
5. **추가 플랫폼(5단계)**: Windows, macOS는 시작하지 않았습니다. 서브프로세스의 스택 보호 옵션([F15](verified-findings.md)) 같은 컴파일러 옵션은 플랫폼마다 다시 확인해야 합니다.
6. 사전 검증 자료를 공개해 반응을 볼지 결정합니다. 수요가 불확실한 상태에서 투자를 줄이려는 선택지입니다([수요 조사](../analyses/demand-research.md)).

## 관련 페이지

- [프로젝트 목표와 범위](../concepts/project-goals.md)
- [주의 사항](cautions.md)
- [설계 결정 기록](design-decisions.md)
