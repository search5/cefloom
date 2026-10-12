---
title: 배포 전략
type: concept
sources:
  - cefweaver_clone/cefweaver/build/native/cef/README.txt
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/runtime-layout.md
  - https://packagist.org/packages/kingbes/pebview
  - https://root.packagist.org/packages/nativephp/laravel/stats
  - https://github.com/php/pie/blob/1.6.x/docs/extension-maintainers.md
  - cefloom/tools/build.sh
updated: 2026-10-12
---

# 배포 전략

프로젝트의 목적은 **배포와 사용자 확보**입니다. 그런데 사용자가 확정한 구현 방식인 C 확장은 PHP 생태계에서 가장 설치 장벽이 높은 방식입니다. 이 긴장이 가장 큰 위험이며, 이 페이지는 위험과 완화 방안을 정리합니다. 웹 조사 수치는 2026-10-12에 조회한 스냅샷입니다.

## 문제

- 경쟁하는 FFI 기반 웹뷰 패키지들은 `composer require`만으로 설치됩니다([수요 조사](../analyses/demand-research.md)). 확장 모듈은 사용자가 `php-dev`로 직접 빌드하거나 플랫폼별 바이너리를 따로 받아야 합니다.
- 바이너리 조합이 많습니다: 운영체제 × 아키텍처 × PHP 마이너 버전(8.3, 8.4, 8.5) × 스레드 모델(NTS, ZTS). CI에서 이 행렬을 계속 유지하는 비용이 듭니다.
- CEF 런타임이 큽니다. 같은 CEF 154.0.34 계열에서 `cefweaver` wheel 디렉터리의 `libcef.so`는 272,219,288바이트이고, 배포본 `Release/libcef.so`는 1,455,021,248바이트였습니다(측정일 2026-10-12). 두 파일이 크기가 다른 이유는 확인하지 않았습니다(추정: 디버그 정보 유무).

## 배포물 구성 (제안)

| 구성 | 내용 | 전달 방법 (후보) |
| --- | --- | --- |
| PHP 확장 | `cefloom.so`(플랫폼과 PHP 버전별) | 사전 빌드 바이너리. PHP 확장 전용 설치 도구 또는 PECL 형태를 검토 |
| 서브프로세스 | `cefloomsubprocess` 실행 파일 | 확장과 같은 압축 파일 또는 첫 실행 때 내려받기 |
| CEF 런타임 | `libcef.so`, 리소스 `.pak`, `icudtl.dat`, `locales/` 등 | 첫 실행 때 내려받기(크기 때문). `cefweaver`의 `prepare.py`가 이미 내려받기를 구현 |
| PHP 쪽 보조 | 스텁(IDE용), 예제, 설치 보조 | Composer 패키지 |

## PIE와 Composer (조사: 2026-10-12)

PIE 1.6.x의 확장 관리자용 문서(`docs/extension-maintainers.md`)를 읽고 정리했습니다. 실제로 PIE를 실행해 보지는 않았습니다.

- `composer require`로 확장 모듈을 설치할 수는 없습니다. 확장 모듈은 PECL의 후속 도구인 PIE(`pie install 공급자/패키지`)로 설치합니다. 패키지 정보는 Composer와 같은 `composer.json`과 Packagist를 쓰며, `"type": "php-ext"`와 `"php-ext"` 객체로 확장임을 선언합니다.
- 미리 빌드한 바이너리를 쓰려면 `"download-url-method": ["pre-packaged-binary", "composer-default"]`처럼 선언하고, 릴리스에 `php_{확장이름}-{버전}_php{PHP버전}-{아키텍처}-{운영체제}-{libc}-{debug}-{스레드모드}.{zip|tgz}` 형식의 파일을 첨부합니다. 아키텍처는 `x86_64`, `arm64` 등, libc는 `glibc`, `musl`입니다. 일치 규칙의 세부는 문서에 적혀 있지 않습니다. 문서는 이름을 맞춘 자산을 만드는 `php/pie-ext-binary-builder` GitHub Action을 권합니다.
- 문서는 동적 라이브러리에 연결된 확장에는 미리 빌드한 바이너리를 쓰지 말라고 경고합니다. 사용자 시스템에 맞는 라이브러리가 없을 수 있기 때문입니다. cefloom은 `libcef.so`에 동적으로 연결됩니다(`readelf`로 확인: NEEDED `libcef.so`, RUNPATH `$ORIGIN`).
- PIE가 설치하는 것은 확장 파일입니다. Windows가 아닌 환경에서 추가 파일(런타임 라이브러리 등)을 함께 배치하거나 설치 후 명령을 실행하는 기능은 문서에 없습니다. CEF 런타임(`libcef.so`, 리소스, `cefloomsubprocess`)은 PIE로 배치할 수 없습니다.
- 소스 빌드(`composer-default`)는 사용할 수 없습니다. `config.m4`가 CEF 배포본의 헤더와 래퍼 라이브러리를 요구하기 때문입니다.
- 판단(제안): 확장 바이너리는 PIE와 Packagist로, PHP 쪽 보조(스텁, 설치 보조 명령)는 `composer require`로, CEF 런타임은 별도 내려받기로 나눕니다. 이 구성은 구현하지 않았습니다.
- 해결됨(2026-10-12): 확장을 php.ini에 켜 두면 모든 PHP 프로세스가 `libcef.so`를 찾아야 하는 문제는 `libcef.so`를 필요할 때 불러오는 빌드(`CEFLOOM_LAZY_LIBCEF=1`)로 풀었습니다. 런타임이 없어도 모듈이 로드되고, 시작 시 메모리가 약 77MB에서 약 20.5MB로 줄었습니다([F16](../reference/verified-findings.md)). 이 빌드가 기본 빌드입니다([D13](../reference/design-decisions.md)).

런타임 파일 배치에는 확인된 규칙이 있습니다. `cefweaver`의 시험으로 `icudtl.dat`는 `libcef.so`가 있는 디렉터리에서만 찾는다는 것이 확인되었습니다(`cefweaver_clone/cefweaver/llm-wiki/pages/concepts/runtime-layout.md`). 내려받는 구조를 설계할 때 파일들을 한 디렉터리에 두어야 합니다.

## 완화 방안

1. **사전 빌드 바이너리를 기본 경로로 삼습니다.** 소스 빌드는 대체 경로로 둡니다.
2. **지원 행렬을 좁게 시작합니다.** Linux x86_64, PHP 한두 개 버전으로 시작하고 반응을 본 뒤 늘립니다.
3. **FFI 호환 프런트엔드를 나중에 추가하는 선택지를 열어 둡니다.** 이번 사전 검증에서 만든 FFI 선언 필터와 shim은 그 가능성의 기초가 됩니다. 단, 이를 제품 방식으로 채택하지 않았습니다([설계 결정 기록](../reference/design-decisions.md)의 D1).
4. **설치부터 첫 창까지 5분 안에 끝나는 문서와 예제**를 만듭니다.

## 사용자 확보 방향 (제안)

1. 바인딩이 아니라 해결하려는 문제를 앞세웁니다: "PHP로 데스크톱 앱 만들기", "PHP용 임베디드 Chromium".
2. NativePHP와 Laravel 사용자층을 겨냥합니다. NativePHP는 수요가 확인된 집단이지만 Electron 기반일 가능성이 높으므로 Node.js 없는 단일 런타임이 차별점이 될 수 있습니다([NativePHP 조사](../analyses/nativephp-desktop.md)).
3. JS와 PHP 사이의 양방향 호출을 핵심 기능으로 삼습니다.
4. 헤드리스 자동화 영역은 겨냥하지 않습니다.
5. 반응(별, 이슈, 설치 수)을 지표로 삼아 투자 규모를 단계적으로 조정합니다.

## 관련 페이지

- [수요 조사](../analyses/demand-research.md)
- [접근 방식 비교](../analyses/approach-comparison.md)
- [PHP 실행 환경의 제약](php-runtime-constraints.md)
- [로드맵](../reference/roadmap.md)
