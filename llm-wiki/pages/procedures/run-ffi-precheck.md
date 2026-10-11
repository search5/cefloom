---
title: 사전 검증 재현 절차
type: procedure
sources:
  - cefloom/experiments/ffi-precheck/run.sh
  - cefloom/experiments/ffi-precheck/verify.php
updated: 2026-10-12
---

# 사전 검증 재현 절차

[FFI 사전 검증 하네스](../components/ffi-precheck-harness.md)를 처음부터 다시 실행하는 방법입니다. 사전 조건은 [PHP 개발 환경 준비](setup-php-environment.md)입니다.

## 실행

```sh
cd cefloom/experiments/ffi-precheck
./run.sh            # 창 모드
./run.sh osr        # 오프스크린 모드
```

- 산출물(선언 파일, shim 라이브러리, 캐시, CEF 로그)은 `OUT`이 지정하지 않으면 임시 디렉터리에 만들어집니다.
- 기본 런타임은 `cefweaver_clone/cefweaver/build/lib.linux-x86_64-cpython-313/cefweaver`, 기본 헤더는 `cefweaver_clone/cefweaver/build/native/cef`입니다. 다르면 환경 변수 `RT`, `CEF_ROOT`로 지정합니다.
- `TIMEOUT`(초, 기본 30)이 전체 제한 시간입니다.
- 가상 X 서버(`xvfb-run`)에서 `WAYLAND_DISPLAY`를 해제하고 실행합니다. 실제 화면에 창이 열리지 않게 하려는 것입니다.

## 기대 결과

창 모드에서 아래 항목이 모두 `[PASS]`이고 종료 코드가 0입니다.

- libcef 로드와 API 해시, `cef_execute_process`가 -1 반환, `cef_initialize` 성공, 브라우저 생성
- life span 통지, `on_load_end`, `on_context_initialized`
- PHP에서 실행한 JS의 console 출력이 PHP 콜백으로 도착
- `on_schedule_message_pump_work` 수신(횟수와 메인 스레드 밖 횟수 출력)
- 참조 카운트 콜백의 호출 스레드(참고)
- 모든 콜백이 PHP 메인 스레드에서 호출

오프스크린 모드는 `on_paint` 수신 항목이 추가됩니다. JS 결과를 받은 뒤 첫 페인트까지 기다려야 통과합니다(스크립트가 그렇게 되어 있습니다).

## 실패할 때 확인할 것

- `php: 명령을 찾을 수 없음`이면 [환경 준비](setup-php-environment.md)가 안 된 것입니다.
- `build/` 디렉터리가 정리되었으면 런타임과 헤더가 없습니다. `cefweaver`를 다시 빌드하거나 `RT`, `CEF_ROOT`를 다른 CEF 배포본으로 지정합니다. 단, 헤더의 CEF 버전과 `libcef.so`의 버전이 같아야 구조체 크기가 맞습니다.
- 스크립트가 PHP 치명 오류로 끝나면 콜백 안에서 예외가 난 것입니다. 오류 메시지의 줄 번호가 `verify.php`의 콜백을 가리킵니다.
- CEF 로그는 `OUT/work/cef.log`에 남습니다. 샌드박스와 DBus 관련 경고(`org.freedesktop.host.portal.Registry` 등)와 SSL 핸드셰이크 오류는 실행 중에 나타났으나 결과에 영향이 없었습니다.

## 관련 페이지

- [실험으로 확인한 사실](../reference/verified-findings.md)
- [알려진 제약과 미검증 항목](../reference/known-constraints.md)
