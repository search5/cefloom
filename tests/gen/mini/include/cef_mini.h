#ifndef MINI_H_
#define MINI_H_
#include "include/cef_base.h"

///
/// A small class implemented by the library.
///
/*--cef(source=library)--*/
class CefMiniThing : public virtual CefBaseRefCounted {
 public:
  ///
  /// Make one.
  ///
  /*--cef()--*/
  static CefRefPtr<CefMiniThing> Create(const CefString& name);

  ///
  /// Name.
  ///
  /*--cef()--*/
  virtual CefString GetName() = 0;

  ///
  /// Count.
  ///
  /*--cef(default_retval=0)--*/
  virtual int GetCount() = 0;

  ///
  /// Set.
  ///
  /*--cef()--*/
  virtual void SetCount(int count, bool loud) = 0;
};

///
/// A small class implemented by the application.
///
/*--cef(source=client)--*/
class CefMiniHandler : public virtual CefBaseRefCounted {
 public:
  ///
  /// Called with a thing.
  ///
  /*--cef()--*/
  virtual void OnThing(CefRefPtr<CefMiniThing> thing, const CefString& text) {}

  ///
  /// Decide.
  ///
  /*--cef(default_retval=false)--*/
  virtual bool OnDecide(int value) { return false; }

  ///
  /// A method with a type the generator cannot map.
  ///
  /*--cef()--*/
  virtual void OnRaw(void* untyped) {}
};

///
/// A class of the renderer process: not generated.
///
/*--cef(source=client)--*/
class CefV8Handler : public virtual CefBaseRefCounted {
 public:
  ///
  /// Never seen by PHP.
  ///
  /*--cef()--*/
  virtual bool Execute(const CefString& name) { return false; }
};

#endif
