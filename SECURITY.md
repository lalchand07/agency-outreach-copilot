# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Use GitHub's
private vulnerability reporting ("Security" tab → "Report a vulnerability")
on this repository, or contact the maintainers privately.

Include what you found, how to reproduce it and the impact you expect. We aim
to acknowledge reports within 5 working days and to release a fix or a
mitigation as soon as possible.

## Supported versions

Only the latest release on the default branch receives security fixes.

## Hardening checklist for operators

- Serve the app over **HTTPS** and set `APP_ENV=production`.
- Point the web server's document root at `public/`; keep `.env`, `config/`, `var/` and `vendor/` out of the web root.
- Use a long admin password (`php bin/console hash-password` requires 12+ characters).
- Restrict your Google Places API key to the Places API (New) and your server's IP; set a billing budget.
- Use a dedicated SMTP account for outreach, with SPF, DKIM and DMARC.
- Keep PHP and dependencies updated (`composer update`).
- Back up `var/app.sqlite` (or your MySQL database); it contains contact data, so store backups securely.

## Design notes

- Secrets live only in `.env` and are never stored in the database or logs.
- CSRF tokens on every POST; prepared statements only; all template output escaped; strict CSP.
- Login attempts are throttled per IP.
- The unsubscribe endpoint is public and token-authenticated (128-bit random tokens).
- Fetched web pages are untrusted input: only http/https, size and redirect limits, and the research prompt instructs the model to ignore instructions in page text. Model output is validated against a JSON schema and observations are verified against the page text before use.
