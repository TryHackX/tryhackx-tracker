# -*- coding: utf-8 -*-
"""Which Font Awesome draws the icons, and the packages an owner installs (1.69.0,
includes/icons.php, includes/iconpack.php, api/admin/iconpacks.php, tools/iconpack.php)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

Font Awesome's own words stay its own: Free, Pro, Solid, Regular, Light, Thin, Duotone, Sharp and the
7.x family names are product names and are not translated (the style select lists them as Font
Awesome names them). "Paczka" for a package: the word the owner uses. Numbers sit where Polish does
not have to agree with them ("stylów: 37", "plików: 81"), never before a noun that changes with them.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Site -> Font Awesome ──────────────────────────────────────────
add('settings', {
    'fa_heading': ('Font Awesome', 'Font Awesome'),
    'fa_heading_sub': ('— drawn while the icon library is Font Awesome', '— rysuje, gdy biblioteka ikon to Font Awesome'),
    'fa_source': ('Font Awesome source', 'Źródło Font Awesome'),
    'fa_source_cdn6': ('Free 6.7.2 (jsDelivr)', 'Free 6.7.2 (jsDelivr)'),
    'fa_source_cdn7': ('Free 7.3.1 (jsDelivr)', 'Free 7.3.1 (jsDelivr)'),
    'fa_source_pack': ('A package installed here (Pro or Free)', 'Paczka zainstalowana tutaj (Pro lub Free)'),
    'fa_source_hint': (
        'Free 6.7.2 is what the site has drawn since 1.68; 7.3.1 is the newer Free build. Both come from '
        'jsDelivr, pinned and checked by their hash. A package is your own copy — Font Awesome Pro 6 or 7 '
        'with the styles you bought — installed below and served by this site itself.',
        'Free 6.7.2 to to, co strona rysuje od wersji 1.68; 7.3.1 to nowsze wydanie Free. Oba pochodzą '
        'z jsDelivr, przypięte do wersji i sprawdzane po sumie kontrolnej. Paczka to twoja własna kopia — '
        'Font Awesome Pro 6 lub 7 ze stylami, które kupiłeś — instalowana niżej i serwowana przez samą stronę.'),
    'fa_pack': ('Package', 'Paczka'),
    'fa_pack_none': ('(no package installed)', '(brak zainstalowanej paczki)'),
    'fa_pack_hint': (
        'Used when the source is a package. Switching the site to a package, or to another one, asks for '
        'your password when you save.',
        'Używana, gdy źródłem jest paczka. Przełączenie strony na paczkę albo na inną paczkę wymaga przy '
        'zapisie podania hasła.'),
    'fa_style': ('Style of the site’s icons', 'Styl ikon strony'),
    'fa_style_hint': (
        'Only the styles that load are offered. Solid is how the site has always looked. Outlines stay '
        'outlines and filled icons stay filled (a starred favourite, a pinned line), and brands are always '
        'brands.',
        'Do wyboru są tylko style, które się ładują. Solid to wygląd, jaki strona miała zawsze. Kontury '
        'zostają konturami, a ikony wypełnione wypełnionymi (ulubione z gwiazdką, przypięty wpis), a marki '
        'zawsze są markami.'),
    'fa_pack_gone': (
        'The package :id is not installed any more — the site draws Free 6.7.2 until you choose another '
        'source or install it again.',
        'Paczki :id nie ma już na serwerze — strona rysuje Free 6.7.2, dopóki nie wybierzesz innego źródła '
        'albo nie zainstalujesz jej ponownie.'),
    'fa_pack_styles': ('Style files to load', 'Pliki stylów do załadowania'),
    'fa_pack_styles_hint': (
        'Font Awesome no longer puts everything in all.css: Sharp and the 7.x families (Jelly, Slab, '
        'Utility…) are files of their own. What is in all.css always loads; tick the others you want — each '
        'is one more stylesheet and, when an icon uses it, one more font for the browser to fetch.',
        'Font Awesome nie trzyma już wszystkiego w all.css: Sharp i rodziny z wersji 7 (Jelly, Slab, '
        'Utility…) to osobne pliki. To, co jest w all.css, ładuje się zawsze; zaznacz te pozostałe, których '
        'chcesz — każdy to jeden arkusz stylów więcej, a gdy ikona go użyje, jeszcze jedna czcionka do pobrania.'),
    'fa_preview': ('Preview', 'Podgląd'),
    'fa_preview_frame': ('The site’s common icons in this setup', 'Najczęstsze ikony strony w tym ustawieniu'),
    'fa_packages': ('Installed packages', 'Zainstalowane paczki'),
    'fa_add_heading': ('Install a package', 'Zainstaluj paczkę'),
    'fa_drop_aria': ('Choose a zip of Font Awesome or drop it here', 'Wybierz plik zip z Font Awesome albo upuść go tutaj'),
    'fa_drop_choose': ('Choose a zip', 'Wybierz plik zip'),
    'fa_drop_or': ('or drop it here', 'albo upuść go tutaj'),
    'fa_drop_sub': (
        'the folder Font Awesome downloads as, zipped any way — its contents, the folder, or inside more folders',
        'folder pobrany z Font Awesome, spakowany jakkolwiek — sama zawartość, cały folder albo w kolejnych folderach'),
    'fa_upload': ('Install from the zip', 'Zainstaluj z pliku zip'),
    'fa_path_ph': ('…or a zip or a folder on the server, e.g. /home/you/fontawesome-pro-7.3.1-web', '…albo plik zip lub folder na serwerze, np. /home/ty/fontawesome-pro-7.3.1-web'),
    'fa_path_label': ('A zip or a folder on the server', 'Plik zip lub folder na serwerze'),
    'fa_path_install': ('Install from the server', 'Zainstaluj z serwera'),
    'fa_packages_hint': (
        'Every file is checked before anything is kept: only <code>css/</code>, <code>webfonts/</code>, '
        '<code>metadata/</code> and a licence text are taken from the package, stylesheets may point only at '
        'the package’s own fonts, and a package that fails a check is refused whole. Packages live in '
        '<code>config/iconpacks/</code> on this server — never in the repository, never in a deploy — and '
        'the one in use cannot be deleted. From the shell: <code>php tools/iconpack.php</code> (run it as the '
        'web server user).',
        'Każdy plik jest sprawdzany, zanim cokolwiek zostanie zachowane: z paczki brane są tylko '
        '<code>css/</code>, <code>webfonts/</code>, <code>metadata/</code> i tekst licencji, arkusze stylów mogą '
        'wskazywać wyłącznie własne czcionki paczki, a paczka, która nie przejdzie choć jednej kontroli, jest '
        'odrzucana w całości. Paczki leżą w <code>config/iconpacks/</code> na tym serwerze — nigdy w repozytorium '
        'ani we wdrożeniu — a używanej nie da się usunąć. Z konsoli: <code>php tools/iconpack.php</code> '
        '(uruchamiaj jako użytkownik serwera WWW).'),
})

# ── the settings save's refusals ─────────────────────────────────────────────
add('api.settings', {
    'fa_pack_none': (
        'Choose which installed package the site should use, or another Font Awesome source.',
        'Wybierz, której zainstalowanej paczki ma używać strona, albo inne źródło Font Awesome.'),
    'fa_pack_missing': (
        'The package :id is not installed — install it first, or choose another source.',
        'Paczka :id nie jest zainstalowana — najpierw ją zainstaluj albo wybierz inne źródło.'),
})

# ── admin/iconpacks and tools/iconpack.php ───────────────────────────────────
add('api.iconpack', {
    'refused': ('Refused:', 'Odrzucono:'),
    'found_in': ('package found in:', 'paczka znaleziona w:'),
    'already': ('Already installed:', 'Już zainstalowana:'),
    'installed': ('Installed', 'Zainstalowano'),
    'n_styles': (':n styles', 'stylów: :n'),
    'n_files': (':n files', 'plików: :n'),
    'skipped': ('skipped:', 'pominięto:'),
    'note': ('note:', 'uwaga:'),
    'msg_installed': ('Installed :id.', 'Zainstalowano :id.'),
    'msg_already': ('That package is already installed (:id).', 'Ta paczka jest już zainstalowana (:id).'),
    'msg_activated': ('The site now draws with :id.', 'Strona rysuje teraz paczką :id.'),
    'msg_activated_bi': (
        ':id is the Font Awesome source now — the site draws it once the icon library is Font Awesome.',
        ':id jest teraz źródłem Font Awesome — strona zacznie jej używać, gdy biblioteką ikon będzie Font Awesome.'),
    'msg_deleted': ('Deleted :id.', 'Usunięto :id.'),
    'upload_too_large': (
        'The zip is larger than this server accepts in one upload (:max MB). Unpack it on the server and '
        'install it from its folder instead.',
        'Plik zip jest większy, niż ten serwer przyjmuje w jednym wysłaniu (:max MB). Rozpakuj go na serwerze '
        'i zainstaluj z folderu.'),
    'no_file': ('Choose a zip first.', 'Najpierw wybierz plik zip.'),
    'upload_failed': ('The upload did not arrive whole — try again.', 'Plik nie dotarł w całości — spróbuj ponownie.'),
    'path_absolute': (
        'Give the full path of a zip or a folder on the server (starting with /).',
        'Podaj pełną ścieżkę pliku zip lub folderu na serwerze (zaczynającą się od /).'),
    'unknown_op': ('Unknown action.', 'Nieznana operacja.'),

    'err_not_found': ('Nothing is there: :detail', 'Nic tam nie ma: :detail'),
    'err_not_readable': ('The web server cannot read :detail', 'Serwer WWW nie może odczytać :detail'),
    'err_not_zip': ('That is not a zip file.', 'To nie jest plik zip.'),
    'err_no_zip_support': ('This PHP has no ZipArchive — install the zip extension, or install from a folder.',
                           'Ten PHP nie ma ZipArchive — zainstaluj rozszerzenie zip albo instaluj z folderu.'),
    'err_zip_open': ('The zip cannot be read — it may be damaged.', 'Nie da się odczytać pliku zip — może być uszkodzony.'),
    'err_too_many_entries': ('The archive holds more files than a package ever needs (:detail).',
                             'Archiwum zawiera więcej plików, niż paczka kiedykolwiek potrzebuje (:detail).'),
    'err_unsafe_name': ('A name in it could write outside where it is unpacked: :detail',
                        'Jedna z nazw w środku mogłaby zapisać plik poza miejscem rozpakowania: :detail'),
    # Plain text, like every api.* message: a toast and the shell print it as it is.
    'err_backslash': (
        'The names in this zip use backslashes (:detail) — the way PowerShell’s Compress-Archive packs. '
        'Pack the folder again with Explorer’s “Send to → Compressed (zipped) folder”, 7-Zip or '
        '“tar -a -c -f fa.zip FOLDER”.',
        'Nazwy w tym pliku zip używają ukośników wstecznych (:detail) — tak pakuje Compress-Archive '
        'z PowerShella. Spakuj folder ponownie przez „Wyślij do → Folder skompresowany (zip)” w Eksploratorze, '
        '7-Zip albo „tar -a -c -f fa.zip FOLDER”.'),
    'err_symlink': ('It contains a symbolic link (:detail) — a package is files, never links.',
                    'Zawiera dowiązanie symboliczne (:detail) — paczka to pliki, nigdy dowiązania.'),
    'err_inside_store': ('That folder is inside the package store itself.', 'Ten folder leży wewnątrz magazynu paczek.'),
    'err_no_root': (
        'No Font Awesome package inside: nothing has a css/ folder with a Font Awesome stylesheet next to a '
        'webfonts/ folder.',
        'W środku nie ma paczki Font Awesome: nic nie ma folderu css/ z arkuszem Font Awesome obok folderu webfonts/.'),
    'err_two_roots': ('It holds more than one package (:detail) — install them one at a time.',
                      'Zawiera więcej niż jedną paczkę (:detail) — instaluj je pojedynczo.'),
    'err_duplicate': ('The same file is in it twice: :detail', 'Ten sam plik występuje w nim dwa razy: :detail'),
    'err_case_collision': ('Two files differ only in capital letters: :detail', 'Dwa pliki różnią się tylko wielkością liter: :detail'),
    'err_encrypted': ('A file in it is encrypted: :detail', 'Jeden z plików jest zaszyfrowany: :detail'),
    'err_file_too_large': ('A file is larger than any Font Awesome file: :detail', 'Plik jest większy niż jakikolwiek plik Font Awesome: :detail'),
    'err_ratio': ('A file packs far too well to be what it says it is (:detail) — refused as a zip bomb.',
                  'Plik kompresuje się zbyt dobrze jak na to, czym ma być (:detail) — odrzucony jako bomba zip.'),
    'err_too_many_files': ('More files to keep than a package has (:detail).', 'Więcej plików do zachowania, niż ma paczka (:detail).'),
    'err_total_too_large': ('Unpacked, it would be larger than any Font Awesome package (:detail bytes).',
                            'Po rozpakowaniu byłaby większa niż jakakolwiek paczka Font Awesome (bajtów: :detail).'),
    'err_disk_full': ('Not enough free disk space on the server for it.', 'Na serwerze brakuje miejsca na dysku.'),
    'err_store_not_writable': ('The web server cannot write to :detail — it must own config/iconpacks/.',
                               'Serwer WWW nie może pisać do :detail — musi być właścicielem config/iconpacks/.'),
    'err_too_many_packages': ('At most :detail packages can be installed — delete one first.',
                              'Można zainstalować najwyżej tyle paczek: :detail — najpierw usuń którąś.'),
    'err_write_failed': ('A file could not be written: :detail', 'Nie udało się zapisać pliku: :detail'),
    'err_size_mismatch': ('A file is not the size the archive says it is: :detail', 'Plik ma inny rozmiar, niż podaje archiwum: :detail'),
    'err_font_magic': ('Not a real font: :detail', 'To nie jest prawdziwa czcionka: :detail'),
    'err_css_encoding': ('A stylesheet is not UTF-8 text: :detail', 'Arkusz stylów nie jest tekstem UTF-8: :detail'),
    'err_css_not_fa': ('A stylesheet does not say it is Font Awesome’s, with an edition and a version: :detail',
                       'Arkusz stylów nie podaje, że jest arkuszem Font Awesome, z edycją i wersją: :detail'),
    'err_css_import': ('A stylesheet imports another one: :detail', 'Arkusz stylów importuje inny arkusz: :detail'),
    'err_css_forbidden': ('A stylesheet contains something no Font Awesome file does: :detail',
                          'Arkusz stylów zawiera coś, czego nie ma żaden plik Font Awesome: :detail'),
    'err_css_url_external': ('A stylesheet points outside the package: :detail', 'Arkusz stylów wskazuje coś spoza paczki: :detail'),
    'err_css_url_missing': ('A stylesheet points at a font the package does not have: :detail',
                            'Arkusz stylów wskazuje czcionkę, której w paczce nie ma: :detail'),
    'err_css_unparsable': ('A stylesheet cannot be read: :detail', 'Nie da się odczytać arkusza stylów: :detail'),
    'err_css_mixed_versions': ('The stylesheets are from different versions: :detail', 'Arkusze stylów pochodzą z różnych wersji: :detail'),
    'err_major_unsupported': ('Font Awesome :detail — this site supports versions 6 and 7.', 'Font Awesome :detail — ta strona obsługuje wersje 6 i 7.'),
    'err_no_core': ('The package has neither all.css nor fontawesome.css with solid (:detail).',
                    'Paczka nie ma ani all.css, ani fontawesome.css ze stylem solid (:detail).'),
    'err_css_global_rule': ('A style file would change icons of other styles: :detail', 'Plik stylu zmieniłby ikony innych stylów: :detail'),
    'err_css_face_conflict': ('A style file claims another style’s font: :detail', 'Plik stylu przywłaszcza sobie czcionkę innego stylu: :detail'),
    'err_css_no_icons': ('The stylesheet declares no icons: :detail', 'Arkusz stylów nie deklaruje żadnych ikon: :detail'),
    'err_unknown_package': ('No such package is installed.', 'Nie ma zainstalowanej takiej paczki.'),
    'err_active_package': ('That is the package the site draws with — choose another source first.',
                           'Tą paczką strona właśnie rysuje — najpierw wybierz inne źródło.'),
    'err_broken_manifest': ('The package’s manifest cannot be read — delete it and install it again.',
                            'Nie da się odczytać manifestu paczki — usuń ją i zainstaluj ponownie.'),
    'err_note_too_large': ('too large to keep; left out', 'za duży, by go zachować; pominięty'),
    'err_note_not_text': ('not a text file; left out', 'to nie jest plik tekstowy; pominięty'),
    'err_note_not_a_style': ('left out: not a style this site loads', 'pominięty: to nie styl, który ta strona ładuje'),
    'err_note_no_family_class': ('left out: its family has no class to draw with', 'pominięty: jego rodzina nie ma klasy do rysowania'),
    'err_note_compat': ('left out: a compatibility sheet for Font Awesome 4 and 5 names and fonts, not a style',
                        'pominięty: arkusz zgodności z nazwami i czcionkami Font Awesome 4 i 5, a nie styl'),
    'err_note_svg_build': ('left out: it belongs to the JS/SVG build, which this site never loads',
                           'pominięty: należy do wersji JS/SVG, której ta strona nigdy nie ładuje'),
})

# ── assets/js/admin-iconpacks.js ─────────────────────────────────────────────
add('js.iconpack', {
    'failed': ('That did not work.', 'To się nie udało.'),
    'core_all': ('In all.css, always loaded:', 'W all.css, ładowane zawsze:'),
    'core_fontawesome': ('Always loaded with fontawesome.css (the site’s solid, outlines and brand need them):',
                         'Ładowane zawsze z fontawesome.css (potrzebne ikonom pełnym, konturom i marce strony):'),
    'no_extra': ('Every style of this package is in all.css.', 'Każdy styl tej paczki jest w all.css.'),
    'in_all': ('in all.css', 'w all.css'),
    'size_title': ('The stylesheet, and the font a browser fetches for the first icon drawn with it',
                   'Arkusz stylów oraz czcionka, którą przeglądarka pobiera przy pierwszej ikonie w tym stylu'),
    'two_layers': ('2 layers', '2 warstwy'),
    'two_layers_title': ('Draws every icon in two tones, one layer over the other', 'Rysuje każdą ikonę w dwóch tonach, warstwa na warstwie'),
    'probe_all': ('Every icon here is drawn by :family itself.', 'Każdą ikonę rysuje tu sama czcionka :family.'),
    'probe_other': (
        ':family has no glyph for some of the site’s icons (:n); they are drawn by the classic family instead.',
        ':family nie ma znaków dla części ikon strony (liczba: :n); rysuje je zamiast niej rodzina klasyczna.'),
    'probe_missing': ('Some icons draw nothing in this setup (:n).', 'Część ikon nie rysuje się w tym ustawieniu (liczba: :n).'),
    'cov_total': ('Mapped icons: :n', 'Ikony w mapie: :n'),
    'cov_exact': ('exact: :n', 'dokładne: :n'),
    'cov_twins': ('Pro twins: :n', 'bliźniaki Pro: :n'),
    'cov_approx': ('approximations: :n', 'przybliżenia: :n'),
    'cov_fallbacks': ('fallbacks: :n', 'zastępstwa: :n'),
    'cov_show': ('Show the fallbacks', 'Pokaż zastępstwa'),
    'fb_twin': ('The package has no :wanted; its Free icon is drawn:', 'Paczka nie ma :wanted; rysowana jest ikona z Free:'),
    'fb_name': ('The package has no :wanted at all:', 'Paczka w ogóle nie ma :wanted:'),
    'fb_not_loaded': ('Drawn in :drawn — tick :wanted to draw them in their own family:', 'Rysowane stylem :drawn — zaznacz :wanted, by rysować je własną rodziną:'),
    'fb_style': ('Not in :wanted, so drawn in :drawn:', 'Nie ma ich w :wanted, więc rysowane są stylem :drawn:'),
    'note_bootstrap': (
        'The site draws Bootstrap Icons now; this is how it will look with Font Awesome.',
        'Strona rysuje teraz Bootstrap Icons; tak będzie wyglądać z Font Awesome.'),
    'count': (':n of :max', ':n z :max'),
    'none': ('No package installed yet. Free 6.7.2 and 7.3.1 need none.', 'Nie zainstalowano jeszcze żadnej paczki. Free 6.7.2 i 7.3.1 jej nie potrzebują.'),
    'none_short': ('(no package installed)', '(brak zainstalowanej paczki)'),
    'col_package': ('Package', 'Paczka'),
    'col_styles': ('Styles', 'Style'),
    'col_size': ('Size', 'Rozmiar'),
    'col_installed': ('Installed', 'Zainstalowana'),
    'col_actions': ('Actions', 'Akcje'),
    'broken': ('broken', 'uszkodzona'),
    'active': ('in use', 'w użyciu'),
    'activate': ('Use', 'Użyj'),
    'activate_title': ('Make this package the Font Awesome source', 'Ustaw tę paczkę jako źródło Font Awesome'),
    'verify': ('Check the files against the manifest', 'Sprawdź pliki z manifestem'),
    'delete': ('Delete the package', 'Usuń paczkę'),
    'delete_active': ('The site draws with this package — choose another source first', 'Strona rysuje tą paczką — najpierw wybierz inne źródło'),
    'delete_confirm': ('Delete this package from the server? It can be installed again from its zip.',
                       'Usunąć tę paczkę z serwera? Można ją zainstalować ponownie z jej pliku zip.'),
    'n_styles': (':n styles', 'stylów: :n'),
    'n_outside': ('outside all.css: :n', 'poza all.css: :n'),
    'n_icons': ('icon names: :n', 'nazw ikon: :n'),
    'by': ('by :who', 'przez: :who'),
    'meta_free': ('metadata: Free', 'metadane: Free'),
    'meta_pro': ('metadata: Pro', 'metadane: Pro'),
    'meta_unknown': ('metadata: unread', 'metadane: nieodczytane'),
    'password_why': (
        'A package decides which stylesheet and fonts every visitor’s browser loads.',
        'Paczka decyduje, jaki arkusz stylów i jakie czcionki ładuje przeglądarka każdego odwiedzającego.'),
    'verify_ok': ('All files are as installed (:n).', 'Wszystkie pliki są takie, jak przy instalacji (:n).'),
    'verify_bad': ('Files differ from the manifest — missing: :missing, changed: :changed.', 'Pliki różnią się od manifestu — brakuje: :missing, zmienione: :changed.'),
    'drop_choose': ('Choose a zip', 'Wybierz plik zip'),
    'drop_or': ('or drop it here', 'albo upuść go tutaj'),
    'pick_file': ('Choose a zip first.', 'Najpierw wybierz plik zip.'),
    'pick_path': ('Type the path of a zip or a folder on the server first.', 'Najpierw wpisz ścieżkę pliku zip lub folderu na serwerze.'),
    'upload_too_large': ('The zip is larger than an upload may be here (:max) — install it from the server instead.',
                         'Plik zip jest większy, niż wolno tu wysłać (:max) — zainstaluj go z serwera.'),
    'install_title': ('Install a Font Awesome package', 'Zainstaluj paczkę Font Awesome'),
    'rep_root': ('Found in', 'Znaleziona w'),
    'rep_root_top': ('the top of the archive', 'głównym katalogu archiwum'),
    'rep_package': ('Package', 'Paczka'),
    'rep_kept': ('Kept', 'Zachowano'),
    'rep_kept_n': ('files: :n, :size', 'plików: :n, :size'),
    'rep_skipped': ('Skipped', 'Pominięto'),
    'rep_outside': ('outside the package:', 'poza paczką:'),
    'rep_too_large': ('too large:', 'za duże:'),
    'rep_note': ('Note', 'Uwaga'),
    'rep_refused': ('Refused', 'Odrzucono'),
    'note_too_large': ('too large to keep; left out', 'za duży, by go zachować; pominięty'),
    'note_not_text': ('not a text file; left out', 'to nie jest plik tekstowy; pominięty'),
    'note_not_a_style': ('left out: not a style this site loads', 'pominięty: to nie styl, który ta strona ładuje'),
    'note_no_family_class': ('left out: its family has no class to draw with', 'pominięty: jego rodzina nie ma klasy do rysowania'),
    'note_compat': ('left out: a compatibility sheet for Font Awesome 4 and 5 names and fonts, not a style',
                    'pominięty: arkusz zgodności z nazwami i czcionkami Font Awesome 4 i 5, a nie styl'),
    'note_svg_build': ('left out: it belongs to the JS/SVG build, which this site never loads',
                       'pominięty: należy do wersji JS/SVG, której ta strona nigdy nie ładuje'),
})
