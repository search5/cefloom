---
title: 설계 결정 기록
type: reference
sources:
  - cefloom/experiments/ffi-precheck/verify.php
  - cefloom/ext/src/async.cc
  - cefloom/tools/gen/scope.py
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/process-model-and-threads.md
updated: 2026-10-12
---

# 설계 결정 기록

결정마다 상태를 적습니다. **확정**은 사용자가 정한 것, **근거 확인**은 사전 검증으로 뒷받침된 것, **제안**은 아직 정해지지 않은 것, **기본값**은 지정이 없어 가정한 값입니다. 결정이 바뀌면 이 페이지를 고치고 log.md에 기록합니다.

## D1. 제품 방식은 C++ PHP 확장 모듈이다

- 상태: **확정** (2026-10-12, 사용자)
- 내용: 구현은 C++ 확장 모듈과 코드 생성입니다. FFI는 이 방식의 전제를 확인하는 사전 검증에만 씁니다.
- 이유: 콜백 안정성과 성능을 직접 제어할 수 있고, 사전 검증을 통해 FFI가 아닌 네이티브 코드가 필요한 지점이 드러났기 때문입니다([F3, F5](verified-findings.md)).
- 대안: FFI 기반 Composer 패키지. 설치 장벽이 낮고 개발 기간이 짧습니다([접근 방식 비교](../analyses/approach-comparison.md)).
- 위험: 설치 장벽이 배포 목표와 어긋납니다([배포 전략](../concepts/distribution-strategy.md)). 반응을 본 뒤 FFI 프런트엔드를 추가하는 선택지를 열어 둡니다.

## D2. 단일 스레드 모델과 외부 메시지 펌프

- 상태: **근거 확인** (F6, F8)
- 내용: PHP 메인 스레드가 UI 스레드이고, `multi_threaded_message_loop`를 끄고 `external_message_pump`를 켠 채 PHP 루프가 `cef_do_message_loop_work()`를 호출합니다.
- 대안: CEF가 별도 UI 스레드를 돌리는 `multi_threaded_message_loop`. 모든 핸들러가 PHP가 아닌 스레드에서 실행되므로 PHP 비안전성 때문에 채택하지 않습니다(해석).

## D3. 참조 카운트와 펌프 예약은 네이티브 구현이다

- 상태: **근거 확인** (F3, F4, F5)
- 내용: PHP를 건드리지 않는 원자적 연산으로 구현합니다.

## D4. 확장은 CEF C++ API에 붙는다

- 상태: **제안**
- 이유: 참조 카운트와 핸들러 구현을 클래스로 한 번만 구현할 수 있고, 재사용 후보(`cefweaver`, `java-cef`)가 모두 이 방식입니다.
- 대안: C API 직접 사용. 사전 검증이 이 방식이었으나 FFI의 제약 때문이었습니다([목표 아키텍처](../concepts/target-architecture.md)).

## D5. 서브프로세스는 PHP 없는 별도 실행 파일이다

- 상태: **근거 확인** (F6: `cefweaver`의 서브프로세스로 동작)
- 내용: `browser_subprocess_path`로 지정합니다. 렌더러 쪽 JS 브리지 코드는 이 실행 파일에 들어갑니다.

## D6. JS 브리지는 JSON 기반 호출이 기본이다

- 상태: **제안** (구현과 시험 모두 없음)
- 내용: 메시지 라우터 위의 JSON 질의와 응답을 기본으로 하고, `window.name(...)` 같은 단순 형태는 편의 기능으로 둡니다. 근거는 `cefweaver`의 두 가지 JS 연동 경험입니다([프로세스 모델과 JS 브리지](../concepts/process-model-and-js-bridge.md)).

## D7. CLI 전용

- 상태: **제안** (설계 판단, 서버 환경 시험은 하지 않음)

## D8. Linux 우선, PHP 8.3 이상

- 상태: **기본값** (사용자가 지정하지 않음)
- 이유: 검증 환경이 Linux이고, `cefweaver`가 세 플랫폼을 지원하므로 후속 확장이 가능합니다. 8.3은 대표 지원 버전을 좁게 잡기 위한 가정입니다.

## D9. 생성기는 `cefweaver`의 파이프라인을 재사용 후보로 한다

- 상태: **제안**
- 미정: 복사, 공유, 참조만 중에서 어떤 방식으로 재사용할지.

## D10. 위키의 `sources`는 작업 영역 기준 경로이다

- 상태: **확정** (이 위키의 규칙, [SCHEMA.md](../../SCHEMA.md))
- 이유: 원본이 형제 프로젝트에 있고 `cefloom`이 독립된 저장소가 아니기 때문입니다.

## D11. 사전 검증 자료는 `cefloom/experiments/`에 보관한다

- 상태: **확정**
- 이유: 임시 디렉터리에 둔 스크립트는 세션이 끝나면 사라질 수 있고, 위키의 사실이 재현 가능한 근거를 가져야 하기 때문입니다.

## D12. IO 스레드의 핸들러는 PHP를 기다리지 않는다

- **상태**: 확정(사용자 결정). 구현과 반복 검증을 마쳤습니다.
- **내용**: CEF의 IO 스레드에서 불리는 핸들러는 즉시 답하고, PHP는 UI 스레드(PHP 메인 스레드)에서 나중에 실행하며, 결과는 CEF가 제공하는 콜백으로 전달합니다. 근거는 [F14](verified-findings.md)입니다.
- **비동기로 바꾼 것**: `ResourceHandler`의 `open`, `processRequest`, `getResponseHeaders`, `skip`, `read`, `cancel`, `SchemeHandlerFactory::create`, `ResourceRequestHandler`의 `onBeforeResourceLoad`와 `onResourceLoadComplete`, `RequestHandler::getAuthCredentials`. PHP에서 보이는 메서드 이름과 반환 형식은 그대로입니다. `getResponseHeaders`는 요청을 열 때 미리 PHP를 실행해 결과를 저장했다가 IO 스레드에서 즉시 돌려줍니다.
- **그대로 PHP를 기다리는 것(사용자 결정)**: 즉시 반환값이 필요한 콜백입니다. `getResourceRequestHandler`(`disableDefaultHandling`을 즉시 알려야 함), `getResourceHandler`(`null`이면 네트워크 로드), `getCookieAccessFilter`, `canSendCookie`, `canSaveCookie`, `onResourceResponse`, `onResourceRedirect`, `onProtocolExecution`, 응답 필터(`getResourceResponseFilter`, `initFilter`, `filter`), 그리고 구형 `readResponse`. 이 콜백을 PHP에서 구현하면 IO 스레드가 막히므로 [F14](verified-findings.md)의 교착이 일어날 수 있습니다.
- **영향**: 생성기는 `tools/gen/scope.py`의 `CUSTOM_PROXY`(프록시를 직접 작성하는 클래스)와 `ASYNC_METHODS`(비동기 본문으로 위임하는 메서드)를 따릅니다.

## 미결 질문

- 확장 코드의 위치와 이름(예: `cefloom/ext/`).
- CEF API 버전을 안정 버전에 고정할지([주의 사항](cautions.md) 13번).
- 샌드박스를 켤지.
- ZTS를 지원할지.
- 첫 공개 범위(핸들러 수, 플랫폼).

## 관련 페이지

- [프로젝트 목표와 범위](../concepts/project-goals.md)
- [로드맵](roadmap.md)
- [주의 사항](cautions.md)
