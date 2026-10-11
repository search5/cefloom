<?php
// CEF C API를 PHP FFI로 구동해 C 확장 설계의 전제를 검증한다.
// 사용법: php verify.php <런타임 디렉터리> <cdef 파일> <작업 디렉터리>
declare(strict_types=1);

[$_, $rt, $hdrFile, $work] = $argv;
$mode = getenv('MODE') ?: 'windowed';
$ffi  = FFI::cdef(file_get_contents($hdrFile), "$rt/libcef.so");
$libc = FFI::cdef('typedef int pid_t; pid_t gettid(void);', 'libc.so.6');

$mainTid = $libc->gettid();
$shim = FFI::cdef('void shim_init(void); void shim_add_ref(void*); int shim_release(void*); int shim_has_one_ref(void*); int shim_has_at_least_one_ref(void*); long shim_get_total(void); long shim_get_foreign(void); void shim_schedule(void*, long long); long shim_pump_calls(void); long shim_pump_foreign(void);', (getenv('REFSHIM') ?: dirname(__FILE__) . '/librefshim.so'));
$shim->shim_init();
$result  = [];          // 검증 항목 => [통과 여부, 설명]
$tids    = [];          // 콜백 이름 => 호출 스레드 목록
$keep    = [];          // FFI 객체와 클로저가 GC되지 않도록 보관
$state   = ['created' => false, 'closed' => false, 'js_ok' => false, 'ctx' => false,
            'pump_calls' => 0, 'paint' => 0, 'loaded' => false, 'browser' => null];

function note(string $name, bool $ok, string $msg = ''): void {
    global $result;
    $result[$name] = [$ok, $msg];
}
function hit(string $cb): void {
    global $libc, $tids;
    $tids[$cb][] = $libc->gettid();
}
function cstr(string $s) {
    global $ffi, $keep;
    $o = $ffi->new('cef_string_t');
    $ffi->cef_string_utf8_to_utf16($s, strlen($s), FFI::addr($o));
    $keep[] = $o;
    return FFI::addr($o);
}
function phpstr($p): string {
    if (FFI::isNull($p) || $p->length === 0) return '';
    $b = FFI::string(FFI::cast('char*', $p->str), $p->length * 2);
    $o = '';
    foreach (unpack('v*', $b) as $c) $o .= $c < 0x80 ? chr($c) : html_entity_decode('&#' . $c . ';', ENT_QUOTES, 'UTF-8');
    return $o;
}
function initBase($obj, string $type): void {
    global $ffi, $shim;
    $obj->base->size = FFI::sizeof($ffi->type($type));
    // 참조 카운트는 CEF 내부 스레드에서도 호출되므로 PHP 클로저가 아닌 네이티브 함수를 쓴다.
    $obj->base->add_ref = $shim->shim_add_ref;
    $obj->base->release = $shim->shim_release;
    $obj->base->has_one_ref = $shim->shim_has_one_ref;
    $obj->base->has_at_least_one_ref = $shim->shim_has_at_least_one_ref;
}
function call($obj, string $name, ...$args) {
    return ($obj->$name)($obj, ...$args);
}
function make(string $type) {
    global $ffi, $keep;
    $o = $ffi->new($type);
    initBase($o, $type);
    $keep[] = $o;
    return $o;
}

// 1. 버전 핸드셰이크와 라이브러리 로드
$hash = $ffi->cef_api_hash(999999, 2);
$ver  = [];
foreach ([0, 1, 2, 3] as $i) $ver[] = $ffi->cef_version_info($i);
note('libcef 로드와 API 해시', strlen($hash) > 0, "commit=$hash version=" . implode('.', $ver));

// 2. 핸들러 구성
$display = make('cef_display_handler_t');
$display->on_console_message = $keep[] = function ($self, $browser, $level, $msg, $src, $line) use (&$state): int {
    hit('on_console_message');
    $text = phpstr($msg);
    fwrite(STDERR, "[console] $text\n");
    if (str_contains($text, 'PHPOK 3')) $state['js_ok'] = true;
    return 0;
};

$load = make('cef_load_handler_t');
$load->on_load_end = $keep[] = function ($self, $browser, $frame, $code) use (&$state): void {
    hit('on_load_end');
    $state['loaded'] = true;
    if (call($frame, 'is_main')) {
        call($frame, 'execute_java_script', cstr("console.log('PHPOK ' + (1 + 2))"), cstr('about:blank'), 0);
    }
};

$life = make('cef_life_span_handler_t');
$life->on_after_created = $keep[] = function ($self, $browser) use (&$state): void {
    hit('on_after_created');
    $state['created'] = true;
    global $ffi;
    $b = $ffi->cast('cef_base_ref_counted_t*', $browser);
    ($b->add_ref)($b);
    $state['browser'] = $browser;
};
$life->on_before_close = $keep[] = function ($self, $browser) use (&$state): void {
    hit('on_before_close');
    $state['closed'] = true;
};

$render = make('cef_render_handler_t');
$render->get_view_rect = $keep[] = function ($self, $browser, $rect): void {
    hit('get_view_rect');
    $rect->x = 0; $rect->y = 0; $rect->width = 800; $rect->height = 600;
};
$render->on_paint = $keep[] = function ($self, $browser, $type, $n, $dirty, $buf, $w, $h) use (&$state): void {
    hit('on_paint');
    $state['paint']++;
};

$client = make('cef_client_t');
$client->get_display_handler   = $keep[] = function ($self) use ($display) { return FFI::addr($display); };
$client->get_load_handler      = $keep[] = function ($self) use ($load) { return FFI::addr($load); };
$client->get_life_span_handler = $keep[] = function ($self) use ($life) { return FFI::addr($life); };
$client->get_render_handler    = $keep[] = function ($self) use ($render, $mode) {
    return $mode === 'osr' ? FFI::addr($render) : null;
};

$bph = make('cef_browser_process_handler_t');
$bph->on_context_initialized = $keep[] = function ($self) use (&$state): void {
    hit('on_context_initialized');
    $state['ctx'] = true;
};
$bph->on_schedule_message_pump_work = $shim->shim_schedule;

$app = make('cef_app_t');
$app->get_browser_process_handler = $keep[] = function ($self) use ($bph) { return FFI::addr($bph); };
$app->on_before_command_line_processing = $keep[] = function ($self, $process_type, $cmd) use ($ffi): void {
    call($cmd, 'append_switch_with_value', cstr('ozone-platform'), cstr('x11'));
};

// 3. 초기화
$argvBuf = FFI::new('char[16]', false);
FFI::memcpy($argvBuf, "php\0", 4);
$argvArr = FFI::new('char*[1]', false);
$argvArr[0] = FFI::cast('char*', $argvBuf);
$args = $ffi->new('cef_main_args_t');
$args->argc = 1;
$args->argv = FFI::cast('char**', FFI::addr($argvArr));

$rc = $ffi->cef_execute_process(FFI::addr($args), FFI::addr($app), null);
note('cef_execute_process가 브라우저 프로세스에서 -1 반환', $rc === -1, "rc=$rc");

$set = $ffi->new('cef_settings_t');
$set->size = FFI::sizeof($set);
$set->no_sandbox = 1;
$set->external_message_pump = 1;
$set->multi_threaded_message_loop = 0;
$set->windowless_rendering_enabled = $mode === 'osr' ? 1 : 0;
$set->log_severity = $ffi->LOGSEVERITY_WARNING;
foreach (['browser_subprocess_path' => "$rt/cefsubprocess", 'resources_dir_path' => $rt,
          'locales_dir_path' => "$rt/locales", 'root_cache_path' => "$work/cache",
          'cache_path' => "$work/cache/default", 'log_file' => "$work/cef.log"] as $f => $v) {
    $ffi->cef_string_utf8_to_utf16($v, strlen($v), FFI::addr($set->$f));
}
@mkdir("$work/cache/default", 0777, true);

$t0 = microtime(true);
$ok = $ffi->cef_initialize(FFI::addr($args), FFI::addr($set), FFI::addr($app), null);
note('cef_initialize 성공', $ok === 1, sprintf('%.2f초', microtime(true) - $t0));
if ($ok !== 1) { report(); exit(1); }

// 4. 브라우저 생성
$wi = $ffi->new('cef_window_info_t');
$wi->size = FFI::sizeof($wi);
$wi->bounds->x = 0; $wi->bounds->y = 0; $wi->bounds->width = 800; $wi->bounds->height = 600;
$wi->windowless_rendering_enabled = $mode === 'osr' ? 1 : 0;
$wi->runtime_style = $ffi->CEF_RUNTIME_STYLE_ALLOY;
$bs = $ffi->new('cef_browser_settings_t');
$bs->size = FFI::sizeof($bs);
$url = 'data:text/html,<html><body><h1>hello</h1></body></html>';
$cr = $ffi->cef_browser_host_create_browser(FFI::addr($wi), FFI::addr($client), cstr($url), FFI::addr($bs), null, null);
note('cef_browser_host_create_browser 호출', $cr === 1, "rc=$cr mode=$mode");

// 5. 메시지 루프를 PHP 메인 스레드에서 직접 구동
$deadline = microtime(true) + (float)(getenv('TIMEOUT') ?: 40);
$closing  = false;
$loops    = 0;
while (microtime(true) < $deadline) {
    $ffi->cef_do_message_loop_work();
    $loops++;
    usleep(2000);
    if ($state['js_ok'] && ($mode !== 'osr' || $state['paint'] > 0) && !$closing) {
        $host = call($state['browser'], 'get_host');
        call($host, 'close_browser', 1);
        $closing = true;
    }
    if ($state['closed']) break;
}
for ($i = 0; $i < 50; $i++) { $ffi->cef_do_message_loop_work(); usleep(2000); }
$ffi->cef_shutdown();

report();

function report(): void {
    global $result, $tids, $mainTid, $state, $loops, $mode, $shim;
    note('콜백 간 life span: 생성과 종료 통지', $state['created'] && $state['closed'], '');
    note('로드 완료 통지(on_load_end)', $state['loaded'], '');
    note('PHP에서 실행한 JS의 console 출력이 PHP 콜백으로 도착', $state['js_ok'], '');
    note('on_context_initialized 수신', $state['ctx'], '');
    note('on_schedule_message_pump_work 수신(네이티브 shim)', $shim->shim_pump_calls() > 0, $shim->shim_pump_calls().'회 중 메인 스레드 밖 '.$shim->shim_pump_foreign().'회');
    if ($mode === 'osr') note('OSR on_paint 수신', $state['paint'] > 0, "{$state['paint']}회");
    $bad = [];
    foreach ($tids as $cb => $list) {
        $u = array_unique($list);
        if ($u !== [$mainTid]) $bad[] = "$cb=" . implode(',', $u);
    }
    note('참조 카운트 콜백의 호출 스레드(참고)', true, '전체 '.$shim->shim_get_total().'회 중 메인 스레드 밖 '.$shim->shim_get_foreign().'회');
    note('모든 콜백이 PHP 메인 스레드에서 호출', $bad === [], $bad ? implode(' ', $bad) : 'tid=' . $mainTid . ' 콜백 ' . count($tids) . '종');
    echo "\n=== 검증 결과 (loops=$loops) ===\n";
    foreach ($result as $name => [$ok, $msg]) {
        printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $msg ? "  ($msg)" : '');
    }
}
