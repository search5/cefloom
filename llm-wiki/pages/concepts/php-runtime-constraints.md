---
title: PHP 실행 환경의 제약
type: concept
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/experiments/ffi-precheck/run.sh
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/lifecycle-and-message-loop.md
updated: 2026-10-12
---

# PHP 실행 환경의 제약

CEF는 이벤트 루프와 오래 사는 프로세스를 전제로 합니다. PHP는 요청 단위 실행을 전제로 만들어진 언어입니다. 이 차이에서 생기는 제약을 확인된 것과 추정을 나누어 적습니다.

## 확인된 환경 (2026-10-12)

| 항목 | 값 |
| --- | --- |
| PHP | 8.5.4 CLI, NTS 빌드(`php -v`의 `(NTS)`) |
| 설치 방법 | Ubuntu 패키지 `php8.5-cli`, `php8.5-dev` (`apt`) |
| FFI | `php8.5-cli`에 포함되어 `php -m`에 `FFI`가 나옵니다. 별도 `php-ffi` 패키지는 후보가 없었습니다. |
| `ffi.enable` 기본값 | `preload`. 검증은 `-d ffi.enable=1`로 실행했습니다. |
| 운영체제 | Linux 6.17, 가상 X 서버(Xvfb)에서 실행 |

## 제약

### CLI SAPI 전용 (설계 판단)

PHP-FPM이나 Apache 모듈 환경은 요청마다 스크립트가 시작되고 끝나며, 워커 프로세스를 여러 개 두는 구조입니다. CEF는 프로세스당 한 번만 초기화할 수 있고(`cefweaver`의 위키가 `CefApp` 문서를 근거로 적음), 메시지 루프를 계속 돌려야 합니다. 그래서 데스크톱 앱 용도의 CLI로 한정하는 것이 맞다고 판단합니다. 서버 환경에서 실제로 어떻게 실패하는지는 시험하지 않았습니다(추정).

### NTS와 ZTS

검증은 NTS 빌드에서만 했습니다. ZTS 빌드에서는 스레드마다 별도의 엔진 상태가 있으므로 동작이 다를 수 있습니다. ZTS 지원 여부는 미정이며 배포 행렬의 크기에 영향을 줍니다([배포 전략](distribution-strategy.md)).

### 다른 스레드에서의 PHP 실행은 치명적이다

[스레드와 메시지 루프](threading-and-message-loop.md)가 다룹니다. 확인된 것은 임의 스레드에서 PHP 클로저가 실행되면 스택 한도 오류와 세그멘테이션 오류로 종료된다는 점입니다.

### 콜백 안의 예외는 프로세스를 종료시킨다

FFI에서 확인한 사실이며, 확장에서는 트램펄린이 예외를 잡아야 합니다([규칙 4](threading-and-message-loop.md)).

### 종료와 정리

스크립트가 비정상 종료되거나 PHP가 객체를 파괴하는 순서가 `cef_shutdown()`의 요구 순서와 어긋나면 세그멘테이션 오류가 날 수 있습니다. `cefweaver`가 Python에서 이런 오류를 겪고 고친 기록이 있습니다(`cefweaver_clone/cefweaver/llm-wiki/pages/concepts/lifecycle-and-message-loop.md`). PHP의 요청 종료 단계(RSHUTDOWN)와 객체 파괴 순서는 이번 조사에서 확인하지 못했습니다.

### FFI 사전 검증에서 얻은 PHP 쪽 사실 (확장에는 직접 해당하지 않음)

- FFI에서 구조체의 함수 포인터 필드는 `$obj->field(...)`로 호출할 수 없고 `($obj->field)($obj, ...)`로 호출해야 합니다.
- PHP 클로저를 콜백으로 대입하면 클로저와 구조체를 PHP 변수에 계속 보관해야 해제되지 않습니다(검증 스크립트가 `$keep` 배열을 씀).
- `pack('U')`는 PHP에 없습니다. UTF-16 문자열을 PHP 문자열로 바꿀 때 직접 변환했습니다.
- FFI 콜백은 `FFI::cast`의 타입 이름을 해당 `FFI` 인스턴스가 정의한 이름 안에서만 찾습니다(`$ffi->cast(...)`가 맞고 정적 `FFI::cast`로는 CEF 타입을 찾지 못함).

## 관련 페이지

- [스레드와 메시지 루프](threading-and-message-loop.md)
- [수명 관리](object-lifetime.md)
- [환경 준비 절차](../procedures/setup-php-environment.md)
- [주의 사항](../reference/cautions.md)
