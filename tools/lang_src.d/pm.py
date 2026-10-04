# -*- coding: utf-8 -*-
"""Private messages

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── recovered 2026-09-13 ────────────────────────────────────────────────────
# These strings were added straight to lang/en.php and lang/pl.php between 1.43 and 1.50 and never to
# these sources, so the first regeneration since then (1.50.1) dropped all of them. They are written
# back here, where the generator reads from. tests/lang_test.php now checks that the generated files
# match the sources, so a string added to the generated file alone fails the battery on the spot.
add('', {
    'pm.new': ('New message',
        'Nowa wiadomość'),
    'pm.new_go': ('Open',
        'Otwórz'),
    'pm.new_ph': ('Who to?',
        'Do kogo?'),
    'pm.note': ('Messages use the same formatting as descriptions, and this tracker allows :n of them a day per account.',
        'Wiadomości używają tego samego formatowania co opisy, a ten tracker pozwala na :n dziennie na konto.'),
    'pm.report_head': ('Report this message',
        'Zgłoś tę wiadomość'),
    'pm.report_note': ('A moderator sees <strong>this message and the one before it</strong> — not the rest of the conversation. Say what is wrong with it; a line or two is enough.',
        'Moderator zobaczy <strong>tę wiadomość i jedną poprzedzającą</strong> — nie resztę rozmowy. Napisz, co jest z nią nie tak; wystarczy linijka albo dwie.'),
    'pm.report_ph': ('What is wrong with it?',
        'Co jest z nią nie tak?'),
    'pm.report_send': ('Send the report',
        'Wyślij zgłoszenie'),
    'pm.search_deep': ('Search inside messages',
        'Szukaj w treści wiadomości'),
    'pm.search_deep_title': ('Look through what was written, not only who wrote it',
        'Przeszukuj to, co napisano, nie tylko kto napisał'),
    'pm.search_ph': ('Filter conversations…',
        'Filtruj rozmowy…'),
})


# ── the Archive and the Trash (1.73.0) ──────────────────────────────────────────────────────────────
# The owner: "a message bin, maybe… I clicked the archive button and the conversation was gone… and a
# confirmation is missing, it vanishes at once and I cannot bring it back — and after confirming, 3-5 seconds
# to take it back". Three places per side of a conversation (includes/people.php), the site's own in-place
# question before a Delete, and a toast with Undo after every move.

# The three tabs above the list, the notes under them, and the Trash's one button — drawn by
# templates/pages/account.php, so the live language switch swaps them with the page.
add('pm', {
    'views_label': ('Where your conversations are', 'Gdzie są twoje rozmowy'),
    'view_inbox': ('Inbox', 'Odebrane'),
    'view_archive': ('Archive', 'Archiwum'),
    'view_trash': ('Trash', 'Kosz'),
    'note_archive': ('Archived conversations wait here — nothing is deleted. A new message brings one back to the Inbox.',
                     'Zarchiwizowane rozmowy czekają tutaj — nic nie jest usuwane. Nowa wiadomość przywraca rozmowę do Odebranych.'),
    'note_archive_stays': ('Archived conversations wait here — nothing is deleted. A new message stays here too, marked unread.',
                           'Zarchiwizowane rozmowy czekają tutaj — nic nie jest usuwane. Nowa wiadomość też zostaje tutaj, oznaczona jako nieprzeczytana.'),
    'note_trash_one': ('A deleted conversation waits here for 1 day, then it is deleted for good — for you only: the other person keeps their copy.',
                       'Usunięta rozmowa czeka tu 1 dzień, potem jest usuwana na dobre — tylko dla ciebie: druga strona zachowuje swoją kopię.'),
    'note_trash_many': ('A deleted conversation waits here for :days days, then it is deleted for good — for you only: the other person keeps their copy.',
                        'Usunięta rozmowa czeka tu :days dni, potem jest usuwana na dobre — tylko dla ciebie: druga strona zachowuje swoją kopię.'),
    'note_trash_none': ('This tracker keeps no Trash any more: Delete deletes at once. What is still here is deleted for good within a minute.',
                        'Ten tracker nie ma już kosza: „Usuń” usuwa od razu. To, co tu jeszcze jest, zostanie usunięte na dobre w ciągu minuty.'),
    'empty_trash': ('Empty the Trash', 'Opróżnij kosz'),
    # read out before a tab's unread number, and not drawn (.pm-sr): "Archive (3) Unread: 2"
    'unread_sr': ('Unread: ', 'Nieprzeczytane: '),
})

# The two settings (Settings → People: messages, friends, directory).
add('settings', {
    'pm_archive_returns': ('A new message in an archived conversation',
                           'Nowa wiadomość w zarchiwizowanej rozmowie'),
    'pm_archive_returns_yes': ('brings it back to the Inbox', 'przywraca ją do Odebranych'),
    'pm_archive_returns_no': ('leaves it in the Archive, unread', 'zostawia ją w archiwum, nieprzeczytaną'),
    'pm_archive_returns_hint': ('The first is what hiding a conversation always did. With the second a new message waits in the Archive: the Archive tab shows it as unread, and it is counted in the number on the account link, so nothing is missed. Writing in an archived conversation always brings it back for the writer.',
                                'Pierwsze to dotychczasowe działanie ukrywania rozmowy. Przy drugim nowa wiadomość czeka w archiwum: zakładka Archiwum pokazuje ją jako nieprzeczytaną i jest liczona w liczbie przy linku konta, więc nic nie umyka. Pisanie w zarchiwizowanej rozmowie zawsze przywraca ją piszącemu.'),
    'pm_trash_days': ('Days in the Trash', 'Dni w koszu'),
    'pm_trash_days_hint': ('How long a deleted conversation can still be restored, then it is deleted for good — for the member who deleted it; the other person keeps their copy. <strong>0 = no Trash</strong>: Delete deletes at once. 1 to 365.',
                           'Jak długo usuniętą rozmowę można jeszcze przywrócić; potem jest usuwana na dobre — dla osoby, która ją usunęła; druga strona zachowuje swoją kopię. <strong>0 = bez kosza</strong>: „Usuń” usuwa od razu. Od 1 do 365.'),
})

# assets/js/people.js — what the rows, the conversation's head and its bar say. Every one of these is written
# through the file's i18n helper, which keeps the key beside the text so the live language switch can say it
# again in the other language (the conversation's old Clear button kept its tooltip in the first one).
add('js.pm', {
    # the actions: a button's name (aria-label) and what pressing it does (data-tip)
    'act_archive': ('Archive', 'Archiwizuj'),
    'act_archive_tip': ('Move the conversation with :user to the Archive — nothing is deleted',
                        'Przenieś rozmowę z :user do archiwum — nic nie zostanie usunięte'),
    'act_unarchive': ('Move to inbox', 'Przenieś do Odebranych'),
    'act_unarchive_tip': ('Move the conversation with :user back to the Inbox',
                          'Przenieś rozmowę z :user z powrotem do Odebranych'),
    'act_trash': ('Delete', 'Usuń'),
    'act_trash_tip': ('Delete the conversation with :user — it goes to the Trash first, and you can restore it',
                      'Usuń rozmowę z :user — najpierw trafi do kosza i można ją przywrócić'),
    'act_trash_final_tip': ('Delete the conversation with :user for good, for you — they keep their copy',
                            'Usuń rozmowę z :user na dobre, dla siebie — druga strona zachowa swoją kopię'),
    'act_restore': ('Restore', 'Przywróć'),
    'act_restore_tip': ('Restore the conversation with :user from the Trash',
                        'Przywróć rozmowę z :user z kosza'),
    'act_purge': ('Delete forever', 'Usuń na zawsze'),
    'act_purge_tip': ('Delete the conversation with :user forever — this cannot be undone',
                      'Usuń rozmowę z :user na zawsze — tego nie da się cofnąć'),
    # the site's in-place question (assets/js/app.js askInPlace) before anything leaves a place for good or for the Trash
    'q_trash': ('Delete it? It goes to the Trash.', 'Usunąć? Trafi do kosza.'),
    'q_trash_final': ('Delete it for good? This cannot be undone.', 'Usunąć na dobre? Tego nie da się cofnąć.'),
    'q_purge': ('Delete it forever? This cannot be undone.', 'Usunąć na zawsze? Tego nie da się cofnąć.'),
    'q_empty': ('Empty the Trash? Everything in it is deleted forever — this cannot be undone.',
                'Opróżnić kosz? Wszystko, co w nim jest, zostanie usunięte na zawsze — tego nie da się cofnąć.'),
    # the toast after a move, with its Undo
    't_archived': ('The conversation with :user is in the Archive.', 'Rozmowa z :user jest w archiwum.'),
    't_unarchived': ('The conversation with :user is back in the Inbox.', 'Rozmowa z :user wróciła do Odebranych.'),
    't_trashed': ('The conversation with :user is in the Trash.', 'Rozmowa z :user jest w koszu.'),
    't_deleted': ('The conversation with :user is deleted for good.', 'Rozmowa z :user została usunięta na dobre.'),
    't_restored_inbox': ('The conversation with :user is back in the Inbox.', 'Rozmowa z :user wróciła do Odebranych.'),
    't_restored_archive': ('The conversation with :user is back in the Archive.', 'Rozmowa z :user wróciła do archiwum.'),
    't_emptied': ('The Trash is empty — conversations deleted for good: :n.', 'Kosz jest pusty — rozmowy usunięte na dobre: :n.'),
    't_undone': ('Undone.', 'Cofnięto.'),
    't_stale': ('Nothing to undo any more — it has changed since.', 'Nie ma już czego cofnąć — w międzyczasie coś się zmieniło.'),
    # the bar under a conversation's head: where it is, and its way back
    'bar_archive': ('This conversation is in your Archive.', 'Ta rozmowa jest w twoim archiwum.'),
    'bar_trash': ('This conversation is in your Trash — it will be deleted for good on :date.',
                  'Ta rozmowa jest w twoim koszu — zostanie usunięta na dobre :date.'),
    'bar_trash_plain': ('This conversation is in your Trash.', 'Ta rozmowa jest w twoim koszu.'),
    'bar_older': ('Earlier messages of this conversation are in your Trash (:n).',
                  'Wcześniejsze wiadomości tej rozmowy są w twoim koszu (:n).'),
    'bar_restore_older': ('Restore them', 'Przywróć je'),
    # the places' empty lists
    'no_archive': ('Nothing in the Archive.', 'Archiwum jest puste.'),
    'no_trash': ('The Trash is empty.', 'Kosz jest pusty.'),
})

# The site's toast (assets/js/app.js siteToast, 1.73.0): a line at the foot of the window with Undo, shared by
# whatever page wants one — the messages are the first. `js.common.`, the part of the bundle every public page
# carries (includes/lang.php LANG_JS_PUBLIC).
add('js.common', {
    'toast_region': ('Notices', 'Powiadomienia'),
    'toast_undo': ('Undo', 'Cofnij'),
    'toast_close': ('Dismiss', 'Zamknij'),
})
