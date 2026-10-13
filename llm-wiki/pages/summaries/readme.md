---
title: README 요약
type: summary
sources:
  - cefloom/README.md
  - cefpython/README.md
  - cefloom/docs/conf.py
updated: 2026-10-13
---

# README 요약

저장소 루트의 `README.md`가 무엇을 말하는지의 요약입니다. 2026-10-13에 cefpython의 `README.md` 구성(목차, 소개, 설치, 예제, 지원)을 참고해 다시 썼습니다.

## 문서 사이트(GitHub Pages)

| 프로젝트 | 영어 | 한국어 |
| --- | --- | --- |
| cefloom | https://search5.github.io/cefloom/en/index.html | https://search5.github.io/cefloom/ko/index.html |
| cefweaver | https://search5.github.io/cefweaver/en/index.html | https://search5.github.io/cefweaver/ko/index.html |

두 사이트는 수동으로 실행하는 GitHub Actions 워크플로(`docs.yml`)로 올립니다([문서 사이트](../procedures/docs-site.md)). README는 두 주소를 모두 싣고, 상대 프로젝트는 "관련 프로젝트"에서 가리킵니다.

## README의 구성

- 목차: 소개, 문서, 설치, 지원 플랫폼, 예제, 빌드와 시험, 관련 프로젝트, 라이선스.
- 소개: 5줄 PHP 예제와 특징 네 가지(CEF 헤더에서 생성, PHP 메인 스레드가 UI 스레드, 스킴과 브리지와 PDF, 자체 창과 Views). CLI만 지원하고 릴리스가 아직 없다고 밝힙니다.
- 설치: PIE(확장), `cefloom-runtime`(런타임), 스텁의 세 부분. 릴리스와 Packagist 등록이 없어서 `pie install`은 아직 동작하지 않는다고 적습니다.
- 지원 플랫폼 표: Linux glibc x86_64 PHP 8.5 NTS CLI만 빌드하고 시험했습니다(Ubuntu). arm64는 같은 방식으로 빌드되지만 시험하지 않았고, musl은 지원하지 않으며, macOS와 Windows는 시험하지 않았습니다.
- 예제 네 개(`hello`, `views`, `print-pdf`, `cast`)와 쿡북 예제 위치.
- 라이선스: 저장소에 라이선스 파일이 없습니다(K28). README는 이를 사실대로 적었고 라이선스를 정하지 않았습니다.
- 시험 파일 수(61개)가 README에 적혀 있어서, 시험을 추가하면 README도 고칩니다.

## 관련 페이지

- [문서 사이트](../procedures/docs-site.md)
- [패키징과 릴리스 절차](../procedures/package-and-release.md)
- [코드 주석 작성 규칙과 점검 절차](../procedures/code-comments.md)
