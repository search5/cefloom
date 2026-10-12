<?php
// php/cefloom.stub.php declares every class for an IDE. It must say what the extension really has: the classes, their
// parents and flags, constants, properties, methods, and each method's parameters (names, types, defaults) and return
// type. The stub is read as text (it cannot be included: its classes are the extension's) and compared with Reflection.
require __DIR__ . '/support.php';

$stub = dirname(__DIR__) . '/php/cefloom.stub.php';
check(is_file($stub), 'the stub exists');

/** Flatten the tokens: [id, text] without whitespace and comments. */
function stub_tokens(string $code): array
{
    $out = [];
    foreach (token_get_all($code) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $out[] = [$t[0], $t[1]];
        } else {
            $out[] = [null, $t];
        }
    }
    return $out;
}

/** A type as text, comparable: no spaces, no leading backslash, lower case, names of the stub's namespace made absolute. */
function norm_type(string $t, string $ns = ''): string
{
    $t = str_replace([' ', "\t", "\n"], '', $t);
    $builtin = ['int', 'string', 'bool', 'float', 'array', 'void', 'mixed', 'null', 'callable', 'iterable', 'object', 'self',
        'static', 'never', 'false', 'true', ''];
    $parts = [];
    foreach (explode('|', $t) as $part) {
        $q = str_starts_with($part, '?') ? '?' : '';
        $name = ltrim($part, '?');
        if ($name !== '' && !str_contains($name, '\\') && !in_array(strtolower($name), $builtin, true)) {
            $name = $ns . '\\' . $name;      // a name written relative to the namespace of the stub
        }
        $parts[] = $q . strtolower(ltrim($name, '\\'));
    }
    return implode('|', $parts);
}

/** Parse the stub into classes: name => [kind flags, parent, consts, props, methods]. */
function parse_stub(array $tk): array
{
    $classes = [];
    $ns = '';
    $n = count($tk);
    $i = 0;
    $text = fn(int $k) => $tk[$k][1] ?? '';
    while ($i < $n) {
        if ($tk[$i][0] === T_NAMESPACE) {
            $i++;
            $ns = '';
            while ($i < $n && $text($i) !== '{' && $text($i) !== ';') {
                $ns .= $text($i++);
            }
            continue;
        }
        $flags = [];
        $k = $i;
        while ($k < $n && in_array($tk[$k][0], [T_ABSTRACT, T_FINAL], true)) {
            $flags[] = $tk[$k][0] === T_ABSTRACT ? 'abstract' : 'final';
            $k++;
        }
        if ($k < $n && $tk[$k][0] === T_CLASS) {
            $name = $text($k + 1);
            $k += 2;
            $parent = null;
            if ($k < $n && $tk[$k][0] === T_EXTENDS) {
                $parent = ltrim($text($k + 1), '\\');
                $k += 2;
            }
            if ($text($k) !== '{') {
                $i = $k;
                continue;
            }
            $k++;
            $depth = 1;
            $class = ['flags' => $flags, 'parent' => $parent, 'consts' => [], 'props' => [], 'methods' => []];
            while ($k < $n && $depth > 0) {
                $t = $text($k);
                if ($t === '{') { $depth++; $k++; continue; }
                if ($t === '}') { $depth--; $k++; continue; }
                if ($depth !== 1) { $k++; continue; }
                // one member
                $mods = [];
                while (in_array($tk[$k][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                    $mods[] = strtolower($text($k));
                    $k++;
                }
                if ($tk[$k][0] === T_CONST) {
                    $k++;
                    $class['consts'][] = $text($k);
                    while ($text($k) !== ';') { $k++; }
                    $k++;
                } elseif ($tk[$k][0] === T_FUNCTION) {
                    $k++;
                    if ($text($k) === '&') { $k++; }
                    $mname = $text($k++);
                    $k++; // (
                    $params = [];
                    $cur = [];
                    $pd = 0;
                    while (!($text($k) === ')' && $pd === 0)) {
                        $tt = $text($k);
                        if ($tt === '(' || $tt === '[') { $pd++; }
                        if ($tt === ')' || $tt === ']') { $pd--; }
                        if ($tt === ',' && $pd === 0) { $params[] = $cur; $cur = []; } else { $cur[] = $tk[$k]; }
                        $k++;
                    }
                    if ($cur) { $params[] = $cur; }
                    $k++; // )
                    $ret = '';
                    if ($text($k) === ':') {
                        $k++;
                        while ($text($k) !== '{' && $text($k) !== ';') { $ret .= $text($k++); }
                    }
                    if ($text($k) === '{') {
                        $k++;
                        $bd = 1;
                        while ($bd > 0) { if ($text($k) === '{') $bd++; if ($text($k) === '}') $bd--; $k++; }
                    } else {
                        $k++;
                    }
                    $plist = [];
                    foreach ($params as $p) {
                        $type = '';
                        $pname = null;
                        $default = false;
                        $variadic = false;
                        $byref = false;
                        foreach ($p as $idx => $tok) {
                            if ($tok[0] === T_VARIABLE) { $pname = ltrim($tok[1], '$'); $rest = array_slice($p, $idx + 1); $default = $rest && $rest[0][1] === '='; break; }
                            if ($tok[1] === '...') { $variadic = true; continue; }
                            if ($tok[1] === '&') { $byref = true; continue; }
                            $type .= $tok[1];
                        }
                        $plist[] = ['name' => $pname, 'type' => norm_type($type, $ns), 'default' => $default, 'variadic' => $variadic, 'byref' => $byref];
                    }
                    $class['methods'][strtolower($mname)] = [
                        'name' => $mname, 'static' => in_array('static', $mods, true), 'abstract' => in_array('abstract', $mods, true),
                        'visibility' => in_array('private', $mods, true) ? 'private' : (in_array('protected', $mods, true) ? 'protected' : 'public'),
                        'params' => $plist, 'return' => norm_type($ret, $ns),
                    ];
                } elseif ($tk[$k][0] === T_VARIABLE || $text($k) === '?' || $tk[$k][0] !== null) {
                    // a property: [type] $name [= value];
                    $type = '';
                    while ($tk[$k][0] !== T_VARIABLE) { $type .= $text($k++); }
                    $pname = ltrim($text($k), '$');
                    while ($text($k) !== ';') { $k++; }
                    $k++;
                    $class['props'][$pname] = norm_type($type, $ns);
                } else {
                    $k++;
                }
            }
            $classes[$ns . '\\' . $name] = $class;
            $i = $k;
            continue;
        }
        $i++;
    }
    return $classes;
}

$classes = parse_stub(stub_tokens((string) file_get_contents($stub)));
check(count($classes) > 200, 'the stub declares many classes (' . count($classes) . ')');

$problems = [];
$checked = ['classes' => 0, 'methods' => 0, 'props' => 0];
foreach ($classes as $fqn => $decl) {
    $fqn = ltrim($fqn, '\\');
    if (!class_exists($fqn)) {
        $problems[] = "$fqn: not in the extension";
        continue;
    }
    $checked['classes']++;
    $r = new ReflectionClass($fqn);
    if (in_array('final', $decl['flags'], true) !== $r->isFinal()) {
        $problems[] = "$fqn: final differs (stub " . (in_array('final', $decl['flags'], true) ? 'yes' : 'no') . ')';
    }
    $isAbstract = $r->isAbstract();
    if (in_array('abstract', $decl['flags'], true) !== $isAbstract) {
        $problems[] = "$fqn: abstract differs";
    }
    $parent = $r->getParentClass() ? $r->getParentClass()->getName() : null;
    $declParent = $decl['parent'] === null ? null : (str_contains($decl['parent'], '\\') ? $decl['parent'] : substr($fqn, 0, strrpos($fqn, '\\')) . '\\' . $decl['parent']);
    if ($declParent !== $parent) {
        $problems[] = "$fqn: parent is " . var_export($parent, true) . ', the stub says ' . var_export($decl['parent'], true);
    }
    foreach ($decl['consts'] as $c) {
        if (!$r->hasConstant($c)) {
            $problems[] = "$fqn::$c: constant missing";
        }
    }
    foreach ($decl['props'] as $p => $type) {
        $checked['props']++;
        if (!$r->hasProperty($p)) {
            $problems[] = "$fqn::\$$p: property missing";
            continue;
        }
        $rt = $r->getProperty($p)->getType();
        $real = $rt === null ? '' : norm_type((string) $rt);
        if ($real !== $type) {
            $problems[] = "$fqn::\$$p: type is '$real', the stub says '$type'";
        }
    }
    // methods declared by this class
    $real = [];
    foreach ($r->getMethods() as $m) {
        if ($m->class === $r->getName()) {
            $real[strtolower($m->getName())] = $m;
        }
    }
    foreach ($decl['methods'] as $key => $s) {
        $checked['methods']++;
        $m = $real[$key] ?? ($r->hasMethod($key) ? $r->getMethod($key) : null);
        if (!$m) {
            $problems[] = "$fqn::{$s['name']}(): not in the extension";
            continue;
        }
        if ($m->isStatic() !== $s['static']) {
            $problems[] = "$fqn::{$s['name']}(): static differs";
        }
        if ($m->isAbstract() !== $s['abstract']) {
            $problems[] = "$fqn::{$s['name']}(): abstract differs";
        }
        $rps = $m->getParameters();
        if (count($rps) !== count($s['params'])) {
            $problems[] = "$fqn::{$s['name']}(): " . count($rps) . ' parameters, the stub says ' . count($s['params']);
            continue;
        }
        foreach ($rps as $idx => $rp) {
            $sp = $s['params'][$idx];
            $where = "$fqn::{$s['name']}() parameter " . ($idx + 1);
            if ($rp->getName() !== $sp['name']) {
                $problems[] = "$where: named '{$rp->getName()}', the stub says '{$sp['name']}'";
            }
            $rtype = $rp->getType() === null ? '' : norm_type((string) $rp->getType());
            $timeParam = $sp['type'] === 'datetimeinterface|int|float|null' && $rtype === '';   // checked by the extension, not by the signature
            if ($rtype !== $sp['type'] && !$timeParam) {
                $problems[] = "$where (\${$sp['name']}): type '$rtype', the stub says '{$sp['type']}'";
            }
            if ($rp->isOptional() !== $sp['default'] && !$sp['variadic']) {
                $problems[] = "$where (\${$sp['name']}): optional differs";
            }
        }
        // methods that PHP code may override have a tentative return type (checked, but only as a deprecation)
        $rtype_obj = $m->getReturnType() ?? $m->getTentativeReturnType();
        $rret = $rtype_obj === null ? '' : norm_type((string) $rtype_obj);
        if ($m->getName() !== '__construct' && $rret !== $s['return']) {
            $problems[] = "$fqn::{$s['name']}(): returns '$rret', the stub says '{$s['return']}'";
        }
    }
    foreach ($real as $key => $m) {
        if ($m->isPublic() && !isset($decl['methods'][$key]) && $key !== '__construct') {
            $problems[] = "$fqn::{$m->getName()}(): in the extension, not in the stub";
        }
    }
}

// classes of the extension that the stub lacks
foreach (get_declared_classes() as $c) {
    if (str_starts_with($c, 'Cefloom\\') && !isset($classes['\\' . $c]) && !isset($classes[$c])) {
        $problems[] = "$c: in the extension, not in the stub";
    }
}

$show = (int) (getenv('CEFLOOM_STUB_SHOW') ?: 40);
foreach (array_slice($problems, 0, $show) as $p) {
    fwrite(STDERR, "  $p\n");
}
if (count($problems) > $show) {
    fwrite(STDERR, '  ... and ' . (count($problems) - $show) . " more\n");
}
same(0, count($problems), "the stub and the extension agree ({$checked['classes']} classes, {$checked['methods']} methods, {$checked['props']} properties)");
finish();
