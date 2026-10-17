# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Product profile (`config/product.php`) and campaigns with country, cities, business-type keywords, languages (English, Urdu, Arabic) and channels.
- Lead sourcing: Google Places API (New) Text Search (only place IDs stored), CSV import with column mapping, manual entry; de-duplication by domain, phone and place ID.
- Polite website research: own site only, robots.txt (RFC 9309), identifying user agent, rate limit, timeouts, max 5 pages.
- Structured research, scoring and drafting through php-ai-guard (cheap/standard tiers, per-feature usage logging, monthly budget), with evidence-checked observations.
- Drafts for email (≤ 120 words), LinkedIn (≤ 300 characters) and WhatsApp (≤ 60 words) with red-flag warnings.
- Review queue with keyboard shortcuts; approval of each specific message.
- Email sending via SMTP (PHPMailer) through a queue with a daily cap and suppression checks; compliance footer and one-click unsubscribe.
- Manual LinkedIn/WhatsApp sending with copy buttons and wa.me links.
- Pipeline statuses, notes, next-action dates, one follow-up draft after N days.
- Suppression list, data retention, dashboard (statuses, sends per day, reply rate, AI spend), CSV export.
- Single admin login with throttling, CSRF protection, secure sessions, CSP.
- `bin/console` for cron: send, followups, retention, research, migrate, hash-password, status.
- PHPUnit suite, PHPStan level 6, GitHub Actions CI.
