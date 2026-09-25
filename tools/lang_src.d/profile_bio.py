# -*- coding: utf-8 -*-
"""The description on a profile (1.69.0, includes/profilebio.php)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The Polish says "opis profilu" for the text itself -- the plain word a Polish reader expects on a
profile ("o mnie" is what they would write in it, not what the field is called). The toolbar's titles
are the ones every other toolbar on the site uses (rt.*), repeated under js.bio because the public
pages only carry the js.* prefixes they are given (LANG_JS_PUBLIC).

Numbers are placed where Polish does not have to agree with them: "za dużo znaków: 302" is right for
every number, "302 znaków" is not.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── Settings -> Profiles -> Profile description ──────────────────────────────
add('settings', {
    'profile_bio_heading': ('Profile description', 'Opis profilu'),
    'profile_bio_intro': (
        'A short text each member may write on their own profile, under their name, and edit in place there. '
        'Only basic BBCode works in it — [b]bold[/b], [i]italic[/i], [u]underline[/u], [s]strikethrough[/s] and '
        '[url] links (http and https, at most :links) — every other tag is shown as the text it is, and HTML is '
        'always text. At most :lines lines. Who may write one is the <code>profile.bio</code> permission '
        '(Users → Groups); members have it. A member whose groups lose it keeps the text, hidden, until the '
        'permission comes back.',
        'Krótki tekst, który każdy członek może napisać na swoim profilu, pod swoją nazwą, i tam go edytować. '
        'Działa w nim tylko podstawowy BBCode — [b]pogrubienie[/b], [i]kursywa[/i], [u]podkreślenie[/u], '
        '[s]przekreślenie[/s] i linki [url] (http i https, najwyżej :links) — każdy inny znacznik jest pokazywany '
        'jako zwykły tekst, a HTML zawsze jest tekstem. Liczba wierszy: najwyżej :lines. Kto może go napisać, '
        'decyduje uprawnienie <code>profile.bio</code> (Użytkownicy → Grupy); członkowie je mają. Członek, którego '
        'grupy je stracą, zachowuje tekst — ukryty, dopóki uprawnienie nie wróci.'),
    'profile_bio_enabled': ('Profile descriptions', 'Opisy profili'),
    'profile_bio_enabled_hint': (
        'Off hides every description and the editor. Nothing is deleted.',
        'Wyłączenie ukrywa wszystkie opisy i edytor. Nic nie jest usuwane.'),
    'profile_bio_max': ('Longest description (characters)', 'Najdłuższy opis (znaki)'),
    'profile_bio_max_hint': (
        'Counted as a reader sees it — the words, not the tags around them; an emoji is one character. '
        ':min–:max. The text as typed, tags included, may be at most :factor times as long, and never more '
        'than :cap characters.',
        'Liczy się to, co widzi czytelnik — słowa, nie znaczniki wokół nich; emoji to jeden znak. Od :min do '
        ':max. Tekst w postaci wpisanej, razem ze znacznikami, może być najwyżej :factor razy dłuższy, a liczba '
        'jego znaków nigdy nie przekracza :cap.'),
})

# ── the profile page ─────────────────────────────────────────────────────────
add('profile', {
    'bio_placeholder': ('Write something about yourself…', 'Napisz coś o sobie…'),
    'bio_edit_title': ('Edit your description', 'Edytuj swój opis'),
})

# ── the account page: one row in the Profile card ────────────────────────────
add('account', {
    'bio': ('Description', 'Opis'),
    'bio_none': ('Not written yet', 'Jeszcze nie napisany'),
    'bio_edit': ('Edit on your profile', 'Edytuj na swoim profilu'),
})

# ── what api/profile_bio.php and api/admin/user_bio.php answer ───────────────
add('api.bio', {
    'login_required': ('Sign in to write a description.', 'Zaloguj się, żeby napisać opis.'),
    'invalid': ('A description has to be text.', 'Opis musi być tekstem.'),
    'bad_encoding': ('That text is not valid UTF-8.', 'Ten tekst nie jest poprawnym UTF-8.'),
    'disabled': ('Profile descriptions are switched off on this site.', 'Opisy profili są na tej stronie wyłączone.'),
    'no_permission': ('Your account may not write a profile description.', 'Twoje konto nie może pisać opisu profilu.'),
    'muted': (
        'A moderator has silenced this account until :until, so the description cannot be changed before then. '
        'You can still remove it.',
        'Moderator wyciszył to konto do :until, więc do tego czasu nie można zmienić opisu. Można go jednak usunąć.'),
    'rate_limit': ('You are saving too often. Wait a while and try again.',
                   'Zapisujesz zbyt często. Odczekaj chwilę i spróbuj ponownie.'),
    'too_long': ('That description is too long: :n characters, and the limit is :max.',
                 'Ten opis ma za dużo znaków: :n, a limit to :max.'),
    'too_long_source': (
        'That description is too long once its tags are counted: at most :max characters, tags included.',
        'Ten opis jest za długi, gdy policzyć jego znaczniki: limit znaków razem ze znacznikami to :max.'),
    'too_many_lines': ('A description may have at most :max lines.', 'Za dużo wierszy: opis może mieć najwyżej :max.'),
    'too_many_links': ('Too many links: at most :max in a description.', 'Za dużo linków: w opisie dozwolone są najwyżej :max.'),
    'bad_link': ('A link has to be a full http:// or https:// address, without spaces.',
                 'Link musi być pełnym adresem http:// albo https://, bez spacji.'),
    'saved': ('Saved.', 'Zapisano.'),
    'cleared': ('The description was removed.', 'Opis został usunięty.'),
    'unknown_op': ('Unknown action.', 'Nieznana operacja.'),
    'admin_cleared': ('The description was cleared.', 'Opis został wyczyszczony.'),
    'admin_nothing': ('There was no description to clear.', 'Nie było opisu do wyczyszczenia.'),
})

# ── the note a member gets when a moderator clears theirs ────────────────────
add('notify', {
    'bio_cleared': ('A moderator removed your profile description', 'Moderator usunął opis Twojego profilu'),
    'bio_cleared_body': ('The text was deleted from the site. You can write a new one on your profile.',
                         'Tekst został usunięty ze strony. Nowy możesz napisać na swoim profilu.'),
})

# ── the editor (assets/js/profile-bio.js); `js.bio.` is in LANG_JS_PUBLIC ─────
add('js.bio', {
    'toolbar': ('Formatting', 'Formatowanie'),
    'bold': ('Bold (Ctrl+B)', 'Pogrubienie (Ctrl+B)'),
    'italic': ('Italic (Ctrl+I)', 'Kursywa (Ctrl+I)'),
    'underline': ('Underline', 'Podkreślenie'),
    'strike': ('Strikethrough', 'Przekreślenie'),
    'link': ('Link (Ctrl+K)', 'Odnośnik (Ctrl+K)'),
    'input': ('Your description', 'Twój opis'),
    'input_ph': ('A few words about yourself', 'Kilka słów o sobie'),
    'save': ('Save', 'Zapisz'),
    'save_title': ('Save (Ctrl+Enter)', 'Zapisz (Ctrl+Enter)'),
    'saving': ('Saving…', 'Zapisywanie…'),
    'cancel': ('Cancel', 'Anuluj'),
    'cancel_title': ('Cancel (Esc)', 'Anuluj (Esc)'),
    'count': (':n / :max', ':n / :max'),
    'count_title': (':n of :max characters, counted as a reader sees them',
                    'Znaki: :n z :max, liczone tak, jak widzi je czytelnik'),
    'failed': ('The description could not be saved. Please try again.',
               'Nie udało się zapisać opisu. Spróbuj ponownie.'),
})

# ── the panel: the user edit modal ───────────────────────────────────────────
# `js.users.` is the panel's (admin-users.js) and is not in LANG_JS_PUBLIC.
add('a.users', {
    'bio_label': ('Profile description', 'Opis profilu'),
    'bio_clear': ('Clear description', 'Wyczyść opis'),
    'bio_note': ('Cleared at once, not on Save: the text is deleted for good, and the member is told in a notification.',
                 'Czyszczony od razu, bez czekania na Zapisz: tekst jest kasowany na zawsze, a członek dostaje o tym powiadomienie.'),
    'bio_hidden': ('Not shown on their profile right now — descriptions are switched off, or their groups do not grant profile.bio.',
                   'Teraz niewidoczny na profilu — opisy są wyłączone albo grupy tego konta nie mają uprawnienia profile.bio.'),
})
add('js.users', {
    'bio_clear_title': ('Clear description', 'Wyczyść opis'),
    'bio_clear_q': ('Clear the profile description of :user? It is deleted for good, and they are told in a notification.',
                    'Wyczyścić opis profilu użytkownika :user? Zostanie skasowany na zawsze, a użytkownik dostanie powiadomienie.'),
    'bio_cleared': ('Cleared.', 'Wyczyszczono.'),
    'bio_failed': ('Something went wrong. Please try again.', 'Coś poszło nie tak. Spróbuj ponownie.'),
})
