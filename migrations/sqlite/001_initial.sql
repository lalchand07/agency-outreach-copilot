-- agency-outreach-copilot schema (SQLite). Times are UTC "YYYY-MM-DD HH:MM:SS".

CREATE TABLE campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    country TEXT NOT NULL DEFAULT '',
    cities TEXT NOT NULL DEFAULT '[]',
    keywords TEXT NOT NULL DEFAULT '[]',
    languages TEXT NOT NULL DEFAULT '["en"]',
    channels TEXT NOT NULL DEFAULT '["email"]',
    ideal_customer TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER REFERENCES campaigns(id) ON DELETE SET NULL,
    source TEXT NOT NULL,
    place_id TEXT,
    name TEXT NOT NULL DEFAULT '',
    website TEXT NOT NULL DEFAULT '',
    domain TEXT,
    email TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    phone_normalized TEXT,
    contact_name TEXT NOT NULL DEFAULT '',
    linkedin_url TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL DEFAULT '',
    language TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'new',
    score INTEGER,
    score_reason TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    next_action_at TEXT,
    status_changed_at TEXT NOT NULL,
    researched_at TEXT,
    contacted_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX leads_domain ON leads (domain);
CREATE INDEX leads_phone ON leads (phone_normalized);
CREATE INDEX leads_status ON leads (status);
CREATE INDEX leads_campaign ON leads (campaign_id);
CREATE UNIQUE INDEX leads_place ON leads (place_id);

CREATE TABLE research (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
    pages TEXT NOT NULL DEFAULT '[]',
    facts TEXT NOT NULL DEFAULT '{}',
    result TEXT NOT NULL DEFAULT '{}',
    dropped TEXT NOT NULL DEFAULT '[]',
    error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX research_lead ON research (lead_id);

CREATE TABLE drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
    channel TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT 'en',
    subject TEXT NOT NULL DEFAULT '',
    body TEXT NOT NULL DEFAULT '',
    observation TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    is_followup INTEGER NOT NULL DEFAULT 0,
    edited INTEGER NOT NULL DEFAULT 0,
    warnings TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    decided_at TEXT
);
CREATE INDEX drafts_lead ON drafts (lead_id);
CREATE INDEX drafts_status ON drafts (status);

CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    draft_id INTEGER,
    lead_id INTEGER REFERENCES leads(id) ON DELETE SET NULL,
    channel TEXT NOT NULL,
    recipient TEXT NOT NULL,
    subject TEXT NOT NULL DEFAULT '',
    body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'queued',
    is_followup INTEGER NOT NULL DEFAULT 0,
    unsubscribe_token TEXT,
    error TEXT NOT NULL DEFAULT '',
    queued_at TEXT NOT NULL,
    sent_at TEXT
);
CREATE INDEX messages_status ON messages (status);
CREATE INDEX messages_sent ON messages (sent_at);
CREATE UNIQUE INDEX messages_token ON messages (unsubscribe_token);

CREATE TABLE suppression (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    value TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX suppression_value ON suppression (type, value);

CREATE TABLE settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER REFERENCES leads(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    detail TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX activity_lead ON activity (lead_id);

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    created_at TEXT NOT NULL
);
