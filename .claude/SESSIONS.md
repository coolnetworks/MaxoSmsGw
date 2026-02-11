# Sessions

## maxo-sms-autoreply-debug
**Date:** 2026-01-20
**Branch:** master

### What was done
- Added `#!` delimiter to outgoing SMS messages (lines 160-161 and 246-247 in MaxoSmsGwServiceProvider.php)
- SMS provider strips everything after `#!` so customers get clean messages without FreeScout boilerplate
- Deployed module to server at `/var/www/html/Modules/MaxoSmsGw/`
- Version bumped to 1.0.1

### Open issue
- Auto-replies seem to be disabled for ALL incoming messages, not just SMS gateway
- Tested: Module logs don't appear for non-SMS emails, so `isFromSmsGateway()` check is working correctly
- Conclusion: The auto-reply issue is NOT caused by this module - it's elsewhere in FreeScout config

### Key files
- `Providers/MaxoSmsGwServiceProvider.php` - main module logic
- `module.json` - version 1.0.1

### Next steps
1. Check FreeScout mailbox settings: Mailbox > Settings > Auto Reply
2. Check if another module is disabling auto-replies
3. Search logs: `grep -i "auto.reply" /var/www/html/storage/logs/laravel.log`

### Server info
- FreeScout path: `/var/www/html`
- SSH access confirmed
- Module shows as "Enabled" in `php artisan module:list`

## maxo-sms-threading-cleanup
**Date:** 2026-02-11
**Branch:** master

### What was done
- **SMS threading**: All SMS from same phone number thread into one conversation via `fetch_emails.data_to_save` hook setting `prev_thread`
- **Body stripping**: Inbound SMS gateway HTML (logos, CSS, "XXXX wrote:", footers) stripped to just message text. Outbound confirmations ("Email2SMS Reply From...") also stripped.
- **`cleanSmsBoilerplate()` final pass**: Catches remaining CSS, "Please reply above this line", "This reply was sent from...", quoted headers, trailing `#!`
- **Replaced `#!` with 3 blank lines** on outgoing SMS (gateway wasn't delivering with `#!`)
- **Suppressed outbound confirmation echoes**: `fetch_emails.should_save_thread` returns false for "Email2SMS Reply From" emails — no more duplicate threads
- **Dropped all SMS attachments**: `$data['attachments'] = []` in the data_to_save hook
- **Reprocessed existing data**: `reprocess_sms.php` — merged 337 duplicate conversations, cleaned ~1,253 thread bodies, deleted 438 attachments
- **Deduplication**: `dedup_sms.php` — deleted 58 duplicate message threads (confirmation echoes) and 331 duplicate action threads ("marked as Closed" entries from merges)
- **Disabled GPT Pro on SMS threads**: JS hook detects `sms.voipportal.com.au` in conversation, hides `.gpt` and `.chatgpt-get` elements, sets `autoGenerate = false`
- **SMS gateway uses 3 blank lines** (not `#!`) to terminate outbound messages
- **SSH setup**: `~/.ssh/freescout_key` (passphraseless ed25519), `Host freescout` → cn@help.cool.net.au, NOPASSWD sudo for www-data
- **Sending SMS via CLI**: `SendReplyToCustomer::dispatch($conv, collect([$thread]), $customer)->onQueue("emails")`

### Key files
- `Providers/MaxoSmsGwServiceProvider.php` — all module logic
- `reprocess_sms.php` — one-time migration/cleanup script
- `dedup_sms.php` — one-time deduplication script

### Deployment
```bash
ssh freescout 'cd /var/www/html/Modules/MaxoSmsGw && sudo -n -u www-data git pull && cd /var/www/html && sudo -n -u www-data php artisan cache:clear'
```
