# -*- coding: utf-8 -*-
"""
Build lang/en.php and lang/pl.php from every module in tools/lang_src.d/.

    python tools/lang_src.py .            # write lang/en.php and lang/pl.php
    python tools/lang_src.py --check .    # exit 1 if the files on disk differ from what these sources make

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


def emit(root, check=False):
    """
    Write the two files -- or, with check=True, only say whether they already match the sources.

    The check exists because 369 strings were once added straight to the generated files, over six
    releases, and the first regeneration after that dropped every one of them. tests/lang_test.php
    runs it, so a string that reaches lang/*.php without its (English, Polish) pair here fails the
    battery the same day rather than the day somebody regenerates.
    """
    S = collect()
    stale = 0
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
        content = '\n'.join(lines) + '\n'
        if check:
            try:
                on_disk = io.open(path, encoding='utf-8', newline='\n').read()
            except OSError:
                on_disk = ''
            if on_disk != content:
                stale += 1
                have = set(l for l in on_disk.split('\n') if l.startswith("    '"))
                want = set(l for l in content.split('\n') if l.startswith("    '"))
                print('%s differs from the sources: %d line(s) only on disk, %d only in the sources'
                      % (path, len(have - want), len(want - have)))
                for l in sorted(have - want)[:5]:
                    print('  disk only: ' + l[:110])
                for l in sorted(want - have)[:5]:
                    print('  source only: ' + l[:110])
            else:
                print('%s matches the sources (%d strings)' % (path, len(S)))
            continue
        io.open(path, 'w', encoding='utf-8', newline='\n').write(content)
        print('%s  %d strings' % (path, len(S)))
    return stale


if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if a != '--check']
    sys.exit(1 if emit(args[0] if args else '.', check='--check' in sys.argv) else 0)
