---
title: PHP용 CEF 바인딩의 수요 조사
type: analysis
sources:
  - https://dev.to/dev_olatunji/how-to-build-and-distribute-desktop-apps-using-php-and-phpdesktop-a-step-by-step-guide-1g80
  - https://packagist.org/packages/chrome-php/chrome
  - https://root.packagist.org/packages/nativephp/laravel/stats
  - https://root.packagist.org/packages/nativephp/php-bin/stats
  - https://packagist.org/packages/NativePHP/desktop
  - https://packagist.org/packages/kingbes/pebview
  - https://root.packagist.org/packages/developersharif/php-gui
updated: 2026-10-12
---

# PHP용 CEF 바인딩의 수요 조사

"PHP용 CEF 바인딩이 많이 쓰일 것인가"를 웹 검색으로 확인했습니다. 조회일은 2026-10-12이며 모든 수치는 **스냅샷**입니다. 사이트마다 값이 다르고 갱신 시점도 다릅니다. 직접 접속한 저장소 페이지가 아니라 검색에 노출된 목록 페이지가 대부분입니다.

## 결론 (먼저)

수요가 **존재는 하지만 규모가 작다**고 판단합니다. 이유는 다음과 같습니다.

1. 현재 CEF 기반 PHP 바인딩은 사실상 PHP Desktop 하나뿐으로, 빈 자리가 있습니다.
2. 인접 수요(PHP로 데스크톱 앱 만들기)는 NativePHP 통계로 확인됩니다.
3. 그러나 그 수요는 Electron 계열과 시스템 웹뷰 기반 도구가 대부분 흡수했고, CEF만의 고유한 수요를 보여 주는 자료는 찾지 못했습니다(K23).
4. FFI 기반 웹뷰 패키지들의 낮은 설치 수는 이 틈새가 크지 않다는 신호로 읽는 것이 합리적입니다(해석).

## 확인된 자료

### 기존 CEF 기반 PHP 도구

- PHP Desktop은 CEF로 화면을 그리고 PHP 앱을 데스크톱 앱으로 묶어 주는 프로젝트입니다. 검색된 튜토리얼은 Windows 기준으로 설명합니다. 저장소는 `github.com/cztomczak/phpdesktop`입니다.
- CEF Python의 저자가 PHP용 바인딩도 작업 중이며 PHP Desktop을 가리킨다는 내용이 검색에 나왔습니다.
- 현재 유지보수 상태는 확인하지 못했습니다(K20).

### NativePHP (인접 수요)

| 패키지 | 누적 설치 | 최근 30일 | 비고 |
| --- | --- | --- | --- |
| `nativephp/desktop` | 1,552 | 1,541 | 최신 2.2.1(2026-05-29 게시) |
| `nativephp/laravel` | 119,671 | 3,229 | 이전 래퍼. 저장소는 2026-05-27 보관 처리 |
| `nativephp/php-bin` | 148,605 | 6,883 | 번들 PHP 바이너리 |

- GitHub 별은 `NativePHP/desktop` 저장소 페이지에서 448개(직접 조회), `nativephp/laravel` 저장소는 3.9천 개(보관 처리)입니다. 제3자 집계 사이트는 355개로 보고했습니다. 값이 다른 것은 집계 시점이 다르기 때문으로 해석합니다.
- 자세한 내용은 [NativePHP 조사](nativephp-desktop.md)에 있습니다.

### FFI 기반 PHP GUI와 웹뷰 패키지 (경쟁 후보)

| 패키지 | 내용 | 비고 |
| --- | --- | --- |
| `kingbes/pebview` | 네이티브 창에 HTML을 표시하는 웹뷰. PHP 8.2 이상, FFI | 설치 수가 적고 이를 쓰는 패키지가 하나뿐. 2026-08 갱신 |
| `developersharif/php-gui` | Tcl/Tk 위젯과 WebView 모드(WebKitGTK, WKWebView, WebView2). PHP 8.1 이상, FFI | |
| `ghostjat/gtk` | FFI로 만든 GTK. 웹뷰 위젯 포함 | README가 API 불안정이라고 밝힘 |
| `helgesverre/libui`, `yangweijie/ui2` | libui-ng 바인딩과 그 위의 계층 | PHP 8.5 이상 요구(ui2는 8.4.x에서 설치 실패) |
| `kingbes/framework` | Windows 중심 데스크톱 프레임워크 | |

이 패키지들은 시스템 웹뷰를 씁니다. Chromium을 직접 내장하는 CEF와 다릅니다. 모두 `composer require`로 설치됩니다.

### 헤드리스 자동화

- `chrome-php/chrome`은 누적 설치 약 538만 건, 별 2,667개, 의존 패키지 72개, 최신 v1.16.1(2026-07-06)입니다. 사이트에 따라 508만 건, 348만 건 등 값이 다른 스냅샷도 있었습니다. DevTools 프로토콜을 쓰는 순수 PHP 라이브러리로, 이 영역은 포화 상태입니다.

## 해석

- CEF 바인딩이 차별화될 여지는 Chromium 버전 고정, 오프스크린 렌더링, 네트워크 가로채기, DevTools 같은 세밀한 제어입니다. 이 기능이 필요한 사용자가 얼마나 되는지는 이번 조사로 알 수 없습니다.
- 수요를 직접 확인하는 가장 싼 방법은 사전 검증 자료나 최소 예제를 공개하고 반응을 보는 것입니다([접근 방식 비교](approach-comparison.md)).

## 관련 페이지

- [NativePHP 조사](nativephp-desktop.md)
- [배포 전략](../concepts/distribution-strategy.md)
- [알려진 제약과 미검증 항목](../reference/known-constraints.md)
