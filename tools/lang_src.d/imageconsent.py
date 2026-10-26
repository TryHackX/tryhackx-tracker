S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('js.wl', {
    'cl_consent_yes': ('the partner allows copying', 'partner pozwala kopiować'),
    'cl_consent_no': ('the partner refuses copying', 'partner nie pozwala kopiować'),
    'cl_consent_none': ('the partner has not allowed copying', 'partner nie pozwolił kopiować'),
})

add('apidocs', {
    'images_consent': (
        'Pictures: if this tracker copies pictures from your site (the image domains set for your key), it does so only '
        'while you allow it. Send <code>"copy_images": true</code> — or <code>false</code> to withdraw it — at the top level '
        'of <code>v1/whitelist/submit</code> or <code>v1/whitelist/describe</code>, or <code>copy_images=1</code> with '
        '<code>v1/whitelist/ping</code>. The replies of all three say <code>"images"</code>: <code>copy</code> (your '
        'pictures are copied), <code>off</code> (this tracker copies none from you) or <code>no_consent</code> (it would, '
        'but you have not allowed it), with the domains in <code>"image_domains"</code>.',
        'Obrazy: jeśli ten tracker kopiuje obrazy z Twojej strony (domeny obrazów ustawione przy Twoim kluczu), robi to '
        'tylko za Twoją zgodą. Wyślij <code>"copy_images": true</code> — albo <code>false</code>, żeby ją cofnąć — na '
        'najwyższym poziomie <code>v1/whitelist/submit</code> lub <code>v1/whitelist/describe</code>, albo '
        '<code>copy_images=1</code> razem z <code>v1/whitelist/ping</code>. Odpowiedzi wszystkich trzech podają '
        '<code>"images"</code>: <code>copy</code> (Twoje obrazy są kopiowane), <code>off</code> (ten tracker nie kopiuje '
        'od Ciebie niczego) albo <code>no_consent</code> (kopiowałby, ale brak Twojej zgody), a domeny w '
        '<code>"image_domains"</code>.'),
})
