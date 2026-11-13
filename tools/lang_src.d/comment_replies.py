# -*- coding: utf-8 -*-
"""Replies to comments, as a tree with a depth the operator sets (1.72.0, part C)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The owner: "a permission to reply to comments ... and a setting for how deep the tree of replies may be --
Reddit-like, e.g. three rows". The Info panel's Reply button and the composer under a comment
(assets/js/comments.js), the thread's fold and its "Show more", the places a comment that went keeps
(tombstones), what the endpoints answer (includes/comments.php), the notification a reply leaves (in the
RECIPIENT's language), the account page's fifth switch and the reply's sound, and Settings -> Descriptions,
comments & ratings -> Comments -> Reply depth.

Polish: the forms that name a gender are avoided as the rest of the site's Polish does ("odpowiadasz",
"odpowiedź od :user"); a tombstone agrees with "komentarz" ("usunięty przez moderatora"), or is impersonal
("usunięto"). "odpowiedzi" is both the plural after 2-4 and the genitive after 5+, so only "one" needs its
own string.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


# ── the Info panel: the Reply button, the composer under a comment, the tree ──
add('js.comments', {
    # the icon button (its name, then the tooltip)
    'reply':             ('Reply', 'Odpowiedz'),
    'reply_tip':         ('Reply to this comment', 'Odpowiedz na ten komentarz'),
    # the composer under the comment
    'replying_to':       ('Replying to :name', 'Odpowiadasz: :name'),
    'reply_cancel':      ('Cancel the reply', 'Anuluj odpowiedź'),
    'reply_placeholder': ('Write a reply… (@name mentions somebody)', 'Napisz odpowiedź… (@nazwa wspomina o kimś)'),
    'reply_placeholder_plain': ('Write a reply…', 'Napisz odpowiedź…'),
    'reply_send':        ('Reply', 'Odpowiedz'),
    # a reply drawn at the deepest level allowed, below where its parent stands (a lowered depth)
    'reply_to':          ('in reply to :name', 'w odpowiedzi na komentarz: :name'),
    'reply_to_gone':     ('in reply to a comment that is gone', 'w odpowiedzi na komentarz, którego już nie ma'),
    # a comment that went while replies hang from it
    'tomb_deleted':      ('[deleted]', '[usunięto]'),
    'tomb_removed':      ('[removed by a moderator]', '[usunięty przez moderatora]'),
    # the thread's fold, and the rest of a long thread
    'fold_hide':         ('Hide replies', 'Ukryj odpowiedzi'),
    'fold_show':         ('Show :n replies', 'Pokaż :n odpowiedzi'),
    'fold_show_one':     ('Show 1 reply', 'Pokaż 1 odpowiedź'),
    'more_replies':      ('Show :n more replies', 'Pokaż jeszcze :n odpowiedzi'),
    'more_replies_one':  ('Show 1 more reply', 'Pokaż jeszcze 1 odpowiedź'),
})


# ── what the endpoints answer (includes/comments.php) ──────────────────────
add('api.comment', {
    'replies_off':       ('Replies are switched off on this site.', 'Odpowiedzi są na tej stronie wyłączone.'),
    'no_reply':          ('Your account may not reply to comments.', 'Twoje konto nie może odpowiadać na komentarze.'),
    'parent_gone':       ('The comment you are replying to is not there any more.', 'Komentarza, na który odpowiadasz, już nie ma.'),
    'parent_pending':    ('That comment is waiting for a moderator: it can be answered once it is let through.',
                          'Ten komentarz czeka na moderatora: można na niego odpowiedzieć, gdy zostanie dopuszczony.'),
    'too_deep':          ('This thread is as deep as it may go (:max levels of replies) — reply to a comment above instead.',
                          'Ten wątek jest już tak głęboki, jak może być (poziomy odpowiedzi: :max) — odpowiedz na komentarz wyżej.'),
    'replied':           ('Your reply is up.', 'Odpowiedź dodana.'),
    'replied_pending':   ('Thank you — your reply will appear once a moderator lets it through.',
                          'Dziękujemy — odpowiedź pojawi się, gdy dopuści ją moderator.'),
})


# ── the notification a reply leaves, in the recipient's language ─────────────
add('notify', {
    'comment_reply':     (':user replied to your comment on ":name"', 'Odpowiedź od :user na Twój komentarz pod „:name”'),
})


# ── the account page: the fifth switch, and the reply's sound ────────────────
add('account', {
    'comment_pref_reply':    ('…that replies to one of my comments', '…który odpowiada na mój komentarz'),
    'snd_ev_comment_reply':  ('Somebody replies to my comment', 'Ktoś odpowiada na mój komentarz'),
})


# ── Settings → Descriptions, comments & ratings → Comments; → Sounds ────────
add('settings', {
    'comments_reply_depth':      ('Reply depth', 'Głębokość odpowiedzi'),
    'comments_reply_depth_hint': ('How many levels of replies a thread may have under a comment: 1 = replies to a comment, '
                                  '2 = replies to those too, 3 (as shipped) = one level more; 0 = no replies — a flat '
                                  'thread, as before; at most :max. <strong>At the limit</strong> the deepest replies have no '
                                  'Reply button and a reply sent anyway is refused: the conversation goes on under the '
                                  'comment above them. Lowered later, the replies already written deeper stay, drawn at '
                                  'the deepest level allowed (each says whom it answers); only new ones are refused. Who '
                                  'may reply is the permission <code>comment.reply</code>.',
                                  'Ile poziomów odpowiedzi może mieć wątek pod komentarzem: 1 = odpowiedzi na komentarz, '
                                  '2 = także odpowiedzi na nie, 3 (domyślnie) = jeszcze jeden poziom; 0 = bez odpowiedzi — '
                                  'płaski wątek, jak dotąd; najwyżej :max. <strong>Na granicy</strong> najgłębsze odpowiedzi '
                                  'nie mają przycisku Odpowiedz, a odpowiedź wysłana mimo to jest odrzucana: rozmowa toczy '
                                  'się dalej pod komentarzem nad nimi. Po zmniejszeniu odpowiedzi napisane głębiej zostają i '
                                  'są pokazywane na najgłębszym dozwolonym poziomie (każda mówi, na co odpowiada); odrzucane '
                                  'są tylko nowe. Kto może odpowiadać, decyduje uprawnienie <code>comment.reply</code>.'),
    'sounds_default_comment_reply': ('Default for a reply to a comment', 'Domyślny dla odpowiedzi na komentarz'),
    'sounds_ev_comment_reply':   ('Reply to my comment', 'Odpowiedź na mój komentarz'),
})
