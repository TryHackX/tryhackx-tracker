# -*- coding: utf-8 -*-
"""The sign-in bridge (v49): settings, the login page, the account page, the panel.

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


# ── settings page ───────────────────────────────────────────────────────
add('settings', {
    'bridge_heading': ('Sign-in bridge', 'Mostek logowania'),
    'bridge_intro': ('One community, two sites, one account. A forum holding an API key can sign its '
                     'people in here — making the account the first time — and somebody signed in here '
                     'can be sent to the forum already signed in. Neither side ever sees the other\'s '
                     'password; what crosses is a one-time ticket worth nothing a minute later.',
                     'Jedna społeczność, dwie strony, jedno konto. Forum z kluczem API może zalogować '
                     'tutaj swoich ludzi — zakładając konto przy pierwszym razie — a osoba zalogowana '
                     'tutaj może trafić na forum już zalogowana. Żadna strona nie widzi hasła drugiej; '
                     'przechodzi tylko jednorazowy bilet, bezwartościowy minutę później.'),
    'bridge_warning': ('<strong>This is the strongest switch on this page.</strong> With the bridge on, '
                       'anybody holding a key with the <code>users</code> scope can say “this browser is '
                       'user 412” and be believed. Give that key to the forum and to nothing else, and '
                       'turn this off the moment you stop using it.',
                       '<strong>To najmocniejszy przełącznik na tej stronie.</strong> Przy włączonym '
                       'mostku każdy, kto ma klucz z zakresem <code>users</code>, może powiedzieć „ta '
                       'przeglądarka to użytkownik 412” i zostanie mu to uwierzone. Daj ten klucz forum '
                       'i niczemu więcej, a gdy przestaniesz go używać — wyłącz to.'),
    'bridge_enabled': ('Bridge', 'Mostek'),
    'bridge_enabled_hint': ('Off, all five v1/auth/* endpoints answer 503.',
                            'Wyłączony — wszystkie pięć endpointów v1/auth/* odpowiada 503.'),
    'bridge_create': ('Create accounts', 'Zakładanie kont'),
    'bridge_create_hint': ('Off, only people who already have a linked account can come through.',
                           'Wyłączone — przejdą tylko ci, którzy już mają powiązane konto.'),
    'bridge_merge': ('Claim existing accounts', 'Przejmowanie istniejących kont'),
    'bridge_merge_none': ('Never (recommended)', 'Nigdy (zalecane)'),
    'bridge_merge_email': ('When the address is verified here', 'Gdy adres jest tu potwierdzony'),
    'bridge_merge_hint': ('Whether a forum identity may take over a local account sharing its email '
                          'address. <strong>Never</strong> by default: a key that can assert any address '
                          'can assert the administrator\'s. Turn it on only where every address was '
                          'confirmed on this site.',
                          'Czy tożsamość z forum może przejąć lokalne konto o tym samym adresie e-mail. '
                          'Domyślnie <strong>nigdy</strong>: klucz, który może podać dowolny adres, może '
                          'podać adres administratora. Włącz tylko tam, gdzie każdy adres został '
                          'potwierdzony na tej stronie.'),
    'bridge_ttl': ('Ticket lifetime', 'Ważność biletu'),
    'bridge_ttl_hint': ('Seconds. It only has to survive one redirect. 30–900.',
                        'Sekundy. Musi przetrwać tylko jedno przekierowanie. 30–900.'),
    'bridge_login_url': ('The forum\'s sign-in page', 'Strona logowania forum'),
    'bridge_login_url_hint': ('Offered on this site\'s login form as “sign in with the forum”. Empty = not offered.',
                              'Pokazywana na formularzu logowania jako „zaloguj przez forum”. Puste = nie pokazujemy.'),
    'bridge_return_url': ('Where “continue to the forum” goes', 'Dokąd prowadzi „przejdź na forum”'),
    'bridge_return_url_hint': ('The forum receives <code>?thx_token=…</code> here and posts it back to '
                               '<code>v1/auth/verify</code>. Empty switches the outbound half off.',
                               'Forum dostaje tu <code>?thx_token=…</code> i odsyła go do '
                               '<code>v1/auth/verify</code>. Puste wyłącza kierunek wychodzący.'),
    'bridge_logout': ('Two-way sign-out', 'Wylogowanie w obie strony'),
    'bridge_logout_hint': ('Signing out on one side ends the bridged session on the other.',
                           'Wylogowanie po jednej stronie kończy mostkowaną sesję po drugiej.'),
    'bridge_endpoints': ('Endpoints: <code>v1/auth/login</code>, <code>v1/auth/logout</code>, '
                         '<code>v1/auth/verify</code>, <code>v1/auth/merge</code>, '
                         '<code>v1/auth/status</code> — all on the <code>users</code> scope. The '
                         'integration guide for a key is on the key itself, in the Whitelist panel.',
                         'Endpointy: <code>v1/auth/login</code>, <code>v1/auth/logout</code>, '
                         '<code>v1/auth/verify</code>, <code>v1/auth/merge</code>, '
                         '<code>v1/auth/status</code> — wszystkie w zakresie <code>users</code>. '
                         'Instrukcja integracji dla klucza jest przy samym kluczu, w panelu Whitelisty.'),
})

# ── the public site ─────────────────────────────────────────────────────
add('bridge', {
    # Used when more than one key could be the bridge, so no specific label is the right one.
    'provider_default': ('the forum', 'forum'),
    'sign_in_with': ('Sign in with :name', 'Zaloguj przez :name'),
    'or': ('or', 'albo'),
    'failed': ('That sign-in link has expired or was already used. Ask the forum to send you over again.',
               'Ten link logowania wygasł albo został już użyty. Poproś forum o ponowne przesłanie.'),
    'continue_to': ('Continue to :name', 'Przejdź na :name'),
    'continue_hint': ('You will arrive already signed in.', 'Trafisz tam już zalogowana/y.'),
    'linked_heading': ('Where this account signs in from', 'Skąd loguje się to konto'),
    # The reason the source is shown at all: an account somebody did not create here should say so
    # to the person holding it, not only to the operator.
    'linked_via': ('This account is linked to :name.', 'To konto jest powiązane z :name.'),
    'linked_as': ('there you are :name', 'tam jesteś jako :name'),
    'linked_since': ('linked :date', 'powiązane :date'),
    'not_linked': ('This account signs in here only, with its own password.',
                   'To konto loguje się tylko tutaj, własnym hasłem.'),
})

# ── the panel ───────────────────────────────────────────────────────────
add('a.users', {
    'col_source': ('Signs in via', 'Loguje się przez'),
    'source_local': ('here', 'tutaj'),
    'source_title': ('Where this account can sign in from', 'Skąd to konto może się logować'),
})
add('js.users', {
    'bridge_via': ('via :name', 'przez :name'),
    'bridge_local': ('here', 'tutaj'),
    # See the note in apidocs.py: the JS bundle is `js.` only, so this is a deliberate duplicate of
    # a.users.source_title rather than a widening of the bundle.
    'bridge_title': ('Where this account can sign in from', 'Skąd to konto może się logować'),
})
