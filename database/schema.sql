-- ============================================================================
--  DocuPilot AI — MySQL 8 schema
--  Import this file through phpMyAdmin (Import tab) or the CLI:
--      mysql -u USER -p DATABASE < database/schema.sql
--  It is safe to re-import on an empty database only; it creates tables and
--  seeds plans, templates, settings and the default administrator account.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password` VARCHAR(255) DEFAULT NULL COMMENT 'NULL for Google-only accounts',
  `google_id` VARCHAR(64) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT NULL,
  `role` ENUM('user','admin') NOT NULL DEFAULT 'user',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `email_verified_at` DATETIME DEFAULT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_google_id_unique` (`google_id`),
  KEY `users_role_status_index` (`role`, `status`),
  KEY `users_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- plans
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(40) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `billing_interval` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `document_limit` INT NOT NULL DEFAULT 5,
  `ai_limit` INT NOT NULL DEFAULT 5,
  `all_templates` TINYINT(1) NOT NULL DEFAULT 0,
  `pdf_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `email_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `features` TEXT DEFAULT NULL COMMENT 'JSON array of marketing bullet points',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_slug_unique` (`slug`),
  KEY `plans_active_index` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- business_profiles
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `business_name` VARCHAR(160) NOT NULL,
  `logo_path` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `website` VARCHAR(190) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(80) DEFAULT NULL,
  `state` VARCHAR(80) DEFAULT NULL,
  `country` VARCHAR(80) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `tax_number` VARCHAR(40) DEFAULT NULL,
  `bank_name` VARCHAR(120) DEFAULT NULL,
  `account_name` VARCHAR(120) DEFAULT NULL,
  `account_number` VARCHAR(40) DEFAULT NULL,
  `ifsc` VARCHAR(20) DEFAULT NULL,
  `default_terms` TEXT DEFAULT NULL,
  `default_notes` TEXT DEFAULT NULL,
  `default_currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `default_template` VARCHAR(30) NOT NULL DEFAULT 'modern',
  `signature_name` VARCHAR(120) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_profiles_user_unique` (`user_id`),
  CONSTRAINT `business_profiles_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- clients
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `company` VARCHAR(160) DEFAULT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `clients_user_name_index` (`user_id`, `name`),
  KEY `clients_user_created_index` (`user_id`, `created_at`),
  CONSTRAINT `clients_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- document_templates
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(30) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `accent_color` VARCHAR(9) NOT NULL DEFAULT '#4f46e5',
  `is_basic` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Available on the Free plan',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_templates_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- documents
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `client_id` BIGINT UNSIGNED DEFAULT NULL,
  `document_type` ENUM('quotation','invoice','proposal','estimate','purchase_order') NOT NULL DEFAULT 'quotation',
  `document_number` VARCHAR(40) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `summary` TEXT DEFAULT NULL,
  `status` ENUM('draft','final','sent') NOT NULL DEFAULT 'draft',
  `template` VARCHAR(30) NOT NULL DEFAULT 'modern',
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `issue_date` DATE NOT NULL,
  `valid_until` DATE DEFAULT NULL,
  `client_name` VARCHAR(160) DEFAULT NULL,
  `client_company` VARCHAR(160) DEFAULT NULL,
  `client_email` VARCHAR(190) DEFAULT NULL,
  `client_phone` VARCHAR(40) DEFAULT NULL,
  `client_address` TEXT DEFAULT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
  `discount_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `discount_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  `ai_generated` TINYINT(1) NOT NULL DEFAULT 0,
  `ai_prompt` TEXT DEFAULT NULL,
  `pdf_path` VARCHAR(255) DEFAULT NULL,
  `pdf_generated_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `documents_user_number_unique` (`user_id`, `document_number`),
  KEY `documents_user_status_index` (`user_id`, `status`),
  KEY `documents_user_type_index` (`user_id`, `document_type`),
  KEY `documents_user_created_index` (`user_id`, `created_at`),
  KEY `documents_client_index` (`client_id`),
  CONSTRAINT `documents_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `documents_client_fk` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- document_items
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `description` VARCHAR(1000) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `unit` VARCHAR(30) NOT NULL DEFAULT 'unit',
  `rate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `line_subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_tax` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `document_items_document_index` (`document_id`, `position`),
  CONSTRAINT `document_items_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ai_generations  (one row per OpenRouter request)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_generations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED DEFAULT NULL,
  `type` VARCHAR(40) NOT NULL DEFAULT 'document',
  `model` VARCHAR(120) DEFAULT NULL,
  `prompt` TEXT DEFAULT NULL,
  `response` LONGTEXT DEFAULT NULL,
  `prompt_tokens` INT NOT NULL DEFAULT 0,
  `completion_tokens` INT NOT NULL DEFAULT 0,
  `total_tokens` INT NOT NULL DEFAULT 0,
  `duration_ms` INT NOT NULL DEFAULT 0,
  `status` ENUM('success','failed') NOT NULL DEFAULT 'success',
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ai_generations_user_created_index` (`user_id`, `created_at`),
  KEY `ai_generations_document_index` (`document_id`),
  CONSTRAINT `ai_generations_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_generations_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ai_usage  (monthly counters used for plan limits)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_usage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `period` CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `ai_generations` INT NOT NULL DEFAULT 0,
  `documents_created` INT NOT NULL DEFAULT 0,
  `emails_sent` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_usage_user_period_unique` (`user_id`, `period`),
  CONSTRAINT `ai_usage_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- subscriptions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'pending',
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `subscriptions_user_status_index` (`user_id`, `status`),
  KEY `subscriptions_plan_index` (`plan_id`),
  CONSTRAINT `subscriptions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscriptions_plan_fk` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payments
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_id` BIGINT UNSIGNED DEFAULT NULL,
  `subscription_id` BIGINT UNSIGNED DEFAULT NULL,
  `gateway` VARCHAR(20) NOT NULL DEFAULT 'payu',
  `txnid` VARCHAR(64) NOT NULL,
  `gateway_payment_id` VARCHAR(80) DEFAULT NULL COMMENT 'PayU mihpayid',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `status` ENUM('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_mode` VARCHAR(30) DEFAULT NULL,
  `bank_ref_num` VARCHAR(64) DEFAULT NULL,
  `error_message` VARCHAR(255) DEFAULT NULL,
  `raw_response` LONGTEXT DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_txnid_unique` (`txnid`),
  KEY `payments_user_status_index` (`user_id`, `status`),
  KEY `payments_created_index` (`created_at`),
  CONSTRAINT `payments_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_plan_fk` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_subscription_fk` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- share_links
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `share_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `views` INT NOT NULL DEFAULT 0,
  `last_viewed_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `share_links_token_unique` (`token`),
  UNIQUE KEY `share_links_document_unique` (`document_id`),
  KEY `share_links_user_index` (`user_id`),
  CONSTRAINT `share_links_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `share_links_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- email_logs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `document_id` BIGINT UNSIGNED DEFAULT NULL,
  `type` VARCHAR(40) NOT NULL DEFAULT 'general',
  `to_email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` MEDIUMTEXT DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` TEXT DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_logs_user_index` (`user_id`, `created_at`),
  KEY `email_logs_document_index` (`document_id`),
  KEY `email_logs_status_index` (`status`),
  CONSTRAINT `email_logs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_logs_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- settings  (admin managed, overrides config/config.php)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT DEFAULT NULL,
  `group` VARCHAR(40) NOT NULL DEFAULT 'general',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`),
  KEY `settings_group_index` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- password_resets
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) NOT NULL,
  `token` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of the emailed token',
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `password_resets_token_unique` (`token`),
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- email_verifications
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of the emailed token',
  `expires_at` DATETIME NOT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_verifications_token_unique` (`token`),
  KEY `email_verifications_user_index` (`user_id`),
  CONSTRAINT `email_verifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- activity_logs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `entity_type` VARCHAR(40) DEFAULT NULL,
  `entity_id` BIGINT UNSIGNED DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `activity_logs_user_index` (`user_id`, `created_at`),
  KEY `activity_logs_action_index` (`action`),
  CONSTRAINT `activity_logs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
--  SEED DATA
-- ===========================================================================

-- Plans -------------------------------------------------------------------
INSERT INTO `plans`
  (`slug`, `name`, `description`, `price`, `currency`, `billing_interval`, `document_limit`, `ai_limit`, `all_templates`, `pdf_enabled`, `email_enabled`, `features`, `is_active`, `sort_order`)
VALUES
  ('free', 'Free', 'Try DocuPilot AI at no cost', 0.00, 'INR', 'monthly', 5, 5, 0, 1, 0,
   '["5 documents / month","5 AI generations / month","Basic template","PDF preview & download"]', 1, 1),
  ('pro', 'Pro', 'For freelancers and consultants', 299.00, 'INR', 'monthly', 100, 100, 1, 1, 1,
   '["100 documents / month","100 AI generations / month","All templates","PDF export","Email delivery","Client sharing"]', 1, 2),
  ('business', 'Business', 'For agencies and growing teams', 799.00, 'INR', 'monthly', 500, 500, 1, 1, 1,
   '["500 documents / month","500 AI generations / month","All templates","PDF export","Email delivery","Client sharing","Priority support"]', 1, 3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Document templates ------------------------------------------------------
INSERT INTO `document_templates`
  (`slug`, `name`, `description`, `accent_color`, `is_basic`, `is_active`, `is_default`, `sort_order`)
VALUES
  ('modern', 'Modern', 'Colour accent header with a clean, contemporary layout.', '#4f46e5', 1, 1, 1, 1),
  ('corporate', 'Corporate', 'Formal, structured layout with bordered tables.', '#0f172a', 0, 1, 0, 2),
  ('minimal', 'Minimal', 'Typography-led, generous white space, no heavy colour.', '#111827', 0, 1, 0, 3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Settings ---------------------------------------------------------------
INSERT INTO `settings` (`key`, `value`, `group`) VALUES
  ('site_name', 'DocuPilot AI', 'system'),
  ('site_logo', '', 'system'),
  ('contact_email', '', 'system'),
  ('registration_enabled', '1', 'system'),
  ('maintenance_mode', '0', 'system'),
  ('require_email_verification', '0', 'system'),
  ('default_currency', 'INR', 'system'),
  ('default_template', 'modern', 'system'),
  ('ai_enabled', '1', 'ai'),
  ('openrouter_api_key', '', 'ai'),
  ('openrouter_model', 'openai/gpt-4o-mini', 'ai'),
  ('openrouter_base_url', 'https://openrouter.ai/api/v1', 'ai'),
  ('ai_temperature', '0.4', 'ai'),
  ('ai_max_tokens', '2000', 'ai'),
  ('smtp_host', '', 'email'),
  ('smtp_port', '587', 'email'),
  ('smtp_username', '', 'email'),
  ('smtp_password', '', 'email'),
  ('smtp_encryption', 'tls', 'email'),
  ('smtp_from_email', '', 'email'),
  ('smtp_from_name', 'DocuPilot AI', 'email'),
  ('payu_mode', 'test', 'payu'),
  ('payu_merchant_key', '', 'payu'),
  ('payu_merchant_salt', '', 'payu'),
  ('payu_base_url', 'https://test.payu.in/_payment', 'payu')
ON DUPLICATE KEY UPDATE `group` = VALUES(`group`);

-- Default administrator ---------------------------------------------------
-- Email: admin@docupilot.ai   Password: Admin@12345
-- CHANGE THIS PASSWORD IMMEDIATELY AFTER YOUR FIRST LOGIN.
INSERT INTO `users` (`name`, `email`, `password`, `role`, `status`, `email_verified_at`)
VALUES ('DocuPilot Admin', 'admin@docupilot.ai',
        '$2y$12$64pwesnHGRfO6tXBer08wedU1ttW/RLeY9LDqh4Cuw3pbe0/HjX7.',
        'admin', 'active', NOW())
ON DUPLICATE KEY UPDATE `role` = 'admin';
