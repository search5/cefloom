// 참조 카운트 콜백을 네이티브로 제공하고, 호출 스레드를 계측하는 검증용 shim.
#define _GNU_SOURCE
#include <unistd.h>
#include <sys/syscall.h>
static long main_tid;
long shim_total, shim_foreign;
void shim_init(void) { main_tid = syscall(SYS_gettid); }
static void count(void) {
  __atomic_fetch_add(&shim_total, 1, __ATOMIC_RELAXED);
  if (syscall(SYS_gettid) != main_tid) __atomic_fetch_add(&shim_foreign, 1, __ATOMIC_RELAXED);
}
void shim_add_ref(void* self) { count(); }
int shim_release(void* self) { count(); return 0; }
int shim_has_one_ref(void* self) { count(); return 1; }
int shim_has_at_least_one_ref(void* self) { count(); return 1; }
long shim_get_total(void) { return shim_total; }
long shim_get_foreign(void) { return shim_foreign; }

// on_schedule_message_pump_work: CEF 문서상 임의의 스레드에서 호출된다. PHP를 건드리지 않고 원자적 값만 갱신한다.
static long pump_calls, pump_foreign;
static long long pump_delay = -1;
void shim_schedule(void* self, long long delay) {
  __atomic_fetch_add(&pump_calls, 1, __ATOMIC_RELAXED);
  if (syscall(SYS_gettid) != main_tid) __atomic_fetch_add(&pump_foreign, 1, __ATOMIC_RELAXED);
  __atomic_store_n(&pump_delay, delay, __ATOMIC_RELAXED);
}
long shim_pump_calls(void) { return pump_calls; }
long shim_pump_foreign(void) { return pump_foreign; }
