---
title: NativePHP Desktop 조사
type: analysis
sources:
  - https://github.com/NativePHP/desktop
  - https://nativephp.com/docs/desktop/2/getting-started/introduction
  - https://packagist.org/packages/NativePHP/desktop
updated: 2026-10-12
---

# NativePHP Desktop 조사

`nativephp/desktop`이 무엇인지 확인한 내용입니다. 조회일은 2026-10-12이며, 페이지에 적혀 있지 않은 부분은 확인하지 못했다고 밝힙니다.

## 확인된 내용

- **성격**: 저장소 소개는 "Create desktop applications with PHP"이고 라이선스는 MIT입니다.
- **규모(조회 시점)**: 커밋 1,148개, 별 448개, 포크 53개, 열린 이슈 15개, 열린 풀 리퀘스트 7개.
- **메인테이너**: Simon Hamp, Marcel Pociot. 후원사로 Bifrost, Laradevs가 적혀 있습니다.
- **구성**: 공식 문서에 따르면 (1) 운영체제 기능에 접근하는 PHP 클래스, (2) 앱을 빌드하고 묶는 도구, (3) 정적 PHP 런타임(사용자 PC에 PHP가 없어도 동작)을 함께 제공합니다.
- **UI**: 일반 웹 기술로 만듭니다. React, Vue, Livewire, Inertia, 순수 HTML과 CSS를 쓸 수 있습니다.
- **기능**: 창과 메뉴 관리, 파일 관리, SQLite, 네이티브 알림, 대화상자, 전역 단축키, 클립보드, 셸, 전원 상태 감시, 큐, 배포.
- **설치**: `composer require`로 설치하며 별도의 PHP 확장이 필요 없다고 적혀 있습니다.
- **Packagist**: 최신 2.2.1(2026-05-29). 누적 설치 수는 [수요 조사](demand-research.md)에 있습니다.

## 확인하지 못한 내용

- **런타임 구조**: 문서 본문에 구조 설명이 없고 크레딧에 Electron이 언급될 뿐입니다. 저장소에 `electron` 토픽과 `package-lock.json`이 있어서 Electron 기반일 가능성이 높다고 추정하지만 문서로 확인하지는 못했습니다(K21).
- **지원 플랫폼**: 구체적인 운영체제 목록이 페이지에 없었습니다.
- **`nativephp/laravel`, `php-bin`과의 관계**: 두 페이지 모두 설명이 없었습니다. `nativephp/laravel` 저장소가 보관 처리되고 `nativephp/desktop`이 이어받은 것으로 보이며, `php-bin`은 이름으로 미루어 번들 PHP 바이너리 패키지로 추정합니다.

## cefloom과의 관계

NativePHP는 Laravel 앱을 데스크톱 앱으로 포장하는 **완제품**이고, cefloom은 PHP에서 Chromium을 직접 제어하는 **저수준 라이브러리**입니다. 대상 사용자층은 겹치지만 역할이 달라서 경쟁보다 보완 관계를 생각해 볼 수 있습니다.

- NativePHP가 Electron에 의존한다면, cefloom은 Node.js 없이 PHP 단일 런타임으로 동작하는 대안이 될 수 있습니다(차별점 후보).
- NativePHP가 이미 빌드와 배포 경험(Laravel 통합, 패키징)을 제공하므로, 같은 용도로 만든 바인딩은 설득력이 약합니다. 오프스크린 렌더링이나 네트워크 가로채기처럼 NativePHP가 제공하지 않는 제어를 앞세워야 합니다.
- 구조를 정확히 알려면 저장소의 `package.json`과 Electron 관련 디렉터리를 직접 읽어야 합니다. 이번에는 하지 않았습니다.

## 관련 페이지

- [수요 조사](demand-research.md)
- [배포 전략](../concepts/distribution-strategy.md)
