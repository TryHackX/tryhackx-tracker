# -*- coding: utf-8 -*-
"""
Build lang/en.php and lang/pl.php from every module in tools/lang_src.d/.

    python tools/lang_src.py .

Every string is written ONCE, as an (English, Polish) pair, and both files are generated from it.
That is the point: a key added to one language and forgotten in the other is the ordinary way a
translation rots, and here there is nowhere to put an English string without its Polish beside it.
tests/lang_test.php checks the two files still agree -- same keys, same `:name` placeholders,
nothing blank -- so a hand-edit that breaks the pairing is caught rather than shipped.

ONE MODULE PER AREA, under tools/lang_src.d/. Adding an area is adding a file; nothing here lists
them, so two people (or two agents) can work on different areas without touching the same file.

This generates ONLY the two languages that ship. Anything an operator installs through
Settings -> Languages is written by includes/lang.php from an uploaded JSON file and is not touched
by this script; running it will not overwrite somebody's German.
"""
import glob
import importlib.util
import io
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PARTS = os.path.join(HERE, 'lang_src.d')


def collect():
    """Every module's S, merged. A key defined twice is an error, not a silent last-wins."""
    out, seen = {}, {}
    for path in sorted(glob.glob(os.path.join(PARTS, '*.py'))):
        name = os.path.basename(path)[:-3]
        if name.startswith('_'):
            continue
        spec = importlib.util.spec_from_file_location('lang_part_' + name, path)
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        for k, v in getattr(mod, 'S', {}).items():
            if k in seen:
                raise SystemExit('duplicate key %r in %s (already in %s)' % (k, name, seen[k]))
            seen[k] = name
            out[k] = v
    return out


def emit(root):
    S = collect()
    for code, idx in (('en', 0), ('pl', 1)):
        lines = ["<?php", "/**",
                 " * %s strings for the tracker." % code.upper(),
                 " *",
                 " * GENERATED — edit tools/lang_src.d/*.py and run `python tools/lang_src.py .`.",
                 " * Both languages come from one source, so a key can never exist in one and be",
                 " * missing from the other. Keys are flat and dotted; a miss falls back to English",
                 " * and then to the key itself (includes/lang.php).",
                 " */", "return ["]
        for k in sorted(S):
            v = S[k][idx].replace('\\', '\\\\').replace("'", "\\'")
            lines.append("    '%s' => '%s'," % (k, v))
        lines.append("];")
        path = os.path.join(root, 'lang', code + '.php')
        io.open(path, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines) + '\n')
        print('%s  %d strings' % (path, len(S)))


if __name__ == '__main__':
    emit(sys.argv[1] if len(sys.argv) > 1 else '.')
