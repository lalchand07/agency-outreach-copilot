# Contributing

Thanks for helping. This project has a few non-negotiable principles; please
read them before opening a pull request.

## Principles

1. **Human in the loop.** Nothing may ever be sent without explicit approval of that specific message. No "auto-approve", no "send all".
2. **Official data sources only.** Google Places API with the user's own key, CSV import, manual entry. No scraping of Google Search, Google Maps pages, LinkedIn or any site that forbids it.
3. **Polite research.** Only the business's own website, `robots.txt` respected, identifying user agent, rate limited, at most 5 pages.
4. **No platform automation.** LinkedIn and WhatsApp stay copy-and-send-by-hand.
5. **Compliance features stay on.** Sender identity, postal address and opt-out in every email; suppression checks; daily cap; one follow-up by default; full send log.
6. **No invented facts.** Anything the AI says about a business must be traceable to a fetched page.

Pull requests that weaken these will not be merged.

## Development

```bash
composer install
composer test      # PHPUnit, no network or keys
composer analyse   # PHPStan level 6
composer serve     # http://127.0.0.1:8080
```

- PHP 8.1+, `declare(strict_types=1)`, PSR-12 style, small focused classes.
- Every LLM call goes through php-ai-guard (`App\Ai\AiFactory`) with a feature tag.
- Database access through `App\Db\Database` with prepared statements only; new tables need a migration for **both** `migrations/sqlite` and `migrations/mysql`.
- Templates escape every value with `e()`. Every POST form includes `<?= $csrf->field() ?>`.
- Tests must not touch the network: use `tests/Support/FakeHttpClient` for websites/Places and `tests/Support/FakeAi` (php-ai-guard's `MockHttpClient`) for the LLM.
- Add a line to `CHANGELOG.md` under *Unreleased*.

## Reporting bugs

Open an issue with steps to reproduce, PHP version, database driver and what you expected. Never paste API keys, SMTP passwords or real contact data. Security issues: see [SECURITY.md](SECURITY.md).
