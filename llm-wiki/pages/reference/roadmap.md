---
title: 로드맵
type: reference
sources:
  - cefloom/experiments/ffi-precheck/run.sh
  - cefweaver_clone/cefweaver/CLAUDE.md
updated: 2026-10-12
---

# 로드맵

개발 단계와 기간 추정입니다. 기간은 **한 사람이 매일 작업한다고 가정한 대략의 추정**이며 실제 구현 경험으로 검증된 값이 아닙니다. 사전 검증이 끝난 지금 구현 방식(C 확장)을 기준으로 다시 계산했습니다.

## 단계

| 단계 | 내용 | 상태 | 추정 기간 |
| --- | --- | --- | --- |
| 0. 사전 검증 | FFI로 C 확장의 전제 확인: 스레드 모델, 참조 카운트, 펌프, JS 왕복, 종료 | **완료** (2026-10-12) | 2일 이내 (실제 소요) |
| 1. 확장 골격 | `phpize` 기반 빌드, CMake 연동, CEF 초기화, 메시지 펌프, 네이티브 참조 카운트와 펌프 예약 | 미착수 | 1주에서 2주 |
| 2. 핸들러와 JS 연동 | 핵심 핸들러를 PHP 클래스로 연결, 스레드 간 큐, JS와 PHP 양방향 호출 | 미착수 | 2주에서 3주 |
| 3. 생성기 | 헤더에서 Zend 클래스와 래퍼 코드 생성, 수명 관리 일반화, 커버리지 보고서 | 미착수 | 3주에서 5주 |
| 4. 배포 | 플랫폼별 바이너리 빌드, 설치 방법, CEF 런타임 내려받기, 문서와 예제 | 미착수 | 2주에서 3주 |
| 5. 추가 플랫폼 | Windows, macOS: 창 임베딩, 입력, DPI | 미착수 | 플랫폼마다 2주에서 4주 |

- Linux 한 곳에서 공개할 만한 첫 버전까지 합계 **8주에서 13주**로 추정합니다.
- 세 플랫폼 모두 지원하는 안정 버전은 **5개월에서 8개월**로 추정합니다.
- 비교: FFI 방식은 4주에서 8주로 추정했으나 채택하지 않았습니다([접근 방식 비교](../analyses/approach-comparison.md)).

## 기간을 줄이는 요소

- `cefweaver`에 헤더 기반 생성기, 서브프로세스, 메시지 펌프, 래퍼가 이미 있습니다([입력 소스 프로젝트](../components/source-projects.md)).
- 사전 검증으로 스레드 모델의 불확실성이 줄었습니다.

## 기간을 늘릴 수 있는 요소

- C API와 C++ API 사이의 변환, 값을 돌려주는 출력 인자, 플랫폼별 구조체에서 생성기가 처리하지 못하는 예외가 많이 나올 수 있습니다.
- UI 스레드가 아닌 스레드의 핸들러 처리(규칙 3)는 설계만 있고 시험이 없습니다.
- 플랫폼별 창 통합이 예상보다 오래 걸릴 수 있습니다(`cefweaver`의 위키에 Windows와 macOS에서 고친 사례가 많음).
- CEF 런타임 내려받기와 배포 행렬 유지 비용.

## 다음에 할 일 (제안)

1. 확장 코드의 위치와 이름을 정합니다([설계 결정 기록](design-decisions.md)의 미결 질문).
2. 구현 전에 읽을 자료: `java-cef/native/CefMessageRouter_N.cpp`, `cefpython/src/javascript_bindings.pyx`, `cef_origin/libcef_dll/ctocpp`(참조 규약 확인), `cefweaver_clone/cefweaver/native/cefwrapper/`의 핸들러 구조.
3. PHP 확장 인자 정보 생성 방법과 확장 설치 도구를 조사합니다([알려진 제약](known-constraints.md)).
4. 1단계의 첫 목표: `php -d extension=cefloom.so`로 로드되고, 사전 검증 `verify.php`와 같은 시나리오를 확장 API로 통과합니다.
5. 1단계를 시작하기 전에 **FFI 반응 확인 실험**을 할지 결정합니다. 수요가 불확실한 상태에서 투자를 줄이려면 사전 검증 자료를 공개해 반응을 보는 선택지가 있습니다([수요 조사](../analyses/demand-research.md)).

## 관련 페이지

- [프로젝트 목표와 범위](../concepts/project-goals.md)
- [주의 사항](cautions.md)
- [설계 결정 기록](design-decisions.md)
