---
title: 프로세스 모델과 JS 브리지
type: concept
sources:
  - cefweaver_clone/cefweaver/llm-wiki/pages/concepts/javascript-bindings.md
  - cefweaver_clone/cefweaver/llm-wiki/pages/reference/javascript-bridge.md
  - cefweaver_clone/cefweaver/native/cefwrapper/javascript_binding.h
  - cefweaver_clone/cefweaver/native/cefwrapper/cef_wrapper_render_process_handler.cc
  - java-cef/native/CefMessageRouter_N.cpp
  - cefpython/src/javascript_bindings.pyx
  - cefloom/experiments/ffi-precheck/verify.php
updated: 2026-10-12
---

# 프로세스 모델과 JS 브리지

목표 G5는 JS에서 PHP 함수를 부르는 단순한 API를 핵심 기능으로 두는 것입니다. 이 페이지는 기존 구현에서 확인한 구조와, 그것을 PHP에 옮기는 제안을 적습니다. **PHP에서의 JS 브리지는 구현도 시험도 하지 않았습니다.**

## 왜 PHP를 렌더러에 둘 수 없는가

V8 컨텍스트는 렌더러 프로세스에 있고, 렌더러는 브라우저 프로세스와 다른 프로세스입니다. 서브프로세스는 PHP를 로드하지 않는 C++ 실행 파일이므로([목표 아키텍처](target-architecture.md)) JS가 PHP 함수를 직접 부를 수 없습니다. 렌더러가 프로세스 메시지로 브라우저 프로세스(PHP)에 요청을 보내야 합니다.

## 기존 구현이 쓰는 흐름 (cefweaver, 읽어서 확인)

`cefweaver`의 Python 바인딩은 여섯 단계를 거칩니다(`cefweaver_clone/cefweaver/llm-wiki/pages/concepts/javascript-bindings.md`).

1. 등록: 이름과 콜백을 브라우저 프로세스 쪽에 모읍니다.
2. 브라우저 생성: 바인딩 **이름 목록**만 `extra_info`에 담아 브라우저에 붙입니다.
3. 렌더러에서 `OnContextCreated`가 V8 전역 객체에 이름마다 함수를 만듭니다.
4. JS가 함수를 호출하면 렌더러가 인자를 프로세스 메시지에 담아 브라우저 프로세스로 보냅니다.
5. 브라우저 프로세스의 UI 스레드가 `OnProcessMessageReceived`로 받아 값을 풀어 핸들러를 부릅니다.
6. 언어 쪽 콜백(Python 대신 PHP)이 실행됩니다.

### 이 구조에서 얻은 교훈

- **이름만 보냅니다.** 초기 구현이 함수 포인터를 품은 객체를 렌더러에 복사했다가 다른 프로세스에서 유효하지 않은 주소를 가리켜 JS 콜백이 전혀 동작하지 않았습니다.
- **값의 종류는 제한됩니다.** `int`, `bool`, `double`, `string`만 전달하고 반환값은 지원하지 않습니다. 목록, 사전, `None`, 반환값(`Promise`)은 메시지 라우터 위의 JSON으로 하는 `JavascriptBridge`가 따로 있습니다(`cefweaver_clone/cefweaver/llm-wiki/pages/reference/javascript-bridge.md`).
- **호출한 프레임으로 보냅니다.** 교차 사이트 iframe은 다른 렌더러 프로세스에 있어서 `browser->GetMainFrame()`이 널이며, 메인 프레임으로 보내면 렌더러가 죽었습니다(`cefweaver` 위키의 F57).

## 다른 구현

- `java-cef`는 메시지 라우터(`CefMessageRouter`)를 JNI로 감쌉니다(`java-cef/native/CefMessageRouter_N.cpp`). JS의 `window.cefQuery`로 요청을 보내고 Java 쪽 핸들러가 응답하는 방식으로 알려져 있습니다. 내부를 이번 조사에서 끝까지 읽지는 않았습니다.
- `cefpython`은 `src/javascript_bindings.pyx`에서 Python 객체를 JS에 노출하는 별도 방식을 씁니다. 역시 끝까지 읽지 않았습니다.

PHP 설계에서 어느 쪽을 참고할지는 구현 전에 두 파일을 정독한 뒤 정합니다([로드맵](../reference/roadmap.md)).

## PHP용 제안

1. **기본 API는 JSON 기반의 호출 브리지**로 합니다. 메시지 라우터의 질의와 응답 위에서 JS의 `cefloom.call(name, args)`가 `Promise`를 돌려주고, PHP는 `$app->expose('name', fn(array $args) => ...)`로 함수를 등록합니다(이름은 가칭). PHP 배열은 JSON으로 변환합니다.
2. 단순 형태(`window.name(...)`)는 편의 기능으로 얹을 수 있습니다. 반환값이 없는 호출이며 `cefweaver`의 Python 바인딩과 같은 제약을 가집니다.
3. 콜백은 UI 스레드(PHP 메인 스레드)에서 `cef_do_message_loop_work()` 호출 안에서 실행되므로 [스레드 규칙](threading-and-message-loop.md)을 그대로 따릅니다.
4. 렌더러 쪽 코드는 PHP 없이 C++로 서브프로세스에 들어갑니다. 서브프로세스 실행 파일에 포함되는 코드이므로 확장 모듈과 별도로 빌드하고 배포해야 합니다([배포 전략](distribution-strategy.md)).

## 확인한 것과 확인하지 못한 것

- 확인: PHP에서 실행한 `console.log`가 PHP 콜백(`on_console_message`)으로 돌아옵니다([F7](../reference/verified-findings.md)). 즉 PHP에서 JS로 가고 다시 PHP 콜백으로 돌아오는 경로는 확인했습니다.
- 미확인: 렌더러 프로세스를 거치는 JS에서 PHP 방향의 호출. 사전 검증은 `cefweaver`의 서브프로세스를 그대로 지정했을 뿐 렌더러 쪽 코드를 새로 만들지 않았습니다.

## 관련 페이지

- [목표 아키텍처](target-architecture.md)
- [스레드와 메시지 루프](threading-and-message-loop.md)
- [입력 소스 프로젝트](../components/source-projects.md)
- [알려진 제약과 미검증 항목](../reference/known-constraints.md)
