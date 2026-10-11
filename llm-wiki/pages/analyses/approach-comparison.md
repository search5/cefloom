---
title: 구현 방식 비교 (C 확장, FFI, 혼합)
type: analysis
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefweaver_clone/cefweaver/CLAUDE.md
updated: 2026-10-12
---

# 구현 방식 비교 (C 확장, FFI, 혼합)

2026-10-12 대화에서 세 가지 방식을 비교했고, 사용자가 **C 확장을 선택**했습니다([설계 결정 D1](../reference/design-decisions.md)). 이 페이지는 비교 내용과, 사전 검증 뒤 달라진 판단을 기록합니다. 기간은 추정입니다.

## 비교

| 항목 | A. C 확장 (선택) | B. 순수 FFI (Composer 패키지) | C. 혼합 (FFI + 작은 네이티브 라이브러리) |
| --- | --- | --- | --- |
| 설치 | PHP 버전과 플랫폼별 바이너리 또는 직접 빌드 | `composer require` + 라이브러리 내려받기 | `composer require` + 네이티브 라이브러리 내려받기 |
| 네이티브 코드 | 많음(확장 전체) | 없다고 생각했으나 **필요함**(아래) | 적음(참조 카운트, 펌프 예약 등 소수 함수) |
| 콜백 안정성 | 직접 제어 | 임의 스레드 콜백을 PHP가 못 받음 | 네이티브 부분이 흡수 |
| PHP 버전 의존 | 있음(ABI) | 없음 | 없음(라이브러리는 PHP와 무관) |
| 추정 기간(Linux 첫 버전) | 8주에서 13주 | 4주에서 8주 | 5주에서 9주 (이 페이지에서 새로 어림한 값이며 근거가 약함) |
| 성능과 제어 | 가장 좋음 | 호출 비용이 큼 | 중간 |

## 사전 검증으로 달라진 판단

처음 대화에서는 "CEF 콜백이 PHP 스레드 밖에서 오면 FFI가 불안정하다"는 이유로 FFI를 권장하지 않았다가, 이후 `multi_threaded_message_loop`를 끄면 콜백이 메인 스레드에서 실행되므로 가능할 수 있다고 정정했습니다. 그 정정이 맞는지를 사전 검증이 확인했고, 결과는 다음과 같습니다. FFI를 "네이티브 코드가 거의 필요 없고 `composer require`만으로 설치된다"고 설명했던 부분도 아래 2번에서 다시 고칩니다.

1. **메인 스레드 콜백은 FFI로 받을 수 있습니다.** `multi_threaded_message_loop`를 끄면 핸들러 콜백이 PHP 메인 스레드에서 실행되었습니다([F8](../reference/verified-findings.md)). 처음의 우려(콜백이 PHP 스레드 밖에서 오면 불안정)는 이 부분에서는 해소되었습니다.
2. **그러나 순수 FFI는 불가능합니다.** 참조 카운트 함수와 `on_schedule_message_pump_work`가 임의 스레드에서 호출되고, PHP 클로저로는 받을 수 없었습니다(F3, F5). 사전 검증은 작은 C 라이브러리(`refshim.c`)로 해결했습니다. 따라서 B는 실제로는 C(혼합)가 됩니다.
3. 혼합 방식의 네이티브 라이브러리는 PHP ABI와 무관한 일반 C 공유 라이브러리입니다. PHP 버전과 NTS/ZTS별로 빌드할 필요가 없고, 플랫폼별 빌드만 하면 됩니다. 이 점은 C 확장보다 배포 행렬이 작다는 뜻입니다(해석).

## 선택에 대한 기록

- 사용자는 C 확장을 선택했습니다. 이유는 대화에 명시되지 않았고, 사전 검증만 FFI로 하라는 지시가 있었습니다.
- 배포와 사용자 확보가 목표인 점을 고려하면 C(혼합)의 설치 이점이 큽니다. 이 비교는 방식을 바꾸자는 제안이 아니라, 결정의 위험을 기록하는 것입니다. 결정을 재검토하려면 [배포 전략](../concepts/distribution-strategy.md)과 [로드맵](../reference/roadmap.md)을 함께 보십시오.
- 혼합 방식의 전제인 "FFI로 핸들러 수십 개를 구현하는 비용"은 평가하지 못했습니다. 사전 검증은 핸들러 7종만 구현했습니다.

## 점진적 선택지

사전 검증 자료(`experiments/ffi-precheck/`)를 정리해 공개하고 반응을 보는 것이 가장 싸게 수요를 확인하는 방법입니다. 반응이 있으면 투자를 늘리고, 없으면 C 확장의 큰 비용을 쓰지 않게 됩니다([수요 조사](demand-research.md)).

## 관련 페이지

- [설계 결정 기록](../reference/design-decisions.md)
- [로드맵](../reference/roadmap.md)
- [스레드와 메시지 루프](../concepts/threading-and-message-loop.md)
