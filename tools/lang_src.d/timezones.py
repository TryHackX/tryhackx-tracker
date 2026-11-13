# -*- coding: utf-8 -*-
"""Time-zone regions (1.73.0, part F)

One area of the dictionary. Every string is an (English, Polish) pair and both lang/*.php files are
generated from all of these together -- see tools/lang_src.py.

The four time-zone selects (the account page; Settings -> Site, the schedule's zone, the backups' zone)
group every zone PHP knows under its region -- the IANA name's first part, "Europe" in Europe/Warsaw,
and "Other" for the one zone without a region (UTC). Those group labels were English on a Polish page;
includes/db_clock.php tzRegionLabel() says them through tz.region_<region>. The English is IANA's own
region word (what the zone names under it start with); the zone names themselves stay IANA's ids in
both languages -- they are what is stored, and a translated city list would be a second tz database.

Polish: the continents and oceans by their Polish names; "Indian" (the islands of the Indian Ocean:
Indian/Maldives, Indian/Mauritius...) is the ocean's full name, because "Indyjski" alone says nothing.
"""

S = {}


def add(prefix, pairs):
    for k, v in pairs.items():
        key = prefix + '.' + k if prefix else k
        assert key not in S, 'duplicate key ' + key
        assert isinstance(v, tuple) and len(v) == 2, key
        S[key] = v


add('tz', {
    'region_africa':     ('Africa', 'Afryka'),
    'region_america':    ('America', 'Ameryka'),
    'region_antarctica': ('Antarctica', 'Antarktyda'),
    'region_arctic':     ('Arctic', 'Arktyka'),
    'region_asia':       ('Asia', 'Azja'),
    'region_atlantic':   ('Atlantic', 'Atlantyk'),
    'region_australia':  ('Australia', 'Australia'),
    'region_europe':     ('Europe', 'Europa'),
    'region_indian':     ('Indian', 'Ocean Indyjski'),
    'region_pacific':    ('Pacific', 'Pacyfik'),
    'region_other':      ('Other', 'Inne'),
})
