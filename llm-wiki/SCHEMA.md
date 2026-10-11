# 위키 스키마

이 문서는 위키의 구조와 규칙을 정의합니다. 어떤 LLM이 작업을 이어받더라도 이 규칙을 그대로 따라야 합니다. 카파시(Andrej Karpathy)의 llm-wiki 패턴을 cefloom 프로젝트에 맞게 구체화한 것이며, 패턴 자체는 [llm-wiki 패턴 요약](pages/summaries/karpathy-llm-wiki.md)에 있습니다. 구조와 형식은 `cefweaver`의 위키(`cefweaver_clone/cefweaver/llm-wiki/SCHEMA.md`)를 따르되, 원본 자료가 이 프로젝트 밖에 있다는 점을 반영해 `sources` 해석 기준을 바꾸었습니다. 규칙이 실제로 맞지 않으면 사람과 LLM이 함께 이 문서를 고치고, 고친 내용을 log.md에 `schema` 항목으로 기록합니다.

## 1. 원칙

- 위키는 LLM이 작성하고 유지합니다. 사람은 자료를 고르고, 방향을 정하고, 질문합니다.
- 원본 자료는 위키 작업 중에 수정하지 않습니다. 형제 프로젝트(`cef_origin`, `cefpython`, `java-cef`, `cefweaver_clone`)는 읽기 전용입니다.
- 사실은 원본을 직접 읽거나 직접 실행해서 확인한 내용만 씁니다. 추측은 쓰지 않습니다.
- **설계 제안과 확인된 사실을 구분합니다.** 아직 구현하거나 실행해서 확인하지 않은 설계는 "제안" 또는 "미검증"이라고 밝힙니다. 이 프로젝트는 설계 단계이므로 이 구분이 가장 중요합니다.
- 실험으로 확인한 사실은 명령, 환경, 결과, 일자를 [실험으로 확인한 사실](pages/reference/verified-findings.md)에 남깁니다.
- 원본을 끝까지 추적해도 근거를 얻을 수 없는 항목만 [알려진 제약과 미검증 항목](pages/reference/known-constraints.md)에 모읍니다. 근거를 얻을 수 없는 이유와 얻는 구체적 방법을 함께 적습니다.
- 문서와 소스가 다르면 소스를 따르고, 불일치를 known-constraints에 기록합니다.
- 좋은 답변과 분석은 대화 속에 사라지게 두지 않고 `analyses/` 페이지로 환원합니다.
- 외부 조사(웹 검색)로 얻은 수치는 조회한 날짜와 출처를 밝히고, 시점이 지나면 달라질 수 있는 스냅샷으로 취급합니다.

## 2. 세 층 구조

| 층 | 이 프로젝트에서 | 소유 |
| --- | --- | --- |
| 원본 자료 | 작업 영역(`/home/jiho/cef_framework`)의 `cef_origin`, `cefpython`, `java-cef`, `cefweaver_clone`과 이 프로젝트의 `experiments/`. 사용 중인 CEF 배포본의 헤더(`cefweaver_clone/cefweaver/build/native/cef/include`)도 읽기 전용 자료입니다. | 사람과 개발 작업 |
| 위키 | `cefloom/llm-wiki/` | LLM |
| 스키마 | 이 문서 | 사람과 LLM이 함께 |

## 3. 디렉터리 구조

```
cefloom/
  experiments/     위키의 근거가 되는 실행 가능한 검증 자료
  llm-wiki/
    README.md      목적과 사용법
    SCHEMA.md      이 문서
    index.md       모든 페이지의 분류별 목록 (질문에 답할 때 가장 먼저 읽습니다)
    log.md         시간순 작업 기록 (덧붙이기 전용)
    lint.py        점검 도구 (표준 라이브러리만 사용)
    pages/
      concepts/    개념과 설계 (type: concept)
      components/  구성요소 (type: component)
      procedures/  절차 (type: procedure)
      reference/   참조, 주의 사항, 결정 기록, 용어집, 로드맵, 실험 기록 (type: reference)
      summaries/   원본 문서의 요약 (type: summary)
      analyses/    질문에 답하며 만든 분석과 비교 (type: analysis)
```

## 4. 페이지 형식

모든 페이지는 아래 frontmatter로 시작합니다.

```
---
title: 페이지 제목
type: concept | component | procedure | reference | summary | analysis
sources:
  - 작업 영역 기준 원본 경로 또는 https URL
updated: YYYY-MM-DD
---
```

- `title`: 한국어 제목입니다. 식별자는 원문을 유지합니다.
- `type`: 위 여섯 값 중 하나이며 페이지가 놓인 폴더와 일치해야 합니다(`concepts`는 `concept`처럼 단수형).
- `sources`: 근거가 된 파일의 경로 목록입니다. 경로는 **작업 영역(`cefloom`의 부모 디렉터리) 기준**이므로 `cef_origin/tools/cef_parser.py`, `cefloom/experiments/ffi-precheck/verify.php`처럼 씁니다. 웹 조사가 근거이면 `https://` URL도 적을 수 있습니다(존재 여부는 점검하지 않으므로 조회일을 본문에 적습니다). 최소 한 개이고, 파일은 모두 실제로 존재해야 하며, 실제로 읽은 것만 적습니다.
- `updated`: 마지막으로 내용을 확인하고 고친 날짜입니다.
- `generated`(선택): `true`이면 도구가 만드는 페이지입니다. 직접 고치지 않고 길이 제한을 적용하지 않습니다. 다른 페이지와 같이 index.md에 등재하고 다른 페이지에서 링크합니다. 지금은 [생성기 커버리지 보고서](pages/reference/coverage-report.md) 하나이며 `python tools/gen/generate.py`가 만듭니다.

본문은 `# 제목` 한 개로 시작하고 `##` 절로 나눕니다. 한 페이지는 한 주제에 집중하며 200줄 이내로 유지하고, 넘으면 주제별로 나눕니다. 끝에 `## 관련 페이지` 절을 두어 교차 참조를 나열합니다.

## 5. 명명 규칙

- 파일명은 영문 소문자 kebab-case에 확장자 `.md`입니다. 예: `threading-and-message-loop.md`
- 파일명은 위키 전체에서 유일해야 합니다.
- 위키 안의 링크는 상대 경로 마크다운 링크만 씁니다. 같은 폴더는 파일명만, 다른 폴더는 `../concepts/x.md`처럼 씁니다.
- cefloom 밖의 파일(형제 프로젝트의 소스와 위키)은 링크하지 않고 본문에서 백틱으로 작업 영역 기준 경로를 표기합니다. 한 컴퓨터에만 있는 경로이기 때문입니다. 예: `cef_origin/tools/cef_parser.py`

## 6. 문체 규칙

- 본문은 한국어로 쓰고, 코드, 식별자, 파일 경로, 고유 명사는 원문을 유지합니다.
- 서술어와 종결어미를 갖춘 평서문(`~합니다`)으로 쓰고 조사와 어미를 생략하지 않습니다.
- 엠대시와 비유적 표현은 쓰지 않습니다.
- 확인한 사실과 추론, 제안을 구분합니다. 추론은 "추정합니다", "해석합니다", 설계는 "제안합니다"처럼 밝힙니다.

## 7. 작업 절차

### ingest (원본 반영)

1. 반영할 원본(새 파일, 실험 결과, 조사 자료)을 실제로 읽거나 실행합니다.
2. index.md에서 영향받는 기존 페이지를 찾습니다. 없으면 새 페이지를 만듭니다.
3. 페이지를 작성하거나 고치고 `sources`와 `updated`를 갱신합니다. 새 자료가 기존 서술과 어긋나면 어긋남을 해당 페이지에 밝히고 known-constraints에도 기록합니다.
4. 관련 페이지의 `관련 페이지` 절에 서로 링크합니다.
5. 새 페이지는 index.md의 알맞은 분류에 한 줄 요약과 함께 등재합니다.
6. 새 용어가 생겼으면 [용어집](pages/reference/glossary.md)을 갱신합니다.
7. 설계 결정이 바뀌었으면 [설계 결정 기록](pages/reference/design-decisions.md)을, 새 위험이 보이면 [주의 사항](pages/reference/cautions.md)을 갱신합니다.
8. log.md 맨 아래에 `## [YYYY-MM-DD] ingest | 제목` 항목을 덧붙입니다.

코드를 만들기 시작하면 코드를 바꾸는 작업이 끝나기 전에 이 절차로 위키를 맞춥니다. 어떤 페이지가 영향받는지는 `sources`에 그 파일이 적힌 페이지를 찾아서 정합니다.

```sh
grep -rl "cefloom/experiments/ffi-precheck/verify.php" cefloom/llm-wiki/pages
```

### query (질문 답변)

1. index.md를 먼저 읽고 관련 페이지를 찾아 읽습니다.
2. 페이지에 없거나 오래되었다고 의심되면 `sources`의 원본을 직접 읽어 확인합니다.
3. 답변에 근거 페이지와 원본 경로를 밝힙니다.
4. 답변이 비교, 분석, 새로 발견한 연결처럼 가치가 있으면 `analyses/`에 페이지로 저장하고(ingest 절차 5번까지와 같이 등재), log.md에 `query` 항목으로 기록합니다.

### lint (점검)

주기적으로, 그리고 큰 변경 뒤에 점검합니다. 먼저 `python cefloom/llm-wiki/lint.py`로 기계적으로 확인할 수 있는 항목을 점검하고, 나머지는 읽어서 확인합니다.

기계적 점검(`lint.py`): 상대 링크가 실제 파일을 가리키는지(cefloom 밖을 가리키면 오류), 모든 페이지가 index.md에 등재되었는지, frontmatter 항목과 `type`과 폴더의 일치, `sources` 존재 여부, 고아 페이지, 200줄 초과, 엠대시, log.md 항목 형식, log.md 항목의 날짜 순서, index.md가 다른 페이지에 대해 적은 개수(주의 사항의 규칙 수, D와 F와 K의 가장 큰 번호)가 실제 페이지와 같은지.

읽어서 확인하는 항목: 페이지 사이의 모순, 원본이나 환경이 바뀌어 사실과 달라진 서술(특히 "제안"이 "확인된 사실"로 승격되었는지), 자기 페이지가 없는 개념, 빠진 교차 참조, 추가 조사로 채울 빈틈.

마지막에 log.md에 `## [YYYY-MM-DD] lint | 제목` 항목을 덧붙입니다.

## 8. index.md 형식

분류(폴더)별 `##` 절 아래에 `- [제목](pages/폴더/파일.md): 한 줄 요약` 형식으로 나열합니다. 질문에 답할 때 LLM이 가장 먼저 읽는 파일이므로 요약은 페이지를 열기 전에 관련 여부를 판단할 수 있게 씁니다.

## 9. log.md 형식

시간순으로 파일 끝에 덧붙이고 기존 항목은 고치지 않습니다. 제목 줄은 다음 형식이며 작업 종류는 `ingest`, `query`, `lint`, `schema` 중 하나입니다.

```
## [YYYY-MM-DD] 작업종류 | 제목
```

```sh
grep "^## \[" cefloom/llm-wiki/log.md | tail -5   # 최근 다섯 항목
```

## 10. 버전 관리

`cefloom`은 2026-10-12에 git 저장소(`main` 브랜치)로 초기화했습니다. 위키 변경은 관련 코드 변경과 같은 커밋에 넣습니다. `build/`와 Python 캐시(`__pycache__/`, `*.pyc`)는 `.gitignore`로 제외합니다.

커밋 규칙은 다음과 같습니다. 사람이 정한 규칙이므로 LLM은 예외 없이 따릅니다.

- 커밋 메시지와 PR 설명에 공동 저작자 표시(`Co-Authored-By`)를 넣지 않습니다.
- 커밋 메시지와 PR 설명에 Claude 세션 링크(`Claude-Session`)를 넣지 않습니다.
