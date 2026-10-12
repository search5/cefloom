---
title: 패키징과 릴리스 절차
type: procedure
sources:
  - cefloom/composer.json
  - cefloom/tools/package.sh
  - cefloom/bin/cefloom-runtime
  - cefloom/tools/build.sh
  - cefloom/README.md
  - cefloom/docs/installation.md
  - cefloom/docs/development.md
  - https://github.com/php/pie/blob/1.6.x/docs/extension-maintainers.md
updated: 2026-10-12
---

# 패키징과 릴리스 절차

확장 모듈은 PIE로, CEF 런타임은 설치 도구로 나누어 배포하는 구성([배포 전략](../concepts/distribution-strategy.md))을 만드는 절차입니다. 아래 1단계에서 3단계와 5단계는 실행해 확인했고, 4단계(릴리스 게시)는 실행하지 않았습니다.

## 1. 빌드

```sh
tools/build.sh
```

기본 빌드는 `libcef.so`에 링크하지 않는 모듈을 만듭니다([D13](../reference/design-decisions.md)). `tools/package.sh`는 링크하는 모듈을 거부합니다.

## 2. 파일 만들기

```sh
tools/package.sh      # build/release/ 에 씁니다
```

- `php_cefloom-<버전>_php<X.Y>-<아키텍처>-linux-glibc-nts.zip`: 최상위에 `cefloom.so`만 있는 압축 파일입니다. 이름은 PIE 1.5.2의 소스(`src/Platform/PrePackagedBinaryAssetName.php`)를 읽어 맞추었습니다. 소문자이고, NTS이면서 디버그가 아니면 `-nts` 접미사(또는 접미사 없음)를 씁니다. 문서에 있는 `nodebug`는 코드에서 쓰이지 않았습니다.
- `cefloom-runtime-<CEF 버전>-linux-<아키텍처>.tar.gz`: CEF 런타임 파일과 `cefloom-runtime.json`(CEF 전체 버전과 파일 목록)입니다. 항목은 하나씩 이름으로 묶습니다. `tar ... .`로 만들면 `.` 항목이 생기고 PHP의 `PharData`가 풀지 못합니다.
- 각 파일 옆의 `.sha256`.

## 3. 시험

릴리스 없이 같은 흐름을 오프라인으로 시험했습니다([F17](../reference/verified-findings.md)).

```sh
unzip php_cefloom-*.zip -d module
XDG_DATA_HOME=$PWD/data php -d extension=$PWD/module/cefloom.so bin/cefloom-runtime install --url=build/release/cefloom-runtime-*.tar.gz
XDG_DATA_HOME=$PWD/data CEFLOOM_DIST=$PWD/module tests/run.sh
```

## 4. 릴리스 게시 (저장소만 게시, 릴리스는 아직)

저장소는 2026-10-12에 `https://github.com/search5/cefloom`(공개)으로 게시했습니다. 아래의 태그와 릴리스 자산 첨부, Packagist 등록은 아직 하지 않았습니다. `composer.json`의 패키지 이름은 저장소 소유자에 맞춰 `search5/cefloom`으로 정했습니다(2026-10-12, 임시 값 `cefloom/cefloom`에서 변경). 저장소에는 라이선스 파일이 아직 없습니다.

PIE는 GitHub 릴리스의 자산에서 바이너리를 찾습니다(PIE의 `PackageReleaseAssets`가 릴리스를 태그 이름으로 찾음). 따라서 저장소를 GitHub에 올리고, 태그를 만들고, 릴리스에 `build/release/`의 파일을 첨부해야 합니다. 이 작업은 외부에 공개되는 일이라 하지 않았고, 라이선스를 정해야 합니다(공급자 이름은 `search5`, 저장소는 `github.com/search5/cefloom`으로 정했습니다). Packagist에 저장소를 등록하면 `pie install search5/cefloom`이 동작합니다.

## 첫 릴리스 전에 남은 일 (체크리스트)

2026-10-12 기준입니다. 순서대로 합니다. 1은 2와 3의 전제입니다.

1. **라이선스 파일 추가** (미정). 저장소에는 라이선스 파일이 없습니다. 라이선스가 없는 공개 저장소는 다른 사람이 쓰거나 수정하거나 재배포할 권리가 없습니다. 정하기 전에 확인할 것: `tools/gen`이 가져온 CEF 파서(`tools/gen/vendor/`, 자체 `LICENSE.txt`가 있음), `cefweaver` 코드를 재사용한 부분의 라이선스([K18](../reference/known-constraints.md)), 런타임을 재배포할 때의 CEF와 Chromium 고지([K25](../reference/known-constraints.md)). 선택 결과는 [K28](../reference/known-constraints.md)을 닫으면서 기록합니다.
2. **태그와 GitHub 릴리스**. 태그는 `v` 없이 `CEFLOOM_VERSION`과 같게(예: `0.1.0`) 만들고, `tools/package.sh`가 만든 파일 2개(`php_cefloom-…zip`, `cefloom-runtime-…tar.gz`)와 각 `.sha256`을 릴리스 자산으로 올립니다. 런타임 압축 파일에 CEF 라이선스 고지를 넣는 일([K25](../reference/known-constraints.md))은 이 단계 전에 합니다.
3. **Packagist 등록**. `search5/cefloom`으로 저장소를 등록합니다. 등록하면 `pie install search5/cefloom`이 릴리스 자산을 찾습니다.
4. **끝까지 시험**. 격리된 환경에서 `pie install search5/cefloom`과 `cefloom-runtime install`을 실행하고 전체 테스트를 돌립니다([K24](../reference/known-constraints.md)). 이 시험에서 PIE가 태그의 `v` 접두사를 다루는 방식과 `php.ini` 활성화도 확인합니다.

2와 3은 외부에 공개되는 일이라 소유자의 확인을 받고 합니다.

## 5. 사용자 쪽

```sh
pie install search5/cefloom
php bin/cefloom-runtime install --base-url=<릴리스 자산 디렉터리>
```

런타임은 `~/.local/share/cefloom/runtime/<CEF 버전>`에 들어가며, 확장은 `CEFLOOM_HOME`, 모듈 옆, 이 디렉터리 순서로 찾습니다.

## 사용자 문서

배포 방식(PIE, 런타임 설치 도구)에 맞춘 사용자 문서는 `cefloom/docs/`에 영어로 있습니다(설치, 시작하기, 개념, 메시지 루프, 핸들러, JS 브리지, Views, 인쇄, Cast, 설정, 문제 해결, 개발). 문서의 코드는 예제와 테스트로 실행해 확인했고(스킴 예제와 브리지 예제는 문서에 쓴 그대로 실행), 링크와 참조 경로는 점검했습니다. 첫 릴리스를 게시하지 않았으므로 설치 문서의 `pie install` 명령과 패키지 이름은 예정된 형태입니다.

## 관련 페이지

- [배포 전략](../concepts/distribution-strategy.md)
- [실험으로 확인한 사실](../reference/verified-findings.md)
- [알려진 제약과 미검증 항목](../reference/known-constraints.md)
- [로드맵](../reference/roadmap.md)
- [코드 주석 작성 규칙과 점검 절차](code-comments.md)
