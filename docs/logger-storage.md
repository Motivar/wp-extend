# Logger file storage

The EWP Logger writes one JSON object per line to daily files in `uploads/ewp-logs/` (filter `ewp_logger_file_directory`). Since 1.9.0 those files are private on every web server, and each file has a maximum number of entries.

## File names and protection

| File | Purpose |
|---|---|
| `ewp-log-{date}-{key}.php` | First file of a day |
| `ewp-log-{date}-{key}-{n}.php` | Part `n` of a day, once the earlier parts are full |
| `ewp-log-index-{key}.php` | Current part and entry count of the last 7 days; its lock serialises writers |

- `{key}` is a random 32-character key per site, stored in the option `ewp_private_file_key` (`EWP\Private_Files::key()`). File names cannot be guessed.
- Every file starts with `<?php exit; ?>`. A direct request runs PHP and gets an empty response, or a 403 where PHP is blocked in uploads. Nothing depends on `.htaccess`, which nginx ignores.
- `.htaccess`, `index.php` and `index.html` are still written for Apache and against directory listings.

REST-health captures use the same scheme: `uploads/ewp-rest-health/payloads-{key}.php` and `history/user-{id}-{key}.php`.

Pre-1.9.0 files (`ewp-log-{date}.log`, `payloads.json`, `user-{id}.json`) are moved to the new names the first time the logger or the REST-health page touches the directory. Nothing has to be run by hand.

On nginx you can also deny the directory outright:

```nginx
location ~* /wp-content/uploads/(ewp-logs|ewp-rest-health)/ { deny all; }
```

## Entries per file

Setting **Max entries per log file** on the logger settings page (`max_entries_per_file`, default 5000, never below 100), filter `ewp_logger_max_entries_per_file`. When a day's current file reaches the limit, new entries go to the next part of the same day. Queries, counts, deletes and retention cleanup read every part, so the viewer, REST, CLI and abilities are unaffected.

At about 600 bytes per entry, the default keeps each file around 3 MB.

## Redaction

`ewp_log()` replaces values under secret-looking keys with `[redacted]` before storing the payload (`EWP\Logger\EWP_Logger::redact()`). This applies to `password`, `user_pass`, `api_key`, `Authorization`, `card_number` and similar keys, at any depth. Keys such as `passengers` and `meta_key` are left alone. Extend the list with the `ewp_logger_redact_keys` filter. REST-health monitoring redacts captured query and body parameters the same way.
