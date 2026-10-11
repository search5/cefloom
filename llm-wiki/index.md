# 위키 목차

모든 페이지의 한 줄 요약 목록입니다. 질문에 답할 때 가장 먼저 읽습니다. 규칙은 [SCHEMA.md](SCHEMA.md), 기록은 [log.md](log.md), 안내는 [README.md](README.md)에 있습니다. 이 프로젝트는 설계와 사전 검증 단계이므로 "제안"과 "확인된 사실"을 구분해서 읽으십시오.

## 개념과 설계 (concepts)

- [프로젝트 목표와 범위](pages/concepts/project-goals.md): 무엇을 만드는지, 설계 목표 G1부터 G7, 비목표, 누가 무엇을 정했는지
- [목표 아키텍처](pages/concepts/target-architecture.md): PHP 확장, 서브프로세스, 재사용 후보, C++ API와 C API 중 선택
- [스레드와 메시지 루프](pages/concepts/threading-and-message-loop.md): PHP는 메인 스레드에서만, 네이티브로 완결해야 하는 콜백, 확인된 사실과 설계 규칙
- [참조 카운트와 수명 관리](pages/concepts/object-lifetime.md): CEF의 참조 규칙, 네이티브 카운터, PHP 객체와의 수명 맞추기
- [바인딩 생성기 계획](pages/concepts/binding-generator-plan.md): cefweaver 파이프라인 재사용, PHP 타입 변환 제안, C API 헤더의 기계 처리 증거
- [프로세스 모델과 JS 브리지](pages/concepts/process-model-and-js-bridge.md): 렌더러에 PHP를 둘 수 없는 이유, 기존 구현의 여섯 단계, PHP용 JSON 브리지 제안
- [배포 전략](pages/concepts/distribution-strategy.md): C 확장의 설치 장벽, 배포물 구성, 완화 방안, 사용자 확보 방향
- [PHP 실행 환경의 제약](pages/concepts/php-runtime-constraints.md): CLI 전용, NTS와 ZTS, 확인된 환경, FFI에서 얻은 PHP 쪽 사실

## 구성요소 (components)

- [입력 소스 프로젝트](pages/components/source-projects.md): cef_origin, cefpython, java-cef, cefweaver가 각각 주는 것
- [FFI 사전 검증 하네스](pages/components/ffi-precheck-harness.md): experiments/ffi-precheck의 파일 구성, 시나리오, 한계

## 절차 (procedures)

- [PHP 개발 환경 준비](pages/procedures/setup-php-environment.md): 설치한 패키지와 제거 방법, 확인한 도구
- [사전 검증 재현 절차](pages/procedures/run-ffi-precheck.md): run.sh 실행, 기대 결과, 실패할 때 확인할 것

## 참조 (reference)

- [주의 사항](pages/reference/cautions.md): 구현할 때 어기면 안 되는 규칙 24개와 근거(확인, 기록, 해석 구분)
- [설계 결정 기록](pages/reference/design-decisions.md): D1부터 D11, 상태(확정, 근거 확인, 제안, 기본값), 미결 질문
- [로드맵](pages/reference/roadmap.md): 단계별 기간 추정, 줄이고 늘리는 요소, 다음에 할 일
- [실험으로 확인한 사실](pages/reference/verified-findings.md): 사전 검증 F1부터 F12, 방법과 결과와 영향
- [알려진 제약과 미검증 항목](pages/reference/known-constraints.md): K1부터 K23, 확인하지 못한 이유와 확인 방법
- [용어집](pages/reference/glossary.md): CEF, 외부 메시지 펌프, 참조 카운트, 트램펄린, shim 등

## 요약 (summaries)

- [llm-wiki 패턴 요약 (카파시)](pages/summaries/karpathy-llm-wiki.md): 세 층, ingest와 query와 lint, 이 프로젝트에서의 대응

## 분석 (analyses)

- [구현 방식 비교 (C 확장, FFI, 혼합)](pages/analyses/approach-comparison.md): 세 방식의 비교, 사전 검증 뒤 달라진 판단(순수 FFI는 불가, 혼합은 가능)
- [PHP용 CEF 바인딩의 수요 조사](pages/analyses/demand-research.md): 웹 조사 결과(2026-10-12), 수요는 존재하나 규모가 작다는 판단
- [NativePHP Desktop 조사](pages/analyses/nativephp-desktop.md): 무엇인지, 확인한 것과 못 한 것, cefloom과의 관계
