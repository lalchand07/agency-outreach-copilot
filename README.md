# agency-outreach-copilot

**A self-hosted B2B outreach copilot for small software companies.** It helps you find relevant businesses through official sources, read their own public website, score how well they fit, and draft short personalised messages. **A human approves every single message before anything is sent.**

The first use case is selling a travel-agency back-office product (TBM) to travel agencies in the UK, Pakistan and the Gulf, but the product profile and the target criteria are plain configuration, so it works for any B2B product.

![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4) ![License: MIT](https://img.shields.io/badge/license-MIT-green)

> **Screenshots**
>
> _Placeholder: add `docs/screenshots/dashboard.png`, `review.png` and `lead.png` here._

---

## Contents

- [What it does](#what-it-does) and [what it does not do](#what-it-does-not-do)
- [10-minute setup](#10-minute-setup): [local](#local) · [shared hosting](#shared-hosting-cpanel)
- [Getting a Google Places API key](#getting-a-google-places-api-key)
- [Configuring the product profile](#configuring-the-product-profile)
- [AI providers, budgets and php-ai-guard](#ai-providers-budgets-and-php-ai-guard)
- [A sample travel-agency campaign](#a-sample-travel-agency-campaign)
- [Daily workflow](#daily-workflow)
- [Compliance](#compliance) (please read)
- [Security and privacy](#security-and-privacy)
- [Cron jobs](#cron-jobs)
- [FAQ](#faq) · [Roadmap](#roadmap) · [Development](#development)

---

## What it does

1. **Find businesses** from sources you are allowed to use:
   - **Google Places API (New) Text Search** with your own API key, e.g. "Umrah travel agency in Birmingham".
   - **CSV import** with column mapping (exhibitor lists, association directories, your own spreadsheets).
   - **Manual entry**.
   - Duplicates are detected by website domain, phone number and Google place ID.
2. **Research** each business by reading **only its own public website**: the home page plus up to four linked pages (about, contact, services…), never more than 5 pages. It obeys `robots.txt`, identifies itself with a clear user agent, waits at least one second between requests and gives up after a timeout.
3. **Extract structured observations** with a cheap LLM: services (Umrah, Hajj, holidays, flights, visas…), signs of size, static vs live prices, online quote form, WhatsApp, languages, ATOL/IATA mentions, and 1–3 specific observations. **Every observation must cite a page that was fetched and quote it word for word; anything that cannot be verified against the page text is dropped** and shown to you as "dropped".
4. **Score** each lead 0–100 against your ideal customer, with a short explanation.
5. **Draft** an email (subject + body under 120 words), a LinkedIn connection note (under 300 characters) and a WhatsApp message (under 60 words) in English, Urdu or Arabic. Each references one verified observation and your offer. Length limits are enforced in code; red-flag phrases ("as discussed", "guarantee", "300%") are highlighted.
6. **Review queue**: research on the left, drafts on the right. Approve, edit, regenerate or reject, with keyboard shortcuts (`j`/`k`, `a`, `r`, `e`, `s`, `g`).
7. **Send** approved emails through **your own SMTP server** via a queue that respects a **daily cap (default 20)** and the **suppression list**. LinkedIn and WhatsApp drafts get **copy buttons and wa.me click-to-chat links**; you send them yourself and mark them as sent.
8. **Pipeline**: new → researched → drafted → approved → contacted → replied → demo booked → trial → customer (or not interested / do not contact), with notes, next-action dates and **one follow-up** draft after N days without a reply (still needs approval).
9. **Opt-out**: a unique unsubscribe link in every email (plus RFC 8058 one-click `List-Unsubscribe` headers) that adds the address to the suppression list. You can add entries by hand too.
10. **Dashboard**: leads per status, messages sent per day, reply rate, and AI spend this month (from php-ai-guard's usage log).
11. **Export** the pipeline to CSV.

## What it does not do

- It does **not** send anything without your explicit approval of that exact message. Editing an approved draft sends it back for approval.
- It does **not** scrape Google Search, Google Maps pages, LinkedIn, directories or any site that forbids it. Website research reads only the business's own site and respects `robots.txt`.
- It does **not** automate LinkedIn or WhatsApp in any way. No logins, no browser automation, no bulk messaging.
- It does **not** detect replies automatically yet (you set "replied" yourself; IMAP is on the [roadmap](#roadmap)).
- It does **not** make your outreach legal. See [Compliance](#compliance).

---

## 10-minute setup

Requirements: **PHP 8.1+** with `curl`, `dom`, `mbstring`, `pdo_sqlite` (or `pdo_mysql`) and `fileinfo`; Composer (on your computer is enough).

### Local

```bash
git clone https://github.com/lalchand07/agency-outreach-copilot.git
cd agency-outreach-copilot
composer install
cp .env.example .env
cp config/product.example.php config/product.php   # then edit it
php bin/console hash-password                       # paste the ADMIN_PASSWORD_HASH line into .env
php bin/console migrate
php -S 127.0.0.1:8080 -t public public/index.php
```

Open <http://127.0.0.1:8080>, sign in as `admin` with the password you chose, and follow the setup checklist under **Settings**.

> php-ai-guard is not on Packagist yet, so `composer.json` pulls it from GitHub as a VCS repository (`lalchand07/php-ai-guard`, package name `your-vendor/php-ai-guard`, `dev-main`). Nothing to do: `composer install` handles it.

The app works without any API key: you can import leads, research websites (facts are extracted from the HTML) and write drafts by hand. Add an AI key for AI research and drafting, a Places key for Google search, and SMTP details for sending.

### Shared hosting (cPanel)

1. **Upload the project** to a folder **outside** `public_html`, e.g. `/home/you/outreach/`. If your host has no SSH/Composer, run `composer install --no-dev` on your computer and upload the whole folder including `vendor/`.
2. **Point a (sub)domain at `public/`**: in cPanel → *Domains*, set the document root of e.g. `outreach.yourcompany.com` to `/home/you/outreach/public`. (If you can only use `public_html`, put the project there; the root `.htaccess` sends everything to `public/` and blocks `.env` and the database, but a separate document root is safer.)
3. **Select PHP 8.1+** in *Select PHP Version* and enable `curl`, `dom`, `mbstring`, `pdo_sqlite`, `fileinfo`.
4. **Create `.env`** from `.env.example` with the File Manager. Set `APP_URL=https://outreach.yourcompany.com`, `APP_ENV=production`, the password hash (run `php bin/console hash-password` locally if you have no terminal on the host), your SMTP details and API keys.
5. **Create `config/product.php`** from the example and fill it in.
6. **Turn on HTTPS** (AutoSSL / Let's Encrypt). Unsubscribe links must work for recipients.
7. **Add the [cron jobs](#cron-jobs)** in cPanel → *Cron Jobs*.
8. Open the site. Tables are created automatically on the first request (or run `php bin/console migrate`).

**MySQL instead of SQLite?** Create a database in *MySQL Databases* and set `DB_DRIVER=mysql`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`. php-ai-guard's tables (prefix `ai_guard_`) go into the same database.

Shared hosts often cap `max_execution_time` at 30 seconds. The UI processes at most 3 leads per click and reads websites politely (≥ 1 s between requests), so bulk work is better done by `php bin/console research --limit=10` from cron or SSH.

---

## Getting a Google Places API key

1. Go to the [Google Cloud console](https://console.cloud.google.com/), create a project and **enable billing** on it.
2. In *APIs & Services → Library*, enable **Places API (New)**.
3. In *APIs & Services → Credentials*, create an **API key**. Then **restrict it**: under *API restrictions* allow only *Places API (New)*; under *Application restrictions* choose *IP addresses* and enter your server's IP.
4. Put it in `.env` as `GOOGLE_PLACES_API_KEY=...`.

**It may cost money.** Google bills Places requests per SKU beyond a monthly free usage allowance. This app's Text Search requests ask for `places.id, displayName, formattedAddress, websiteUri, businessStatus, googleMapsUri, primaryTypeDisplayName`; because `websiteUri` is included, each request is billed at the *Text Search Enterprise* SKU. Check the current [Google Maps Platform pricing](https://developers.google.com/maps/billing-and-pricing/pricing) and set a **budget alert and quota limit** in the Cloud console. Every search is a deliberate button press in the app (never automatic), and results come 20 per page, at most 60 per query.

**What is stored from Google.** The [Places API policies](https://developers.google.com/maps/documentation/places/web-service/policies) say Places content must not be cached or stored, except the **place ID**, which may be stored indefinitely. So this app:

| Data from Places | What happens |
|---|---|
| Place ID | stored on the lead (allowed indefinitely); used for de-duplication |
| Name, address, website, status, Maps link, type | **shown once on the search page, never saved** |
| Phone numbers, ratings, reviews, photos, opening hours | **not requested at all** |

When you add a result as a lead, the app fetches **the business's own home page** once and stores the website address and the name from that page's `<title>`, i.e. data confirmed from the business's own site, not Places content. The city stored is the city *you* searched for. Search results are shown with "Powered by Google" and link to Google Maps.

Google also requires apps using the Places API to have their own Terms of Use and Privacy Policy that reference Google's. This is an internal tool with one user, but read the policies yourself and decide what applies to you.

---

## Configuring the product profile

Everything the AI says about your product comes from **`config/product.php`** (gitignored; copy it from [`config/product.example.php`](config/product.example.php)):

| Key | What to put there |
|---|---|
| `product_name`, `one_line_pitch` | What you sell, in one sentence |
| `key_benefits` | 3–5 concrete benefits. No numbers you cannot prove |
| `ideal_customer` | Who fits best: used for scoring (a campaign can override it) |
| `proof_points` | Only real, checkable facts (customers, years, integrations). Leave empty if you have none |
| `offer` | The low-risk next step, e.g. "a free 20-minute demo and a free import of your booking data" |
| `demo_links` | Label → URL (video, GIF, demo booking page) |
| `sender` | `name`, `title`, `company`, `email`, `phone`, `website`, **`address`** (a real postal address), `linkedin` |
| `languages` | Languages you can actually hold a sales conversation in |

Sending is **blocked** until `sender.name`, `sender.company`, `sender.email` and `sender.address` are set, because every email must say who sent it and where you are.

---

## AI providers, budgets and php-ai-guard

All LLM calls go through [**php-ai-guard**](https://github.com/lalchand07/php-ai-guard), built in [`src/Ai/AiFactory.php`](src/Ai/AiFactory.php):

- **Providers**: set `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` and/or `GROQ_API_KEY`. Gemini and Groq are marked `allows_personal_data => false`, so php-ai-guard redacts card numbers, IBANs and passport lines before they are sent there.
- **Tiers**: `cheap` (`AI_CHEAP_MODELS`, default `anthropic:claude-haiku-5-5`) for research and scoring, `standard` (`AI_STANDARD_MODELS`, default `anthropic:claude-sonnet-5-5,anthropic:claude-haiku-5-5`) for drafting. List several `provider:model` targets for automatic fallback.
- **Features**: `research`, `scoring` (cheap), `drafting`, `followup` (standard). Each call is tagged with `$ai->withFeature(...)`, so usage and cost are logged per feature and shown on the dashboard.
- **Budget**: `AI_MONTHLY_BUDGET_USD` (default 10) and optional `AI_DAILY_BUDGET_USD`. php-ai-guard refuses a call **before** sending it if its worst-case cost could exceed what is left; the app then falls back to rule-based scoring and HTML-only research, and tells you.
- **Structured output**: research, scoring and drafts use `$ai->json($prompt, $schema, ...)`. The library validates the answer against the schema and asks once for a correction if needed. This app then verifies observations against the fetched pages and enforces draft lengths itself.
- **Caching**: identical requests (same pages, same prompt) are served from php-ai-guard's cache; "regenerate" always bypasses it.
- **Pricing**: [`config/ai-pricing.php`](config/ai-pricing.php) is a copy of php-ai-guard's example price table. **Verify every row** against your provider's pricing page; budgets are only as accurate as these numbers.

---

## A sample travel-agency campaign

**Campaign: "UK Umrah agencies – West Midlands"**

| Field | Value |
|---|---|
| Country | United Kingdom |
| Cities | Birmingham, Coventry, Wolverhampton |
| Keywords | Umrah travel agency, Hajj and Umrah operator, ATOL travel agency |
| Ideal customer | Independent UK agencies with 2–20 staff selling Umrah/Hajj packages, ATOL-protected, managing bookings in spreadsheets |
| Languages | English, Urdu |
| Channels | Email, WhatsApp (manual) |

1. **Campaigns → New**, enter the above.
2. **Find on Google Places** → "Umrah travel agency" in "Birmingham" → tick the ones with a website → *Add selected*.
3. Or **Import CSV**, e.g. an exhibitor list from a travel trade show you attended (only if its terms let you use it for outreach).
4. **Research & draft** (3 per click), or `php bin/console research --campaign=1 --limit=10`.
5. **Review**: read the research next to each draft. A good email looks like:

   > **Subject:** Your December Umrah packages
   >
   > Hello Crescent Travel team,
   >
   > I saw your site lists 7-night Umrah packages for December from £1,150, with visa and Heathrow flights included. Agencies selling packages like these often juggle hotel, visa and flight costs across spreadsheets.
   >
   > We make TBM, a back office that keeps package costing, invoices and supplier payments in one place. We'd be happy to give you a free 20-minute demo and import your current bookings for free.
   >
   > Would that be useful?
   >
   > Sam

   (Signature, postal address and unsubscribe link are added automatically.)
6. **Approve** (`a`). Emails go to the outbox and leave at most 20 per day. Copy the WhatsApp text or open the wa.me link, send it yourself, then click *I sent this by hand*.
7. When someone replies, set the lead to **Replied** / **Demo booked**. After 5 days without a reply, **Draft follow-ups** creates one polite follow-up per lead for you to approve.

More on targeting, timing and the demo itself: [docs/PLAYBOOK.md](docs/PLAYBOOK.md).

---

## Daily workflow

| When | What |
|---|---|
| Morning | Dashboard → review queue. Approve 10–20 good drafts, reject the rest. |
| | Outbox → send the LinkedIn/WhatsApp ones you approved by hand. |
| | Reply to anyone who answered; set their status and next-action date. |
| Cron | Sends the email queue within the daily cap, drafts due follow-ups, applies retention. |

---

## Compliance

**This tool does not make your outreach compliant by itself.** It gives you safeguards; you decide whom to contact and how. Laws differ by country and by the type of recipient, and they change. **Check the laws that apply to you and to each recipient, and take legal advice if unsure.** Not legal advice; starting points only:

- **UK: PECR and UK GDPR.** Under PECR you may email *corporate subscribers* (limited companies, LLPs, public bodies) without prior consent if you identify yourself and offer a simple opt-out. **Sole traders and some partnerships count as individuals**, and unsolicited marketing email to them generally needs prior consent. Many small travel agencies are sole traders: check before you email. UK GDPR still applies to named people's business contact details: you need a lawful basis (usually legitimate interests, with a written assessment), must tell people where you got their data and how to object (Article 14), and must honour objections. See the ICO's guidance on electronic marketing and B2B marketing.
- **EU: ePrivacy and GDPR.** Each member state implements the ePrivacy Directive differently; several require prior consent even for B2B email. GDPR applies as above.
- **UAE.** The Personal Data Protection Law (Federal Decree-Law No. 45 of 2021) and TDRA rules on unsolicited commercial communications may apply. Free-zone rules (DIFC, ADGM) have their own data protection laws.
- **Saudi Arabia, Qatar and other Gulf states** have their own data protection laws (e.g. Saudi PDPL).
- **Pakistan.** The Prevention of Electronic Crimes Act 2016 includes provisions on unsolicited electronic messages ("spamming"), including offering recipients a way to opt out.
- **Platform rules.** LinkedIn's User Agreement forbids automation and bulk messaging; WhatsApp's terms and Business Messaging Policy restrict unsolicited and automated messages. This tool only prepares text for you to send by hand. Use it sparingly and personally.
- **Data sources.** Respect the terms of every list you import and of the Google Maps Platform.

**Safeguards built in:**

- Explicit approval of each specific message; edits require re-approval; length and red-flag checks.
- Every email has your name, company, **postal address**, why they are receiving it and a **unique unsubscribe link**, plus `List-Unsubscribe` / one-click headers. Sending is blocked without sender identity and address.
- **Suppression list** (email, domain, phone) checked at approval, when queueing **and again right before sending**. Unsubscribes and "do not contact" add entries automatically; data retention keeps them.
- **Daily send cap** (default 20, hard maximum 200) and **one follow-up maximum** by default (follow-ups also need approval).
- **Full send log**: what was sent, to whom, when, and the exact text, viewable per lead and in the outbox.
- **Data retention**: details of "not interested" and "do not contact" leads are deleted after N days (default 180); suppression entries stay and the log keeps only a hash of the address.

---

## Security and privacy

- Single admin login; the password hash lives in `.env` (`password_hash`), logins are throttled per IP (5 failures / 15 minutes), the session id is regenerated at login, cookies are `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS, 8-hour idle timeout.
- **CSRF token on every POST** (forms and HTMX). The only exception is the public unsubscribe link, whose unguessable token is its own credential.
- All output escaped; **prepared statements only**; strict Content-Security-Policy, `X-Frame-Options: DENY`, `nosniff`.
- **Secrets only in `.env`**: API keys and the SMTP password are never stored in the database, shown in the UI or logged.
- CSV uploads: `.csv` only, 5 MB, content checked, stored outside the web root and deleted after import. CSV export neutralises spreadsheet formulas.
- Website fetching: http/https only, 1 MB per page, 3 redirects, redirects to other sites are refused, page text is treated as data (the research prompt tells the model to ignore instructions found in pages).

Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

---

## Cron jobs

```cron
*/15 * * * *  php /home/you/outreach/bin/console send          # send approved emails (cap + suppression enforced)
0 8 * * *     php /home/you/outreach/bin/console followups     # draft due follow-ups (they still need approval)
0 3 * * 0     php /home/you/outreach/bin/console retention     # apply the retention policy
```

Other commands: `migrate`, `hash-password`, `research [--campaign=ID] [--limit=N] [--no-draft]`, `retention --dry-run`, `status`.

---

## FAQ

**Does it work without AI?** Yes. Research then extracts facts from the HTML (services, ATOL/IATA, WhatsApp links, forms, prices, emails) and builds observations from them; scoring is rule-based; you write drafts by hand.

**Which AI model should I use?** Start with the defaults: a small model for research/scoring and a stronger one for drafts. A typical lead (research + score + 3 drafts) uses a few thousand tokens. Watch the dashboard and set the monthly budget.

**Why does my draft say "No verified observation"?** Drafts must mention something real. Research the lead first; if the site has little text, add a fact by hand in the lead notes and write the draft yourself.

**Why was an observation "dropped"?** The model cited a page that was not fetched, or its quote was not on the page. That is the safety net working.

**Can I send more than 20 emails a day?** You can raise the cap in Settings (up to 200). Cold email reputation suffers quickly at volume; small, relevant batches work better and keep your domain healthy. Use a separate sending domain or mailbox with SPF, DKIM and DMARC set up.

**Can it send WhatsApp or LinkedIn messages for me?** No, deliberately. Automating them breaks those platforms' terms and is a fast way to get your account banned.

**How do I detect replies?** For now, check your inbox and set the status. IMAP reply detection is on the roadmap.

**Is Urdu/Arabic supported?** Drafts can be generated in Urdu (Urdu script) or Modern Standard Arabic; the review screen shows them right-to-left. Have a native speaker check them before you send.

**Can I use it for something other than travel agencies?** Yes: change `config/product.php` and create campaigns with your own keywords and ideal customer. The research schema's service list is travel-oriented but "other" is accepted and the observations are generic.

---

## Roadmap

- Reply detection via IMAP (mark leads "replied", pause follow-ups automatically)
- Calendar booking links (Cal.com / Calendly) in the offer and a "demo booked" webhook
- CRM webhooks (HubSpot, Pipedrive, generic JSON) on status changes
- Per-campaign sending mailboxes and warm-up friendly scheduling
- Optional prebuilt Tailwind CSS (no CDN)
- Multi-user with roles

---

## Development

```bash
composer install
composer test       # PHPUnit: no network or API keys needed
composer analyse    # PHPStan level 6
composer serve      # php -S 127.0.0.1:8080
```

Architecture, data model, prompts and schemas: [PLAN.md](PLAN.md). Contributing: [CONTRIBUTING.md](CONTRIBUTING.md). Changes: [CHANGELOG.md](CHANGELOG.md).

---

## Built by

_Your name / company here._ ([website](https://example.com) · [GitHub](https://github.com/lalchand07))

## License

MIT. See [LICENSE](LICENSE).
