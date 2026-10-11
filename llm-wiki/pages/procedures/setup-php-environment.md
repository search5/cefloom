---
title: PHP 개발 환경 준비
type: procedure
sources:
  - cefloom/experiments/ffi-precheck/run.sh
  - cefweaver_clone/cefweaver/CLAUDE.md
updated: 2026-10-12
---

# PHP 개발 환경 준비

2026-10-12에 이 컴퓨터(Ubuntu, Linux 6.17)에서 실제로 수행한 절차입니다. 다른 컴퓨터에서는 패키지 이름과 버전이 다를 수 있습니다.

## 설치한 것

```sh
sudo apt-get install -y php8.5-cli php8.5-dev
```

- 설치 전에는 `php`, `php-config`, `phpize`가 모두 없었습니다.
- 설치 후 `php -v`는 `PHP 8.5.4 (cli) (built: Sep 2 2026 14:42:18) (NTS)`를 보였습니다.
- `php -m`에 `FFI`가 나옵니다. 별도 `php-ffi` 패키지는 후보가 없었습니다.
- `php8.5-dev`는 확장 모듈을 만들 때 필요한 `phpize`, `php-config`와 헤더를 제공합니다. 사전 검증에는 필요하지 않았고, 확장 골격을 시작할 때 씁니다.

**시스템에 설치된 패키지입니다.** 제거하려면 다음을 실행합니다.

```sh
sudo apt remove php8.5-cli php8.5-dev
```

## 확인한 도구

- `gcc`, `g++`, `cmake`, `xvfb-run`(Xvfb)이 이미 설치되어 있었습니다.
- Python은 `mkcdef.py` 실행에 씁니다(`python3 -I`).
- CEF 런타임과 헤더는 `cefweaver`의 빌드 결과를 씁니다. 없으면 `cefweaver_clone/cefweaver`에서 `python tools/prepare.py`로 확보하는 절차가 `CLAUDE.md`에 있습니다(이번에는 실행하지 않았습니다).

## FFI 사용 시 실행 옵션

`ffi.enable` 기본값이 `preload`이므로 CLI 스크립트에서는 `-d ffi.enable=1`을 주고 실행했습니다. `run.sh`가 이 옵션을 포함합니다.

## 관련 페이지

- [사전 검증 재현 절차](run-ffi-precheck.md)
- [PHP 실행 환경의 제약](../concepts/php-runtime-constraints.md)
