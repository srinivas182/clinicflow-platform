# Rotating the encryption key (APP_KEY)

Stored secrets and sensitive records (ID numbers, provider keys, authenticator secrets, transcripts, lab results…) are encrypted with `APP_KEY`. Rotate it yearly, when staff with server access leave, or if it may have been exposed.

1. **Generate a new key:** `php artisan key:generate --show` (copy the output).
2. **Switch keys without downtime:** set `APP_KEY` to the new key and put the old one in `APP_PREVIOUS_KEYS` (comma-separated if more than one). Deploy/restart. Everything still decrypts.
3. **Preview:** `php artisan security:reencrypt --dry-run` — counts values still under an old key.
4. **Re-encrypt:** `php artisan security:reencrypt` — re-encrypts them with the new key (platform, network hub and every practice database). It must report **0 unreadable**.
5. **Retire the old key:** remove it from `APP_PREVIOUS_KEYS` and restart. Keep a sealed copy for 30 days in case a backup made before rotation must be restored.

Note: users stay signed in during rotation (sessions encrypted with the old key still decrypt until step 5).
