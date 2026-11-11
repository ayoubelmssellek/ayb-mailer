-- AYB Unsubscribe + Global Suppression (Hostinger MariaDB/MySQL)
-- Charset: utf8mb4

CREATE TABLE IF NOT EXISTS suppression_global (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_norm VARCHAR(320) NOT NULL,
  reason VARCHAR(40) NOT NULL,
  source VARCHAR(80) NOT NULL DEFAULT '',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  removed_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_supp_email (email_norm),
  KEY idx_supp_reason (reason),
  KEY idx_supp_updated (updated_at),
  KEY idx_supp_removed (removed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unsubscribe_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  email_norm VARCHAR(320) NOT NULL,
  purpose VARCHAR(40) NOT NULL DEFAULT 'marketing',
  provider VARCHAR(40) NOT NULL DEFAULT '',
  issued_by VARCHAR(120) NOT NULL DEFAULT '',
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_email (email_norm),
  KEY idx_token_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppression_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_norm VARCHAR(320) NOT NULL,
  action VARCHAR(40) NOT NULL,
  reason VARCHAR(40) NOT NULL DEFAULT '',
  source VARCHAR(80) NOT NULL DEFAULT '',
  detail VARCHAR(500) NOT NULL DEFAULT '',
  actor VARCHAR(120) NOT NULL DEFAULT 'system',
  request_id VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_email (email_norm),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_consumers (
  consumer_id VARCHAR(80) NOT NULL,
  last_suppression_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (consumer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
