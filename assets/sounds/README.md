# Shipped notification sounds

Short clips a member can pick on the account page's **Sounds** tab (includes/sounds.php lists this
directory; a file is a sound when its name matches `^[a-z0-9][a-z0-9-]{0,48}\.(mp3|ogg|wav)$`, and it
is offered as `b:<name>`). The owner adds more from Settings → Sounds; those live in the database, not
here. As shipped no sound is selected for anything (every `sound_default_*` is empty), and a member
hears nothing until they pick one.

All of these come from [Pixabay](https://pixabay.com/sound-effects/) under the Pixabay Content
License (free to use, no attribution required). Three were shortened with a frame-level cut (no
re-encode) so they end when the sound does:

| file | source (Pixabay) | note |
|---|---|---|
| notification-7.mp3 | SoundReality — "Notification 7" (158193) | the file carried the sound twice; the repeat was cut |
| notification-center.mp3 | SoundReality — "Notification Center" (443093) | silent tail cut |
| notification-good.mp3 | SoundReality — "Notification Good" (427346) | silent tail cut |
| message-ping.mp3 | UNIVERSFIELD — "Message Ping" (351298) | |
| email-notification.mp3 | UNIVERSFIELD — "Email Notification" (143029) | |
| incoming-message.mp3 | UNIVERSFIELD — "Incoming Message" (132126) | |
| new-notification-12.mp3 | UNIVERSFIELD — "New Notification 012" (363675) | |
| notification-beep.mp3 | UNIVERSFIELD — "Notification Beep" (229154) | |
| notification-alert.mp3 | user 47313572 — "Notification Alert" (269289) | |
| notification-effect.mp3 | Dragon Studio — "Notification Sound Effect" (372475) | |
| notify-8.mp3 | notification_message — "Notify 8" (313753) | |
| multi-pop.mp3 | floraphonic — "Multi Pop 1" (188165) | |
| ding.mp3 | user u_31vnwfmzt6 — "Ding" (126626) | |

This README is documentation only. A deployment that copies the whole tree (a `git` checkout, FTP)
serves it as a static file like any other in `assets/`; it holds nothing private.
