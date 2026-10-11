---
title: llm-wiki 패턴 요약 (카파시)
type: summary
sources:
  - cefweaver_clone/cefweaver/llm-wiki/pages/summaries/karpathy-llm-wiki.md
  - cefweaver_clone/cefweaver/llm-wiki/SCHEMA.md
  - cefloom/llm-wiki/SCHEMA.md
updated: 2026-10-12
---

# llm-wiki 패턴 요약 (카파시)

이 위키가 따르는 패턴의 요약입니다. 원문은 안드레이 카파시가 2026-04-04에 공개한 GitHub gist "LLM Wiki"입니다. **이번에 원문을 다시 내려받지 않았고**, 원문을 전문 읽고 정리한 `cefweaver` 위키의 요약 페이지(`cefweaver_clone/cefweaver/llm-wiki/pages/summaries/karpathy-llm-wiki.md`)를 근거로 줄여 적었습니다. 원문과 다를 가능성이 있으므로 정확한 인용이 필요하면 원문을 확인하십시오.

## 핵심 아이디어

문서와 LLM을 함께 쓰는 보통의 방식(RAG)은 질문마다 관련 조각을 찾아 답을 만들기 때문에 지식이 쌓이지 않습니다. 이 패턴은 LLM이 원본 자료와 사용자 사이에 **지속되는 위키**를 만들고 유지하게 합니다. 새 자료가 들어오면 LLM이 읽고 기존 위키에 통합합니다: 페이지를 갱신하고, 요약을 고치고, 기존 주장과 어긋나는 곳을 표시합니다. 지식은 한 번 정리되어 최신 상태로 유지되며 질문마다 다시 유도되지 않습니다. 사람은 자료를 고르고 질문하며, 위키는 LLM이 씁니다. 유지 비용이 거의 0이라서 위키가 유지된다는 것이 핵심 논거입니다.

## 세 층

1. **원본 자료**: 불변이며 LLM은 읽기만 합니다. 진실의 원천입니다.
2. **위키**: LLM이 만들고 소유하는 마크다운 파일의 디렉터리입니다.
3. **스키마**: 구조, 규칙, 작업 절차를 LLM에게 알려 주는 문서(Claude Code는 `CLAUDE.md`)입니다.

## 작업

- **ingest(반영)**: 새 자료를 읽고, 요약 페이지를 쓰고, 관련 페이지를 고치고, 색인과 기록을 갱신합니다. 자료 하나가 여러 페이지에 영향을 줍니다.
- **query(질문)**: 색인으로 관련 페이지를 찾아 답을 종합합니다. 가치 있는 답변은 새 페이지로 위키에 되돌려 저장합니다.
- **lint(점검)**: 모순, 오래된 주장, 고아 페이지, 빠진 교차 참조, 빈틈을 주기적으로 찾습니다.

## 색인과 기록

- **index.md**: 내용 중심의 카탈로그입니다. 질문에 답할 때 먼저 읽습니다.
- **log.md**: 시간순 덧붙이기 전용 기록입니다. `## [날짜] 작업 | 제목` 접두사로 간단한 도구가 읽을 수 있게 합니다.

## 이 프로젝트에서의 대응

| 원문의 개념 | cefloom |
| --- | --- |
| 원본 자료 | 형제 프로젝트(`cef_origin`, `cefpython`, `java-cef`, `cefweaver_clone`)와 `experiments/` |
| 위키 | `cefloom/llm-wiki/` ([README](../../README.md)) |
| 스키마 | [SCHEMA.md](../../SCHEMA.md) |
| 점검 도구 | `lint.py` |
| Dataview 호환 | YAML frontmatter(`title`, `type`, `sources`, `updated`) |
| 질문 답변의 환원 | `pages/analyses/` |

이 프로젝트가 패턴에 더한 규칙은 **제안과 확인된 사실의 구분**, `sources`를 작업 영역 기준 경로로 해석하는 방식, 웹 URL 출처 허용입니다([SCHEMA.md](../../SCHEMA.md)).

## 관련 페이지

- [프로젝트 목표와 범위](../concepts/project-goals.md)
- [입력 소스 프로젝트](../components/source-projects.md)
