# cefloom llm-wiki

이 디렉터리는 cefloom 프로젝트의 조사와 설계를 담은, LLM이 작성하고 유지하는 위키입니다. 안드레이 카파시(Andrej Karpathy)가 2026-04-04에 공개한 gist "LLM Wiki"의 패턴을 따릅니다. 패턴의 요약은 [llm-wiki 패턴 요약](pages/summaries/karpathy-llm-wiki.md)에 있습니다.

## 프로젝트의 목적

cefloom은 Chromium Embedded Framework(CEF)를 PHP에서 쓰게 하는 PHP 확장 모듈(C++)과 그 바인딩 생성기를 만드는 프로젝트입니다. 작업 영역에는 이미 CEF 원본(`cef_origin`), Python 바인딩(`cefpython`, `cefweaver_clone`), Java 바인딩(`java-cef`)이 있고, 이 소스들을 바탕으로 PHP 바인딩을 설계합니다.

**현재 단계는 설계와 사전 검증입니다(2026-10-12).** 확장 모듈 코드는 아직 없습니다. 사전 검증(`experiments/ffi-precheck/`)으로 C 확장 설계의 핵심 전제를 확인했고, 그 결과와 설계 목표, 주의 사항이 이 위키에 정리되어 있습니다.

## 세 층

| 층 | 위치 | 설명 |
| --- | --- | --- |
| 원본 자료 | 작업 영역의 형제 프로젝트와 `experiments/` | 위키가 읽기만 하는 코드와 검증 자료입니다. |
| 위키 | `cefloom/llm-wiki/` | LLM이 쓰고 유지하는 마크다운 페이지입니다. |
| 스키마 | [SCHEMA.md](SCHEMA.md) | 구조, 형식, 작업 절차 규칙입니다. |

## 사람을 위한 사용법

1. [index.md](index.md)에서 분류별 페이지 목록을 봅니다.
2. 무엇을 왜 만드는지는 [프로젝트 목표와 범위](pages/concepts/project-goals.md)부터 읽습니다.
3. 만들 때 조심할 점은 [주의 사항](pages/reference/cautions.md)에 모여 있습니다.
4. 지금까지 실제로 확인한 사실은 [실험으로 확인한 사실](pages/reference/verified-findings.md), 아직 확인하지 못한 것은 [알려진 제약과 미검증 항목](pages/reference/known-constraints.md)에 있습니다.
5. 다음에 할 일은 [로드맵](pages/reference/roadmap.md)에 있습니다.
6. 위키 내용과 원본이 다르면 원본이 맞습니다. 차이를 발견하면 해당 페이지를 갱신하도록 요청합니다.

## LLM을 위한 사용법

1. 작업을 시작하기 전에 [SCHEMA.md](SCHEMA.md)를 읽고 구조, 형식, 절차를 따릅니다.
2. 질문에 답할 때는 [index.md](index.md)로 관련 페이지를 찾고, 필요하면 `sources`에 적힌 원본을 다시 읽어 확인합니다.
3. 새 자료를 반영하거나 오류를 고친 뒤에는 [log.md](log.md)에 기록을 덧붙이고 index.md를 갱신합니다.
4. **제안과 확인된 사실을 구분합니다.** 구현하거나 실행하지 않은 설계를 사실처럼 쓰지 않습니다.
5. 점검은 `python cefloom/llm-wiki/lint.py`로 시작합니다.

## 파일 구성

| 파일 | 역할 |
| --- | --- |
| [README.md](README.md) | 위키 안내 |
| [SCHEMA.md](SCHEMA.md) | 구조, 형식, 작업 절차 규칙 |
| [index.md](index.md) | 모든 페이지의 분류별 목록 |
| [log.md](log.md) | 시간순 작업 기록 |
| `lint.py` | 점검 도구 |
| `pages/concepts/` | 개념과 설계: 목표, 아키텍처, 스레드 모델, 수명 관리, 생성기, JS 브리지, 배포 |
| `pages/components/` | 구성요소: 입력 소스 프로젝트, 검증 하네스 |
| `pages/procedures/` | 절차: 환경 준비, 사전 검증 재현 |
| `pages/reference/` | 참조: 주의 사항, 결정 기록, 로드맵, 실험 기록, 제약, 용어집 |
| `pages/summaries/` | 원본 문서의 요약 |
| `pages/analyses/` | 수요 조사, NativePHP 조사, 접근 방식 비교 |
| `../experiments/ffi-precheck/` | 사전 검증 스크립트(위키 밖, 실행 가능) |
