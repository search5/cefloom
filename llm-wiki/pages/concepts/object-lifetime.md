---
title: 참조 카운트와 수명 관리
type: concept
sources:
  - cef_origin/include/capi/cef_base_capi.h
  - cef_origin/include/cef_base.h
  - cefloom/experiments/ffi-precheck/refshim.c
  - cefloom/experiments/ffi-precheck/verify.php
updated: 2026-10-12
---

# 참조 카운트와 수명 관리

CEF의 모든 객체는 참조 카운트 방식으로 수명이 정해집니다. PHP 객체의 수명과 CEF 객체의 수명을 어떻게 맞출지가 확장 설계의 핵심 과제입니다. 이 페이지는 확인된 사실과 설계 제안을 구분해서 적습니다.

## CEF의 규칙 (헤더에서 확인)

C API에서 모든 객체는 `cef_base_ref_counted_t`로 시작합니다(`cef_origin/include/capi/cef_base_capi.h`). 구조체의 `size`와 함수 포인터 네 개(`add_ref`, `release`, `has_one_ref`, `has_at_least_one_ref`)로 이루어집니다. C++ API의 `CefBaseRefCounted`와 `CefRefPtr<T>`가 같은 규칙을 감싼 것입니다(`cef_origin/include/cef_base.h`).

- 응용이 구현하는 객체(핸들러, `CefApp` 등)는 응용이 이 함수들을 구현합니다. CEF가 `add_ref`와 `release`를 호출합니다.
- CEF가 구현하는 객체(`CefBrowser`, `CefFrame` 등)는 응용이 `add_ref`로 보관하고 다 쓰면 `release`합니다.
- 헤더의 `add_ref` 설명은 "포인터의 새 사본마다 호출해야 한다"이고, `release` 설명은 "카운트가 0이 되면 객체가 스스로 삭제되어야 한다"입니다.
- 핸들러의 `get_*` 계열 함수가 돌려주는 포인터도 새 사본이므로 같은 규칙이 적용된다고 **해석**합니다. 이 해석은 헤더의 `get_*` 설명에 명시되어 있지 않아서 CEF 소스(`cef_origin/libcef_dll/ctocpp`)로 확인해야 합니다. 사전 검증에서는 카운트를 세지 않는 구현이라 이 규약의 위반이 드러나지 않았습니다([알려진 제약](../reference/known-constraints.md)).

## 확인된 사실

- `add_ref`와 `release` 등은 **CEF 내부의 여러 스레드에서** 호출됩니다. 창 모드 한 번의 실행에서 375회 중 16회가 PHP 메인 스레드 밖이었습니다([F4](../reference/verified-findings.md)).
- 이 함수를 PHP 클로저로 만들면 `cef_initialize()` 도중에 PHP가 스택 한도 오류를 내고 종료합니다([F3](../reference/verified-findings.md)). 네이티브 함수 포인터를 대입하는 방식은 동작합니다.
- 사전 검증의 shim은 `release`가 0을 돌려주고 객체를 해제하지 않았습니다. **따라서 실제 해제와 소멸 순서는 한 번도 시험하지 못했습니다**([알려진 제약](../reference/known-constraints.md)).

## 설계 제안

### 1. 참조 카운트는 네이티브 클래스가 구현한다

응용이 구현하는 객체는 `std::atomic` 카운터로 `add_ref`와 `release`를 구현한 C++ 클래스(C++ API라면 `IMPLEMENT_REFCOUNTING`)로 만듭니다. PHP를 호출하지 않습니다.

### 2. PHP 객체는 CEF 객체에 참조 하나를 쥔다

CEF가 구현하는 객체를 감싸는 PHP 객체(Zend 객체)는 생성 시 참조 하나를 보관하고, `free_obj` 핸들러에서 놓습니다. 이 해제는 PHP 메인 스레드에서만 일어납니다.

### 3. 핸들러가 쥔 PHP 값은 메인 스레드에서만 건드린다

PHP에서 구현한 핸들러는 사용자 객체(`zval`)를 보관합니다. 핸들러의 마지막 `release`가 CEF의 다른 스레드에서 일어날 수 있으므로, 소멸자는 PHP 값을 직접 해제하지 말고 메인 스레드의 정리 큐에 넣어야 합니다. 큐는 `cef_do_message_loop_work()` 호출 전후에 PHP 루프가 비웁니다.

### 4. 순환 참조와 GC

핸들러가 브라우저를, 브라우저가 클라이언트를, 클라이언트가 핸들러를 가리키는 순환이 쉽게 생깁니다. PHP의 순환 GC가 이 순환을 끊지 못하게 되는 경우를 막으려면, 브라우저 종료 통지(`on_before_close`)에서 핸들러가 쥔 참조를 명시적으로 놓는 규칙이 필요합니다. 이것도 구현 전입니다.

### 5. 종료 순서

`cefweaver`는 브라우저 참조를 놓기 전에 `CefShutdown()`을 부르면 세그멘테이션 오류가 났다고 기록합니다(`cefweaver_clone/cefweaver/llm-wiki/pages/concepts/lifecycle-and-message-loop.md`). 확장에서는 PHP 객체가 파괴되는 순서가 이 순서와 맞는지 보장해야 합니다. `cefweaver`는 `shutdown()` 없이 `CefApp` 객체가 사라지는 경우 C++ 객체를 일부러 해제하지 않고 남겨 둔다고 기록합니다. 비슷한 안전장치가 필요할 수 있습니다.

## 관련 페이지

- [스레드와 메시지 루프](threading-and-message-loop.md)
- [주의 사항](../reference/cautions.md)
- [검증 하네스](../components/ffi-precheck-harness.md)
