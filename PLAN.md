# agency-outreach-copilot: implementation plan

A self-hosted B2B outreach copilot. It finds businesses through official
sources, researches their own public website, scores them, drafts short
personalised messages, and puts every message in front of a human. Nothing is
sent until that specific message is approved.

First use case: selling TBM (a travel agency back-office product) to travel
agencies in the UK, Pakistan and the Gulf. Everything product-specific lives
in `config/product.php` and in campaign settings.

## 1. Architecture

- PHP 8.1+, no framework. Front controller `public/index.php`, a tiny router,
  plain PHP templates in `templates/`, HTMX for partial updates, Tailwind via
  CDN.
- Storage: PDO. SQLite by default (`var/app.sqlite`), MySQL optional. Schema in
  `migrations/` (one file per driver), applied by `bin/console migrate` and
  automatically on first request.
- LLM: every call goes through `lalchand07/php-ai-guard` (`PhpAiGuard\AiGuard`),
  created once in `App\Ai\AiFactory` from `.env`:
  - tiers `cheap` (research, scoring) and `standard` (drafting),
  - features `research`, `scoring`, `drafting`, `followup` mapped to tiers so
    usage is logged per feature,
  - a global monthly budget (`AI_MONTHLY_BUDGET_USD`, default 10) and an
    optional daily one, stored in `var/ai-guard.sqlite` (or the MySQL database
    with prefix `ai_guard_`),
  - structured output with `json()` and a schema for research, scoring and
    drafts; one corrective retry is built into the library,
  - privacy redaction on for providers marked `allows_personal_data=false`.
  - Dashboard spend comes from `$ai->reporter()->month(date('Y-m'), 'feature')`.
- Outbound HTTP (websites, Places, robots.txt) goes through
  `App\Net\HttpClientInterface` so tests use `FakeHttpClient`. LLM HTTP goes
  through php-ai-guard's own `HttpClientInterface`; tests use its
  `MockHttpClient`.
- Email via PHPMailer over the user's SMTP, behind `App\Mail\MailerInterface`
  (tests use `ArrayMailer`).
- CLI `bin/console` for cron: `migrate`, `hash-password`, `send`, `followups`,
  `retention`, `research`.

## 2. Data model

| Table | Columns (main) | Notes |
|---|---|---|
| `campaigns` | id, name, country, cities (JSON), keywords (JSON), languages (JSON), channels (JSON), ideal_customer, notes, created_at | target criteria |
| `leads` | id, campaign_id, source (`places`/`csv`/`manual`), place_id, name, website, domain, email, phone, phone_normalized, contact_name, city, country, status, score, score_reason, notes, next_action_at, status_changed_at, researched_at, created_at, updated_at | unique-ish by domain and phone (enforced in code, indexed) |
| `research` | id, lead_id, pages (JSON: url, title, status), facts (JSON: deterministic signals), result (JSON: LLM output after verification), created_at | latest row wins |
| `drafts` | id, lead_id, channel (`email`/`linkedin`/`whatsapp`), language, subject, body, observation, status (`pending`/`approved`/`rejected`/`queued`/`sent`/`superseded`), is_followup, edited, created_at, decided_at | one set per generation |
| `messages` | id, draft_id, lead_id, channel, recipient, subject, body, status (`queued`/`sent`/`failed`/`skipped`), unsubscribe_token, error, queued_at, sent_at | the send log: what went to whom and when |
| `suppression` | id, type (`email`/`domain`/`phone`), value, reason, created_at | checked before every send and every queue |
| `settings` | key, value | daily cap, follow-up days, max follow-ups, retention days |
| `activity` | id, lead_id, type, detail, created_at | audit trail (status changes, approvals) |

Statuses: `new`, `researched`, `drafted`, `approved`, `contacted`, `replied`,
`demo_booked`, `trial`, `customer`, `not_interested`, `do_not_contact`.

## 3. Pages

| Path | Purpose |
|---|---|
| `/login`, `/logout` | single admin, password hash from `.env` |
| `/` | dashboard: leads per status, sent per day (14 days), reply rate, AI spend this month, follow-ups due |
| `/campaigns`, `/campaigns/new`, `/campaigns/{id}` | criteria editor, batch actions |
| `/campaigns/{id}/places` | Google Places Text Search; results shown transiently, pick which to add |
| `/import` | CSV upload → column mapping → import report |
| `/leads`, `/leads/new`, `/leads/{id}` | list/filter, manual add, detail (research, score, drafts, notes, status, next action) |
| `/review` | review queue with keyboard shortcuts |
| `/outbox` | queue, send log, manual channels (copy, wa.me), process queue |
| `/suppression` | list, manual add |
| `/settings` | caps and retention, compliance checklist |
| `/export.csv` | pipeline export |
| `/u/{token}` | public unsubscribe (GET confirm page, POST one-click, RFC 8058) |

## 4. Pipeline

1. **Source**: Places search, CSV import or manual add. De-duplication by
   normalised domain (lower-case host, no `www.`) and normalised phone (digits,
   country code from the campaign country). Duplicates are reported, not
   inserted.
2. **Research**: `WebsiteFetcher` fetches the home page and up to 4 linked
   pages on the same host (about, contact, services, …), max 5 pages, after
   checking robots.txt (RFC 9309: longest match, `*` and `$`, 4xx = allow all,
   5xx/unreachable = disallow all, `Crawl-delay` honoured), with user agent
   `AgencyOutreachCopilot/1.0 (+contact URL)`, 1 request per second per host
   minimum, 10 s timeout, 1 MB cap. Deterministic facts are extracted (WhatsApp
   links, forms, ATOL/IATA/ABTA mentions, emails, phones). The cheap tier is
   asked for structured observations.
3. **Verification**: every observation must cite a fetched URL and an exact
   evidence quote that appears in that page's text. Anything else is dropped.
4. **Score**: cheap tier returns `{score, explanation}`. Without AI configured a
   transparent rule-based score is used.
5. **Draft**: standard tier returns email subject/body, LinkedIn note and
   WhatsApp message in the chosen language, each using one verified
   observation and the offer. Limits enforced in code; one shortening retry,
   then the draft is flagged.
6. **Review**: approve, edit, regenerate or reject each draft.
7. **Send**: approved email → `messages` queue → `bin/console send` (cron) or
   "Process queue" button. LinkedIn/WhatsApp → copy button / wa.me link, user
   marks as sent.
8. **Follow-up**: N days (default 5) after contact with no reply, a follow-up
   draft is generated (still needs approval). Max 1 by default.

## 5. LLM prompts and schemas

All prompts state: use only the supplied page text, never invent facts, no
exaggerated claims, no fake familiarity ("I loved your…", "as we discussed").

**Research** (`research`, cheap, `max_tokens` 900)

```json
{"type":"object","required":["services","size","pricing","has_quote_form","whatsapp","languages","accreditations","observations","summary"],
 "properties":{
  "services":{"type":"array","items":{"type":"string","enum":["umrah","hajj","holidays","flights","visas","tours","hotels","corporate","cruises","other"]}},
  "size":{"type":"object","required":["estimate","signals"],"properties":{"estimate":{"type":"string","enum":["solo","small","medium","large","unknown"]},"signals":{"type":"string","maxLength":300}}},
  "pricing":{"type":"string","enum":["static","live","on_request","none","unknown"]},
  "has_quote_form":{"type":"boolean"},"whatsapp":{"type":"boolean"},
  "languages":{"type":"array","items":{"type":"string"}},
  "accreditations":{"type":"array","items":{"type":"string"}},
  "observations":{"type":"array","maxItems":3,"items":{"type":"object","required":["text","source_url","evidence"],
     "properties":{"text":{"type":"string","maxLength":240},"source_url":{"type":"string"},"evidence":{"type":"string","maxLength":200}}}},
  "summary":{"type":"string","maxLength":400}}}
```

**Scoring** (`scoring`, cheap, `max_tokens` 300):
`{"score": integer 0..100, "explanation": string ≤ 300}` built by
`ScoringPromptBuilder` from the ideal customer, campaign criteria and the
verified research.

**Drafting** (`drafting` / `followup`, standard, `max_tokens` 900):
`{"email_subject" ≤ 80, "email_body", "linkedin_note" ≤ 300 chars,
"whatsapp_message", "observation_index" integer}`. Body limits (email ≤ 120
words, WhatsApp ≤ 60 words) are checked by `DraftLimits` since JSON schema
cannot count words.

## 6. Compliance safeguards

- Nothing is sent without approval of that exact draft; edits keep approval
  tied to the edited text (editing resets approval).
- Every email gets a footer: sender name, company, physical address, why they
  are getting it, and a unique unsubscribe link; plus `List-Unsubscribe` and
  `List-Unsubscribe-Post` headers. Sending refuses to run if sender identity or
  address is missing.
- Suppression list (email, domain, phone) checked when queueing and again at
  send time. Unsubscribes and `do_not_contact` add entries; retention keeps
  them.
- Daily send cap (default 20) enforced at send time across all sends that day.
- One follow-up maximum by default; follow-ups are drafts needing approval.
- Full send log (`messages`), exportable.
- Google Places: only `place_id` is stored (allowed indefinitely). Search
  results are displayed transiently with Google attribution. Lead name and
  domain come from the business's own website (title tag), fetched when the
  lead is added; phone/address from Places are never stored.
- Website research: own site only, robots.txt, UA, rate limit, timeouts,
  ≤ 5 pages.
- LinkedIn/WhatsApp: drafts only, copy and wa.me links, no automation.
- Secrets only in `.env`. Not stored in DB, not logged.
- Retention: leads in `not_interested` / `do_not_contact` older than N days
  are deleted (drafts, research, notes); the send log keeps only a hash of the
  recipient; suppression entries stay.

## 7. Security

Password hash (`password_hash`) in `.env`, session cookie `HttpOnly`,
`SameSite=Lax`, `Secure` on HTTPS, session id regenerated at login, login
throttling, CSRF token on every POST (except the token-authenticated
unsubscribe), all output escaped with `e()`, prepared statements only, CSV
uploads checked by extension, MIME sniff and size, CSV export neutralises
formula injection, security headers (CSP, frame-ancestors none, nosniff).

## 8. Tests

PHPUnit, no network or keys: de-duplication, scoring prompt building, draft
length limits, suppression and daily caps, unsubscribe flow, robots.txt
handling, CSV mapping, plus research verification, Places client, retention,
CSRF. PHPStan level 6. GitHub Actions on PHP 8.1–8.4.
