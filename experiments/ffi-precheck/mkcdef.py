import re, sys
src, out = sys.argv[1], sys.argv[2]
keep = False
lines = []
for ln in open(src, encoding='utf-8'):
    m = re.match(r'# \d+ "([^"]+)"', ln)
    if m:
        keep = '/cef/include/' in m.group(1)
        continue
    if keep and ln.strip():
        lines.append(ln.rstrip('\n'))
text = '\n'.join(lines)
text = text.replace('__attribute__((visibility("default")))', '')
text = re.sub(r'__attribute__\s*\(\(.*?\)\)', '', text)
text = text.replace('CEF_EXPORT', '')
open(out, 'w', encoding='utf-8').write(text + '\n')
