# -*- coding: utf-8 -*-
"""A torrent's description: deleted, edited, credited, and listed on a profile (1.70.0, includes/content.php
and includes/profiledescs.php)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The Polish avoids the forms that have to guess somebody's gender ("napisałeś / napisałaś"): a
description is "twój opis", an edit "twoja edycja", and what somebody did is said of the text ("opis
zmieniono", "nie przyjęto"). A hidden name is "użytkownik" -- the plain word for somebody the page does
not name -- and the credit line starts "Autor opisu:", which reads the same before a name, before
"użytkownik" and before "usunięte konto". "Współautor" is the role word, as "Autor" is.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the line every description editor shows before anything is sent ────────────────
add('descs', {
    'public_named': (
        'A description is public: whoever can open this torrent reads it, with your name beside it — '
        '“Description by :name”. You can hide your name in <a href=":url">Privacy</a>.',
        'Opis jest publiczny: przeczyta go każdy, kto może otworzyć ten torrent, a obok będzie Twoja nazwa — '
        '„Autor opisu: :name”. Nazwę możesz ukryć w <a href=":url">ustawieniach prywatności</a>.'),
    'public_hidden': (
        'A description is public: whoever can open this torrent reads it. Your name is hidden — it says '
        '“a member” instead; you can change that in <a href=":url">Privacy</a>.',
        'Opis jest publiczny: przeczyta go każdy, kto może otworzyć ten torrent. Twoja nazwa jest ukryta — '
        'zamiast niej widać „użytkownik”; zmienisz to w <a href=":url">ustawieniach prywatności</a>.'),
    'public_anon': (
        'A description is public: whoever can open this torrent reads it.',
        'Opis jest publiczny: przeczyta go każdy, kto może otworzyć ten torrent.'),
    # the table's header (templates/partials/descs_section.php)
    'col_role': ('Role', 'Rola'),
    'col_role_title': (
        'The author, or a co-author with the share of the text their edits changed',
        'Autor albo współautor — przy współautorze: jaką część tekstu zmieniły edycje'),
    'col_date': ('Date', 'Data'),
    'col_date_title': (
        'When it was written, published or edited — in your time zone',
        'Kiedy go napisano, opublikowano albo zmieniono — w Twojej strefie czasowej'),
})

# ── the account page: the tab, its heading, the two privacy switches ────────────────
add('account', {
    'tab_descriptions': ('Descriptions', 'Opisy'),
    'descs_heading': ('Your descriptions', 'Twoje opisy'),
    'descs_public_label': ('Show the descriptions I wrote on my profile', 'Pokazuj na moim profilu listę moich opisów'),
    'descs_public_hint': (
        'Only published ones — yours and those you co-wrote with an edit — with your part in each. Off, nobody but '
        'you sees the list.',
        'Tylko opublikowane — Twoje i te, które współtworzysz edycjami — z Twoją rolą przy każdym. Wyłączone — tej '
        'listy nie widzi nikt poza Tobą.'),
    'descs_public_name_hidden': (
        'Your name is hidden on your descriptions right now, so this list is shown to nobody else either.',
        'Twoja nazwa jest teraz ukryta przy opisach, więc tej listy też nie widzi nikt poza Tobą.'),
    'credit_public_label': ('Show my name on the descriptions I write and edit', 'Pokazuj moją nazwę przy opisach, które piszę i zmieniam'),
    'credit_public_hint': (
        'Off, every description and every edit of yours says “a member” instead of your name — no link, no picture. '
        'Moderators still see who wrote it.',
        'Wyłączone — przy każdym Twoim opisie i każdej Twojej edycji zamiast nazwy widać „użytkownik”, bez linku '
        'i bez zdjęcia. Moderatorzy nadal widzą, kto jest autorem.'),
})

# ── the profile's section heading ───────────────────────────────────────────────────
add('profile', {
    'descriptions': ('Descriptions', 'Opisy'),
})

# ── Settings -> Profiles -> Descriptions on profiles ─────────────────────────────────
add('settings', {
    'profile_descs_heading': ('Descriptions on profiles', 'Opisy na profilach'),
    'profile_descs_intro': (
        'The torrents a member described — as the author, or as a co-author credited for an edit with the share '
        'of the text it changed — on a tab of their account page right after Likes / Ratings and, if they allow '
        'it, in a section of their public profile right after Likes / Ratings. A table that sorts and searches: '
        'the member sees theirs in every state, with their own proposals still waiting or turned down; everybody '
        'else only the published ones. It works only while descriptions or source links are switched on '
        '(<strong>Descriptions &amp; ratings</strong>). Showing the list to other members takes the '
        '<code>content.public</code> permission (Users → Groups; members have it), the member’s own yes in their '
        'privacy settings, which starts as no, and their name shown on their descriptions — a member who hides '
        'their name has no list for anybody else. Taking a description down from the Info panel is '
        '<code>content.delete_own</code> for its author (members have it) and <code>content.delete_any</code> '
        'for anybody’s published one (moderators have it).',
        'Torrenty, które członek opisał — jako autor albo jako współautor, któremu za edycję przypisano udział, '
        'czyli część zmienionego tekstu — na karcie jego strony konta, zaraz po Polubieniach / Ocenach, a jeśli '
        'na to pozwoli, także w sekcji jego publicznego profilu, zaraz po Polubieniach / Ocenach. To tabela, którą '
        'można sortować i przeszukiwać: członek widzi swoje opisy w każdym stanie, razem z własnymi propozycjami, '
        'które czekają albo których nie przyjęto; wszyscy inni — tylko opublikowane. Działa tylko wtedy, gdy '
        'opisy albo linki źródłowe są włączone (<strong>Opisy i oceny</strong>). Pokazanie listy innym członkom '
        'wymaga uprawnienia <code>content.public</code> (Użytkownicy → Grupy; członkowie je mają), zgody samego '
        'członka w ustawieniach prywatności, która na początku jest wyłączona, oraz jego nazwy widocznej przy '
        'opisach — kto ukrywa nazwę, nie ma listy dla nikogo innego. Usunięcie opisu z panelu Info to '
        'uprawnienie <code>content.delete_own</code> dla jego autora (członkowie je mają) i '
        '<code>content.delete_any</code> dla każdego opublikowanego opisu (mają je moderatorzy).'),
    'profile_descriptions_enabled': ('Descriptions on profiles', 'Opisy na profilach'),
    'profile_descriptions_enabled_hint': (
        'Off hides the tab and the profile section everywhere. Nothing is deleted.',
        'Wyłączenie ukrywa kartę i sekcję profilu wszędzie. Nic nie jest usuwane.'),
    'profile_descs_content_off': (
        'Descriptions and source links are both switched off right now, so nothing of this is shown anywhere.',
        'Opisy i linki źródłowe są teraz wyłączone, więc nic z tego nie jest nigdzie pokazywane.'),
})

# ── the answers of the endpoints (includes/content.php, api/content_delete.php) ────────
add('api.content', {
    'edit_unchanged': (
        'That is the text as it stands — change something first.',
        'To jest tekst w obecnej postaci — najpierw coś w nim zmień.'),
    'edit_not_allowed': (
        'Only a published description can be edited, by a signed-in member who may read it.',
        'Edytować można tylko opublikowany opis, będąc zalogowanym członkiem, który może go czytać.'),
    'delete_denied': ('You may not delete this description.', 'Nie możesz usunąć tego opisu.'),
    'nothing_to_delete': ('There is no description here to delete.', 'Nie ma tu opisu do usunięcia.'),
    'deleted_own': ('Your description is deleted.', 'Twój opis został usunięty.'),
    'deleted_any': ('The description is deleted, and its author is told.', 'Opis został usunięty, a jego autor dostanie o tym powiadomienie.'),
    'edit_applied': (
        'Edit applied: it changed :pct% of the text. The author stays; the editor is credited beside them.',
        'Edycja zastosowana: zmieniła :pct% tekstu. Autor zostaje, a obok niego widać edytującego.'),
})

# ── what the people involved are told (includes/content.php) ────────────────────────
add('notify', {
    'content_edit_applied': ('Your edit of ":name" was accepted', 'Twoja edycja opisu „:name” została przyjęta'),
    'content_edit_applied_body': (
        'It changed :pct% of the description, and you are credited beside its author.',
        'Zmieniła :pct% opisu, a pod opisem widać teraz Twój udział obok autora.'),
    'content_edited': ('Your description of ":name" was edited', 'Twój opis „:name” został zmieniony'),
    'content_edited_body': (
        'A moderator accepted an edit that changed :pct% of it. You are still its author.',
        'Moderator przyjął edycję, która zmieniła :pct% tekstu. Autorstwo zostaje przy Tobie.'),
    'content_edit_rejected': ('Your edit of ":name" was not accepted', 'Twoja edycja opisu „:name” nie została przyjęta'),
    'content_deleted': ('Your description of ":name" was deleted', 'Twój opis „:name” został usunięty'),
    'content_deleted_body': (
        'A moderator took it down, with its source link. Proposals waiting for it were withdrawn.',
        'Moderator go usunął, razem z linkiem źródłowym. Propozycje zmian, które na niego czekały, wycofano.'),
    'content_proposal_withdrawn': ('Your proposal for ":name" was withdrawn', 'Twoja propozycja dla „:name” została wycofana'),
    'content_proposal_withdrawn_body': (
        'The description it would have changed was deleted, so there is nothing left for it to change.',
        'Opis, który miała zmienić, został usunięty, więc nie ma już czego zmieniać.'),
})

# ── the Info panel (assets/js/app.js) ───────────────────────────────────────────────
add('js.app', {
    'desc_by_many': ('Description by', 'Autorzy opisu:'),
    'credit_first': ('first', 'pierwsza wersja'),
    'credit_edit': (':pct% edit', 'edycja :pct%'),
    'credit_hidden': ('a member', 'użytkownik'),
    'credit_hidden_you': ('a member (you)', 'użytkownik (Ty)'),
    'credit_deleted': ('a deleted account', 'usunięte konto'),
    'credit_more_title': ('Show everyone', 'Pokaż wszystkich'),
    'desc_propose_title': (
        'Write a description of your own to replace this one — if a moderator accepts it, you become its author',
        'Napisz własny opis zamiast tego — jeśli moderator go przyjmie, zostaniesz jego autorem'),
    'desc_edit': ('Edit', 'Edytuj'),
    'desc_edit_title': (
        'Change this description: the editor opens with its text, and a moderator decides. Its author stays, and '
        'you are credited with the share you changed',
        'Zmień ten opis: edytor otworzy się z jego tekstem, a zdecyduje moderator. Autor zostaje, a Tobie zostanie '
        'przypisany udział w zmianach'),
    'desc_delete': ('Delete description', 'Usuń opis'),
    'desc_delete_sure': ('Click again to delete', 'Kliknij ponownie, aby usunąć'),
    'desc_delete_title_own': (
        'Delete your description and its source link. This cannot be undone.',
        'Usuń swój opis razem z linkiem źródłowym. Tego nie da się cofnąć.'),
    'desc_delete_title_any': (
        'Delete this description and its source link; its author is told. This cannot be undone.',
        'Usuń ten opis razem z linkiem źródłowym; jego autor dostanie powiadomienie. Tego nie da się cofnąć.'),
    'desc_deleted': ('Deleted.', 'Usunięto.'),
})

# ── the descriptions table (assets/js/favourites.js, initDescs()) ───────────────────
add('js.descs', {
    'role_author': ('Author', 'Autor'),
    'role_coauthor': ('Co-author, :pct%', 'Współautor, :pct%'),
    'role_edit': ('Edit', 'Edycja'),
    'role_rewrite': ('Rewrite', 'Poprawka'),
    'st_pending': ('waiting for a moderator', 'czeka na moderatora'),
    'st_rejected': ('not published', 'nie opublikowano'),
    'st_declined': ('not accepted', 'nie przyjęto'),
    'st_withdrawn': ('withdrawn: the description was deleted', 'wycofano: opis usunięto'),
    'total': ('Found: :n', 'Znaleziono: :n'),
    'none_own': (
        'Nothing here yet. A description you add in a torrent’s Info panel — or an edit of one — puts it here.',
        'Na razie pusto. Opis dodany w panelu Info torrenta — albo edycja opisu — trafia tutaj.'),
    'none_match': ('Nothing matches this search.', 'Nic nie pasuje do tego wyszukiwania.'),
    'excerpt_source': ('Source link: :url', 'Link źródłowy: :url'),
})

# ── the panel's Rewrites tab (assets/js/admin-whitelist.js) ─────────────────────────
add('js.wl', {
    'name_hidden': ('name hidden publicly', 'nazwa ukryta publicznie'),
    'name_hidden_title': (
        'This member hides their name on descriptions: the public pages say “a member”. You see who it is because '
        'you moderate it.',
        'Ten członek ukrywa nazwę przy opisach: strony publiczne pokazują „użytkownik”. Widzisz, kto to, bo to moderujesz.'),
    'edit_kind': ('edit', 'edycja'),
    'edit_kind_title': (
        'An edit: applied, the author stays and the editor is credited with the share of the text it changes',
        'Edycja: po zastosowaniu autor zostaje, a edytującemu przypisuje się udział — część tekstu, którą zmienia'),
    'edit_share': ('Changes :pct% of the text as it stands now.', 'Zmienia :pct% obecnego tekstu.'),
    'edit_author_stays': ('The author (:user) stays; the editor is credited beside them.', 'Autor (:user) zostaje, a obok niego pojawi się edytujący.'),
    'edit_as_rewrite': (
        'An edit of a description that has since been cleared: applied, it goes in as a rewrite — its proposer becomes the author.',
        'Edycja opisu, który w międzyczasie wyczyszczono: po zastosowaniu wchodzi jak poprawka — jej autor zostaje autorem opisu.'),
    'rewrite_line': ('A rewrite: applied, its proposer becomes the author.', 'Poprawka: po zastosowaniu jej autor zostaje autorem opisu.'),
    'apply_edit_title': ('Apply this edit', 'Zastosuj tę edycję'),
    'apply_edit_body': (
        'The published description is replaced by the edited one, which changes :pct% of it. Its author stays; the editor is credited beside them.',
        'Opublikowany opis zostanie zastąpiony zmienioną wersją, która zmienia :pct% tekstu. Autor zostaje, a obok niego pojawi się edytujący.'),
})
