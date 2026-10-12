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
| K14 | 오프스크린 모드의 픽셀 내용 | `on_paint` 수신 횟수만 셈 | 버퍼를 읽어 비교 |
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
| K27 | 인쇄 대화상자(`PrintHandler`), 실제 Cast 기기, 데스크톱 환경의 창 관리자와 Wayland에서의 Views | 창과 PDF 출력과 라우터 호출과 입력(가상 화면)만 시험함([F18](verified-findings.md), [F19](verified-findings.md)) | 데스크톱 환경의 화면에서 입력을 보내 보고, 인쇄 핸들러를 구현해 `print()`를 호출 |
| K28 | 저장소의 라이선스 | 라이선스 파일이 없음(공개 저장소이므로 기본적으로 사용 권리가 없음). 소유자가 아직 정하지 않았고, K18과 K25에 따라 선택이 달라질 수 있음 | 소유자가 선택하고, 포함한 제3자 코드(`tools/gen/vendor/`), `cefweaver` 재사용분(K18), CEF 재배포 고지(K25)를 확인해 `LICENSE`를 추가 |

## 문서와 소스의 불일치

현재 없습니다.

## 관련 페이지

- [실험으로 확인한 사실](verified-findings.md)
- [주의 사항](cautions.md)
- [수요 조사](../analyses/demand-research.md)
