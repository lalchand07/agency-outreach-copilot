-- agency-outreach-copilot schema (MySQL 5.7+/MariaDB 10.3+). Times are UTC "YYYY-MM-DD HH:MM:SS".

CREATE TABLE campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    country VARCHAR(80) NOT NULL DEFAULT '',
    cities TEXT NULL,
    keywords TEXT NULL,
    languages VARCHAR(190) NOT NULL DEFAULT '["en"]',
    channels VARCHAR(190) NOT NULL DEFAULT '["email"]',
    ideal_customer TEXT NULL,
    notes TEXT NULL,
    created_at CHAR(19) NOT NULL,
    updated_at CHAR(19) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NULL,
    source VARCHAR(20) NOT NULL,
    place_id VARCHAR(255) NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    website VARCHAR(500) NOT NULL DEFAULT '',
    domain VARCHAR(190) NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(60) NOT NULL DEFAULT '',
    phone_normalized VARCHAR(30) NULL,
    contact_name VARCHAR(190) NOT NULL DEFAULT '',
    linkedin_url VARCHAR(500) NOT NULL DEFAULT '',
    city VARCHAR(120) NOT NULL DEFAULT '',
    country VARCHAR(80) NOT NULL DEFAULT '',
    language VARCHAR(10) NOT NULL DEFAULT '',
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    score INT NULL,
    score_reason TEXT NULL,
    notes TEXT NULL,
    next_action_at CHAR(19) NULL,
    status_changed_at CHAR(19) NOT NULL,
    researched_at CHAR(19) NULL,
    contacted_at CHAR(19) NULL,
    created_at CHAR(19) NOT NULL,
    updated_at CHAR(19) NOT NULL,
    KEY leads_domain (domain),
    KEY leads_phone (phone_normalized),
    KEY leads_status (status),
    KEY leads_campaign (campaign_id),
    UNIQUE KEY leads_place (place_id),
    CONSTRAINT leads_campaign_fk FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE research (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    pages MEDIUMTEXT NULL,
    facts MEDIUMTEXT NULL,
    result MEDIUMTEXT NULL,
    dropped TEXT NULL,
    error TEXT NULL,
    created_at CHAR(19) NOT NULL,
    KEY research_lead (lead_id),
    CONSTRAINT research_lead_fk FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drafts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NOT NULL,
    channel VARCHAR(20) NOT NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    body TEXT NULL,
    observation TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    is_followup TINYINT NOT NULL DEFAULT 0,
    edited TINYINT NOT NULL DEFAULT 0,
    warnings TEXT NULL,
    created_at CHAR(19) NOT NULL,
    decided_at CHAR(19) NULL,
    KEY drafts_lead (lead_id),
    KEY drafts_status (status),
    CONSTRAINT drafts_lead_fk FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_id INT UNSIGNED NULL,
    lead_id INT UNSIGNED NULL,
    channel VARCHAR(20) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    body TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    is_followup TINYINT NOT NULL DEFAULT 0,
    unsubscribe_token VARCHAR(64) NULL,
    error TEXT NULL,
    queued_at CHAR(19) NOT NULL,
    sent_at CHAR(19) NULL,
    KEY messages_status (status),
    KEY messages_sent (sent_at),
    UNIQUE KEY messages_token (unsubscribe_token),
    CONSTRAINT messages_lead_fk FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppression (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(10) NOT NULL,
    value VARCHAR(190) NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_at CHAR(19) NOT NULL,
    UNIQUE KEY suppression_value (type, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    name VARCHAR(64) PRIMARY KEY,
    value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id INT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    detail TEXT NULL,
    created_at CHAR(19) NOT NULL,
    KEY activity_lead (lead_id),
    CONSTRAINT activity_lead_fk FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(64) NOT NULL,
    created_at CHAR(19) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
