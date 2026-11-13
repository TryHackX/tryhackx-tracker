# -*- coding: utf-8 -*-
"""A list's description, and its Edit window (1.70.0)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The card's button was "Rename" and is "Edit" now ("Edytuj"): it opens a window with the name and the
description together. The window's own words are drawn by the server (templates/partials/list_edit.php,
lists.*), the few the script says under js.lists.*, and what the save answers under api.lists.*.

Numbers are placed where Polish does not have to agree with them: "za dużo znaków: 1002" is right for
every number, "1002 znaków" is not -- the profile description's rule (profile_bio.py).
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Profiles -> Lists: the description's length ──────────────────
add('settings', {
    'lists_desc_max': ('Longest list description (characters)', 'Najdłuższy opis listy (znaki)'),
    'lists_desc_max_hint': (
        'A list\'s description is BBCode or Markdown, as the site\'s descriptions are, with the emoji picker. '
        'Counted as a reader sees it — the words, not the tags around them; an emoji is one character, an '
        'emote\'s <code>:code:</code> counts as it is typed. :min–:max. The text as typed, tags included, may '
        'be at most :factor times as long, and never more than :cap characters. No pictures from other '
        'sites — the site\'s own emotes and stickers only.',
        'Opis listy to BBCode albo Markdown, jak opisy na tej stronie, z selektorem emotek. Liczy się to, co '
        'widzi czytelnik — słowa, nie znaczniki wokół nich; emoji to jeden znak, a <code>:code:</code> emote '
        'liczy się tak, jak go wpisano. Od :min do :max. Tekst w postaci wpisanej, razem ze znacznikami, może '
        'być najwyżej :factor razy dłuższy, a liczba jego znaków nigdy nie przekracza :cap. Bez obrazków '
        'z innych stron — tylko emote i naklejki tej strony.'),
})

# ── the Edit window (templates/partials/list_edit.php) ───────────────────────
add('lists', {
    'edit_title': ('Edit list', 'Edytuj listę'),
    'edit_name': ('Name', 'Nazwa'),
    'edit_name_hint': (
        'Renaming keeps a shared list\'s address: a link you have handed out still opens it.',
        'Zmiana nazwy nie zmienia adresu udostępnionej listy: link, który komuś dałeś, dalej ją otwiera.'),
    'edit_desc': ('Description', 'Opis'),
    'edit_desc_ph': ('What is on this list, and who it is for…', 'Co jest na tej liście i dla kogo…'),
    'edit_desc_note': (
        'Shown under the name when the list is opened, and a line or two of it on the card. No pictures '
        'from other sites here — the site\'s own emotes and stickers are welcome.',
        'Widoczny pod nazwą po otwarciu listy, a linijka lub dwie z niego — na karcie. Bez obrazków '
        'z innych stron — emote i naklejki tej strony jak najbardziej.'),
    'edit_save': ('Save', 'Zapisz'),
    'edit_cancel': ('Cancel', 'Anuluj'),
    'edit_discard_q': ('Discard the changes?', 'Porzucić zmiany?'),
    'edit_discard': ('Discard', 'Porzuć'),
    'edit_keep': ('Keep editing', 'Edytuj dalej'),
})

# ── the card and the window's script (assets/js/favourites.js) ───────────────
add('js.lists', {
    'edit': ('Edit', 'Edytuj'),
    'edit_title': ('Edit the name and the description', 'Edytuj nazwę i opis'),
    'saving': ('Saving…', 'Zapisywanie…'),
    'edit_failed': ('The list could not be saved. Please try again.', 'Nie udało się zapisać listy. Spróbuj ponownie.'),
    'rate_limited': ('You are saving too often. Wait a while and try again.',
                     'Zapisujesz zbyt często. Odczekaj chwilę i spróbuj ponownie.'),
    'name_required': ('A list needs a name.', 'Lista musi mieć nazwę.'),
    'close_again': ('Unsaved changes — press again to close', 'Niezapisane zmiany — naciśnij ponownie, aby zamknąć'),
})

# ── what `edit` / `describe` answer (api/user_lists.php, includes/lists.php) ──
add('api.lists', {
    'name_required': ('A list needs a name.', 'Lista musi mieć nazwę.'),
    'name_too_long': ('That name is too long: at most :max characters.', 'Za długa nazwa: limit znaków to :max.'),
    'invalid': ('A description has to be text.', 'Opis musi być tekstem.'),
    'bad_encoding': ('That text is not valid UTF-8.', 'Ten tekst nie jest poprawnym UTF-8.'),
    'bad_format': ('That format is not accepted here.', 'Ten format nie jest tu przyjmowany.'),
    'muted': (
        'A moderator has silenced this account until :until, so the description cannot be changed before then. '
        'You can still remove it.',
        'Moderator wyciszył to konto do :until, więc do tego czasu nie można zmienić opisu. Można go jednak usunąć.'),
    'too_long': ('That description is too long: :n characters, and the limit is :max.',
                 'Ten opis ma za dużo znaków: :n, a limit to :max.'),
    'too_long_source': (
        'That description is too long once its tags are counted: at most :max characters, tags included.',
        'Ten opis jest za długi, gdy policzyć jego znaczniki: limit znaków razem ze znacznikami to :max.'),
    'no_images': (
        'A list\'s description cannot show pictures from other sites. The site\'s own emotes and stickers it can.',
        'Opis listy nie może pokazywać obrazków z innych stron. Emote i naklejki tej strony — może.'),
    'too_many_links': ('Too many links: :n, and the limit is :max.', 'Za dużo linków: :n, a limit to :max.'),
    'saved': ('Saved.', 'Zapisano.'),
})
