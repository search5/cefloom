---
title: 생성기 커버리지 보고서
type: reference
sources:
  - cefloom/tools/gen/generate.py
  - cefloom/tools/gen/scope.py
  - cefloom/tools/gen/typesys.py
updated: 2026-10-12
generated: true
---

# 생성기 커버리지 보고서

`python tools/gen/generate.py`가 만드는 페이지입니다. 직접 고치지 않습니다. 입력은 CEF 154.0.34+g14c5a08+chromium-154.0.8037.98의 헤더입니다.

## 요약

- 클래스: 라이브러리 78개, 클라이언트 60개. 구조체 25개, 열거형 100개(읽지 못한 열거형 1개), 전역 함수 40개.
- 메서드와 함수 1284개 중 생성 1262개, 손으로 작성 2개, 생성하지 못함 20개.
- 범위에서 뺀 것: 렌더러 프로세스 클래스, 소유 포인터 클래스, 테스트 헤더. 이유는 `tools/gen/scope.py`에 있습니다.

## 생성하지 못한 이유

- rawptr pointer (2): CefApp.OnRegisterCustomSchemes, CefBrowserProcessHandler.OnRegisterCustomPreferences
- CEF keeps the pointer the handler returns, so the bytes would have to outlive every use (2): CefResourceBundleHandler.GetDataResource, CefResourceBundleHandler.GetDataResourceForScale
- another overload of GetAttribute is generated (Python has one name) (2): CefXmlReader.GetAttribute, CefXmlReader.GetAttribute
- another overload of MoveToAttribute is generated (Python has one name) (2): CefXmlReader.MoveToAttribute, CefXmlReader.MoveToAttribute
- class CefV8Handler is not generated yet (1): Cef (global functions).CefRegisterExtension
- class CefRenderProcessHandler is not generated yet (1): CefApp.GetRenderProcessHandler
- a pointer into memory that CEF owns and can free while Python still holds it (get_data() copies the bytes) (1): CefBinaryValue.GetRawData
- pointer to char* const (1): CefCommandLine.InitFromArgv
- class CefV8Context is not generated yet (1): CefFrame.GetV8Context
- class CefDOMVisitor is not generated yet (1): CefFrame.VisitDOM
- reference to a CefRefPtr (1): CefLifeSpanHandler.OnBeforeDevToolsPopup
- struct-like value type CefAcceleratedPaintInfo (1): CefRenderHandler.OnAcceleratedPaint
- another overload of CreateContext is generated (Python has one name) (1): CefRequestContext.CreateContext
- a pointer into memory that CEF's shared memory region owns and can free while Python still holds it (1): CefSharedMemoryRegion.Memory
- a pointer into memory that CEF's shared memory builder owns and can free while Python still holds it (1): CefSharedProcessMessageBuilder.Memory
- struct cef_platform_thread_id_t (1): CefThread.GetPlatformThreadId

## 클래스별

| 클래스 | 종류 | 메서드 | 생성 | 손으로 작성 | 생성하지 못함 |
| --- | --- | --- | --- | --- | --- |
| Cef (global functions) | functions | 40 | 39 | 0 | 1 |
| CefAccessibilityHandler | client | 2 | 2 | 0 | 0 |
| CefApp | client | 5 | 3 | 0 | 2 |
| CefAudioHandler | client | 5 | 5 | 0 | 0 |
| CefAuthCallback | library | 2 | 2 | 0 | 0 |
| CefBeforeDownloadCallback | library | 1 | 1 | 0 | 0 |
| CefBinaryValue | library | 9 | 8 | 0 | 1 |
| CefBoxLayout | library | 2 | 2 | 0 | 0 |
| CefBrowser | library | 21 | 21 | 0 | 0 |
| CefBrowserHost | library | 72 | 70 | 2 | 0 |
| CefBrowserProcessHandler | client | 7 | 6 | 0 | 1 |
| CefBrowserView | library | 6 | 6 | 0 | 0 |
| CefBrowserViewDelegate | client | 21 | 21 | 0 | 0 |
| CefButton | library | 6 | 6 | 0 | 0 |
| CefButtonDelegate | client | 13 | 13 | 0 | 0 |
| CefCallback | library | 2 | 2 | 0 | 0 |
| CefClient | client | 19 | 19 | 0 | 0 |
| CefCommandHandler | client | 5 | 5 | 0 | 0 |
| CefCommandLine | library | 23 | 22 | 0 | 1 |
| CefCompletionCallback | client | 1 | 1 | 0 | 0 |
| CefComponent | library | 4 | 4 | 0 | 0 |
| CefComponentUpdateCallback | client | 1 | 1 | 0 | 0 |
| CefComponentUpdater | library | 5 | 5 | 0 | 0 |
| CefContextMenuHandler | client | 7 | 7 | 0 | 0 |
| CefContextMenuParams | library | 20 | 20 | 0 | 0 |
| CefCookieAccessFilter | client | 2 | 2 | 0 | 0 |
| CefCookieManager | library | 6 | 6 | 0 | 0 |
| CefCookieVisitor | client | 1 | 1 | 0 | 0 |
| CefDeleteCookiesCallback | client | 1 | 1 | 0 | 0 |
| CefDevToolsMessageObserver | client | 5 | 5 | 0 | 0 |
| CefDialogHandler | client | 1 | 1 | 0 | 0 |
| CefDictionaryValue | library | 30 | 30 | 0 | 0 |
| CefDisplay | library | 16 | 16 | 0 | 0 |
| CefDisplayHandler | client | 13 | 13 | 0 | 0 |
| CefDownloadHandler | client | 3 | 3 | 0 | 0 |
| CefDownloadImageCallback | client | 1 | 1 | 0 | 0 |
| CefDownloadItem | library | 20 | 20 | 0 | 0 |
| CefDownloadItemCallback | library | 3 | 3 | 0 | 0 |
| CefDragData | library | 28 | 28 | 0 | 0 |
| CefDragHandler | client | 2 | 2 | 0 | 0 |
| CefEndTracingCallback | client | 1 | 1 | 0 | 0 |
| CefFileDialogCallback | library | 2 | 2 | 0 | 0 |
| CefFillLayout | library | 0 | 0 | 0 | 0 |
| CefFindHandler | client | 1 | 1 | 0 | 0 |
| CefFocusHandler | client | 3 | 3 | 0 | 0 |
| CefFrame | library | 26 | 24 | 0 | 2 |
| CefFrameHandler | client | 5 | 5 | 0 | 0 |
| CefImage | library | 14 | 14 | 0 | 0 |
| CefJSDialogCallback | library | 1 | 1 | 0 | 0 |
| CefJSDialogHandler | client | 4 | 4 | 0 | 0 |
| CefKeyboardHandler | client | 2 | 2 | 0 | 0 |
| CefLabelButton | library | 12 | 12 | 0 | 0 |
| CefLayout | library | 3 | 3 | 0 | 0 |
| CefLifeSpanHandler | client | 6 | 5 | 0 | 1 |
| CefListValue | library | 29 | 29 | 0 | 0 |
| CefLoadHandler | client | 4 | 4 | 0 | 0 |
| CefMediaAccessCallback | library | 2 | 2 | 0 | 0 |
| CefMediaObserver | client | 4 | 4 | 0 | 0 |
| CefMediaRoute | library | 5 | 5 | 0 | 0 |
| CefMediaRouteCreateCallback | client | 1 | 1 | 0 | 0 |
| CefMediaRouter | library | 6 | 6 | 0 | 0 |
| CefMediaSink | library | 7 | 7 | 0 | 0 |
| CefMediaSinkDeviceInfoCallback | client | 1 | 1 | 0 | 0 |
| CefMediaSource | library | 3 | 3 | 0 | 0 |
| CefMenuButton | library | 3 | 3 | 0 | 0 |
| CefMenuButtonDelegate | client | 14 | 14 | 0 | 0 |
| CefMenuButtonPressedLock | library | 0 | 0 | 0 | 0 |
| CefMenuModel | library | 57 | 57 | 0 | 0 |
| CefMenuModelDelegate | client | 7 | 7 | 0 | 0 |
| CefNavigationEntry | library | 10 | 10 | 0 | 0 |
| CefNavigationEntryVisitor | client | 1 | 1 | 0 | 0 |
| CefOverlayController | library | 19 | 19 | 0 | 0 |
| CefPanel | library | 13 | 13 | 0 | 0 |
| CefPanelDelegate | client | 11 | 11 | 0 | 0 |
| CefPdfPrintCallback | client | 1 | 1 | 0 | 0 |
| CefPermissionHandler | client | 3 | 3 | 0 | 0 |
| CefPermissionPromptCallback | library | 1 | 1 | 0 | 0 |
| CefPostData | library | 8 | 8 | 0 | 0 |
| CefPostDataElement | library | 9 | 9 | 0 | 0 |
| CefPreferenceManager | library | 9 | 9 | 0 | 0 |
| CefPreferenceObserver | client | 1 | 1 | 0 | 0 |
| CefPrintDialogCallback | library | 2 | 2 | 0 | 0 |
| CefPrintHandler | client | 6 | 6 | 0 | 0 |
| CefPrintJobCallback | library | 1 | 1 | 0 | 0 |
| CefPrintSettings | library | 23 | 23 | 0 | 0 |
| CefProcessMessage | library | 7 | 7 | 0 | 0 |
| CefReadHandler | client | 5 | 5 | 0 | 0 |
| CefRegistration | library | 0 | 0 | 0 | 0 |
| CefRenderHandler | client | 17 | 16 | 0 | 1 |
| CefRequest | library | 23 | 23 | 0 | 0 |
| CefRequestContext | library | 26 | 25 | 0 | 1 |
| CefRequestContextHandler | client | 2 | 2 | 0 | 0 |
| CefRequestHandler | client | 11 | 11 | 0 | 0 |
| CefResolveCallback | client | 1 | 1 | 0 | 0 |
| CefResourceBundle | library | 4 | 4 | 0 | 0 |
| CefResourceBundleHandler | client | 3 | 1 | 0 | 2 |
| CefResourceHandler | client | 7 | 7 | 0 | 0 |
| CefResourceReadCallback | library | 1 | 1 | 0 | 0 |
| CefResourceRequestHandler | client | 8 | 8 | 0 | 0 |
| CefResourceSkipCallback | library | 1 | 1 | 0 | 0 |
| CefResponse | library | 18 | 18 | 0 | 0 |
| CefResponseFilter | client | 2 | 2 | 0 | 0 |
| CefRunContextMenuCallback | library | 2 | 2 | 0 | 0 |
| CefRunFileDialogCallback | client | 1 | 1 | 0 | 0 |
| CefRunQuickMenuCallback | library | 2 | 2 | 0 | 0 |
| CefSSLInfo | library | 2 | 2 | 0 | 0 |
| CefSSLStatus | library | 5 | 5 | 0 | 0 |
| CefSchemeHandlerFactory | client | 1 | 1 | 0 | 0 |
| CefScrollView | library | 8 | 8 | 0 | 0 |
| CefSelectClientCertificateCallback | library | 1 | 1 | 0 | 0 |
| CefServer | library | 14 | 14 | 0 | 0 |
| CefServerHandler | client | 8 | 8 | 0 | 0 |
| CefSetCookieCallback | client | 1 | 1 | 0 | 0 |
| CefSettingObserver | client | 1 | 1 | 0 | 0 |
| CefSharedMemoryRegion | library | 3 | 2 | 0 | 1 |
| CefSharedProcessMessageBuilder | library | 5 | 4 | 0 | 1 |
| CefStreamReader | library | 8 | 8 | 0 | 0 |
| CefStreamWriter | library | 7 | 7 | 0 | 0 |
| CefStringVisitor | client | 1 | 1 | 0 | 0 |
| CefTask | client | 1 | 1 | 0 | 0 |
| CefTaskManager | library | 6 | 6 | 0 | 0 |
| CefTaskRunner | library | 7 | 7 | 0 | 0 |
| CefTextfield | library | 25 | 25 | 0 | 0 |
| CefTextfieldDelegate | client | 13 | 13 | 0 | 0 |
| CefThread | library | 5 | 4 | 0 | 1 |
| CefURLRequest | library | 8 | 8 | 0 | 0 |
| CefURLRequestClient | client | 5 | 5 | 0 | 0 |
| CefUnresponsiveProcessCallback | library | 2 | 2 | 0 | 0 |
| CefValue | library | 23 | 23 | 0 | 0 |
| CefView | library | 52 | 52 | 0 | 0 |
| CefViewDelegate | client | 11 | 11 | 0 | 0 |
| CefWaitableEvent | library | 6 | 6 | 0 | 0 |
| CefWindow | library | 43 | 43 | 0 | 0 |
| CefWindowDelegate | client | 34 | 34 | 0 | 0 |
| CefWriteHandler | client | 5 | 5 | 0 | 0 |
| CefX509CertPrincipal | library | 7 | 7 | 0 | 0 |
| CefX509Certificate | library | 10 | 10 | 0 | 0 |
| CefXmlReader | library | 30 | 26 | 0 | 4 |
| CefZipReader | library | 13 | 13 | 0 | 0 |

## 읽지 못한 열거형

- cef_color_id_t: enumerator 'COLOR_IDS CEF_UiColorsEnd'

## 관련 페이지

- [바인딩 생성기 계획](../concepts/binding-generator-plan.md)
