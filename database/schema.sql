-- =============================================================================
--  LRMS — Loan Recovery Management System
--  Normalised relational schema (MySQL 5.7+ / MariaDB 10.4+, InnoDB, utf8mb4)
--
--  Load with:  php database/migrate.php --fresh
--        or:   mysql -u user -p lrms < database/schema.sql
--
--  Design notes
--  ------------
--  * Two field-work flows are modelled separately and never mixed:
--      visits      = TYPE A, BC Supervisor customer recovery visit (Android)
--      inspections = TYPE B, Admin/Supervisor inspection of a BC Supervisor (web)
--  * `account_assignments.is_active` is NULL for historical rows and 1 for the
--    current one. Combined with UNIQUE(loan_account_id, is_active) the database
--    itself guarantees an account can never have two live owners.
--  * Every row that the Android app can create carries a client generated
--    `uuid` with a UNIQUE index. Re-sending a queued offline record is therefore
--    idempotent: the insert simply collides and is reported as a duplicate.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Roles and users
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(40)  NOT NULL,
  `name`        VARCHAR(80)  NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(40)  NOT NULL,
  `name`        VARCHAR(160) NOT NULL,
  -- Regional Office and Zone as printed on the field visit verification report.
  `region`      VARCHAR(120) NULL,
  `zone`        VARCHAR(120) NULL,
  `district`    VARCHAR(120) NULL,
  `state`       VARCHAR(120) NULL,
  `address`     VARCHAR(255) NULL,
  `pincode`     VARCHAR(12)  NULL,
  `phone`       VARCHAR(20)  NULL,
  `email`       VARCHAR(160) NULL,
  -- Branch centroid, used for the optional GPS drift check.
  `latitude`    DECIMAL(10,7) NULL,
  `longitude`   DECIMAL(10,7) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`),
  KEY `ix_branches_status` (`status`),
  KEY `ix_branches_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`              BIGINT UNSIGNED NOT NULL,
  -- NULL for Admin/Supervisor (organisation wide); mandatory in practice for
  -- Branch Managers and BC Supervisors, which is what enforces branch isolation.
  `branch_id`            BIGINT UNSIGNED NULL,
  `name`                 VARCHAR(160) NOT NULL,
  `email`                VARCHAR(190) NULL,
  `username`             VARCHAR(80)  NULL,
  `employee_code`        VARCHAR(60)  NULL,
  `mobile`               VARCHAR(20)  NULL,
  `password`             VARCHAR(255) NOT NULL,
  `status`               ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `failed_attempts`      INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME NULL,
  `last_login_at`        DATETIME NULL,
  `last_login_ip`        VARCHAR(45) NULL,
  `password_changed_at`  DATETIME NULL,
  `created_by`           BIGINT UNSIGNED NULL,
  `created_at`           DATETIME NULL,
  `updated_at`           DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_employee_code` (`employee_code`),
  KEY `ix_users_role` (`role_id`),
  KEY `ix_users_branch` (`branch_id`),
  KEY `ix_users_status` (`status`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `branches`
  ADD CONSTRAINT `fk_branches_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

DROP TABLE IF EXISTS `branch_managers`;
CREATE TABLE `branch_managers` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `branch_id`      BIGINT UNSIGNED NOT NULL,
  `designation`    VARCHAR(120) NULL,
  `contact_number` VARCHAR(20)  NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `assigned_at`    DATETIME NULL,
  `assigned_by`    BIGINT UNSIGNED NULL,
  `created_at`     DATETIME NULL,
  `updated_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_managers_user` (`user_id`),
  KEY `ix_branch_managers_branch` (`branch_id`),
  CONSTRAINT `fk_bm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bm_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `bc_supervisors`;
CREATE TABLE `bc_supervisors` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `branch_id`  BIGINT UNSIGNED NOT NULL,
  -- BC / BCBF Code as printed in the bank's Excel sheets and on the report
  -- header; used for auto allocation. Wide enough for the 12-digit codes in use.
  `bc_code`    VARCHAR(60) NOT NULL,
  -- The BC Agent is the BC Supervisor: one person, one role. These are the
  -- identity fields the field visit verification report prints in section 1
  -- and countersigns in section 12.
  `sp_cbc_name` VARCHAR(190) NULL,
  `ssa`         VARCHAR(160) NULL,
  `iibf_number` VARCHAR(60)  NULL,
  `dra_id`      VARCHAR(60)  NULL,
  `designation` VARCHAR(120) NULL,
  -- Only the last four digits of Aadhaar are stored: the report prints
  -- XXXX-XXXX-nnnn and the full number is not needed for any function here.
  `aadhaar_last4` CHAR(4)    NULL,
  `pan_number`  VARCHAR(12)  NULL,
  `mobile`     VARCHAR(20)  NULL,
  `address`    VARCHAR(255) NULL,
  `village`    VARCHAR(120) NULL,
  `block`      VARCHAR(120) NULL,
  `tehsil`     VARCHAR(120) NULL,
  `district`   VARCHAR(120) NULL,
  `state`      VARCHAR(120) NULL,
  `pincode`    VARCHAR(12)  NULL,
  `joined_on`  DATE NULL,
  `status`     ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `notes`      VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bc_user` (`user_id`),
  UNIQUE KEY `uq_bc_code` (`bc_code`),
  KEY `ix_bc_branch` (`branch_id`),
  KEY `ix_bc_status` (`status`),
  KEY `ix_bc_iibf` (`iibf_number`),
  KEY `ix_bc_dra` (`dra_id`),
  CONSTRAINT `fk_bc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bc_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Excel import pipeline
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `excel_mapping_templates`;
CREATE TABLE `excel_mapping_templates` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(160) NOT NULL,
  `description`  VARCHAR(255) NULL,
  -- JSON: {"account_number":"A/C No","borrower_name":"Customer Name", ...}
  `mapping`      LONGTEXT NOT NULL,
  `header_row`   INT UNSIGNED NOT NULL DEFAULT 1,
  `usage_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   DATETIME NULL,
  `updated_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mapping_name` (`name`),
  CONSTRAINT `fk_mapping_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `excel_imports`;
CREATE TABLE `excel_imports` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `original_name`    VARCHAR(255) NOT NULL,
  `stored_path`      VARCHAR(255) NOT NULL,
  `file_size`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256`           CHAR(64) NULL,
  `sheet_name`       VARCHAR(160) NULL,
  `header_row`       INT UNSIGNED NOT NULL DEFAULT 1,
  `detected_headers` LONGTEXT NULL,
  `mapping`          LONGTEXT NULL,
  `template_id`      BIGINT UNSIGNED NULL,
  `status`           ENUM('uploaded','mapped','importing','completed','failed','cancelled')
                     NOT NULL DEFAULT 'uploaded',
  `total_rows`       INT UNSIGNED NOT NULL DEFAULT 0,
  `imported_rows`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_accounts` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_accounts` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_rows`     INT UNSIGNED NOT NULL DEFAULT 0,
  `error_rows`       INT UNSIGNED NOT NULL DEFAULT 0,
  `duplicate_rows`   INT UNSIGNED NOT NULL DEFAULT 0,
  `assigned_rows`    INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at`       DATETIME NULL,
  `completed_at`     DATETIME NULL,
  `error_message`    VARCHAR(255) NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_imports_user` (`user_id`),
  KEY `ix_imports_status` (`status`),
  CONSTRAINT `fk_imports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_imports_template` FOREIGN KEY (`template_id`) REFERENCES `excel_mapping_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `excel_import_errors`;
CREATE TABLE `excel_import_errors` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      BIGINT UNSIGNED NOT NULL,
  `row_number`     INT UNSIGNED NOT NULL,
  `column_name`    VARCHAR(160) NULL,
  `account_number` VARCHAR(60)  NULL,
  `error_type`     ENUM('missing_required','invalid_data','duplicate','unknown_branch','invalid_bc','other')
                   NOT NULL DEFAULT 'other',
  `severity`       ENUM('error','warning') NOT NULL DEFAULT 'error',
  `message`        VARCHAR(255) NOT NULL,
  `raw_row`        LONGTEXT NULL,
  `created_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_import_errors_import` (`import_id`),
  KEY `ix_import_errors_type` (`error_type`),
  CONSTRAINT `fk_import_errors_import` FOREIGN KEY (`import_id`) REFERENCES `excel_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Loan accounts and allocation
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `loan_accounts`;
CREATE TABLE `loan_accounts` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_number`   VARCHAR(60)  NOT NULL,
  `cif`              VARCHAR(60)  NULL,
  `borrower_name`    VARCHAR(190) NOT NULL,
  `father_name`      VARCHAR(190) NULL,
  `mobile`           VARCHAR(20)  NULL,
  -- Section 2 of the field visit verification report. Aadhaar is held as the
  -- last four digits only, which is all the report prints (XXXX-XXXX-nnnn).
  `gender`           ENUM('male','female','other') NULL,
  `date_of_birth`    DATE NULL,
  `alternate_mobile` VARCHAR(20) NULL,
  `aadhaar_last4`    CHAR(4)     NULL,
  `pan_number`       VARCHAR(12) NULL,
  `village`          VARCHAR(160) NULL,
  `gram_panchayat`   VARCHAR(160) NULL,
  `tehsil`           VARCHAR(120) NULL,
  `district`         VARCHAR(120) NULL,
  `state`            VARCHAR(120) NULL,
  `pincode`          VARCHAR(12)  NULL,
  `address`          VARCHAR(500) NULL,
  `branch_id`        BIGINT UNSIGNED NOT NULL,
  -- Raw values exactly as they appeared in the uploaded sheet, kept for audit.
  `branch_code_raw`  VARCHAR(60) NULL,
  `bc_code_raw`      VARCHAR(60) NULL,
  `loan_type`        VARCHAR(120) NULL,
  `sanction_date`    DATE NULL,
  `npa_date`         DATE NULL,
  -- `limit_amount` is the sanction limit on the report.
  `limit_amount`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `drawing_power`    DECIMAL(15,2) NULL,
  `outstanding`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `interest_overdue` DECIMAL(15,2) NULL,
  `overdue`          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_recovered`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- Asset classification as printed in section 3.
  `asset_classification` ENUM('standard','sma_0','sma_1','sma_2','npa') NULL,
  -- Tags an account into the dedicated KRM OTS / CKCC OD-2 work streams.
  `loan_category`    ENUM('general','krm_ots','ckcc_od2') NOT NULL DEFAULT 'general',
  `status`           ENUM('active','closed','settled','written_off','excluded') NOT NULL DEFAULT 'active',
  `recovery_status`  ENUM('pending','in_progress','partly_recovered','recovered','ptp','ots','not_traceable')
                     NOT NULL DEFAULT 'pending',
  `visit_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `last_visit_at`    DATETIME NULL,
  `excel_import_id`  BIGINT UNSIGNED NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_number` (`account_number`),
  KEY `ix_accounts_branch` (`branch_id`),
  KEY `ix_accounts_cif` (`cif`),
  KEY `ix_accounts_mobile` (`mobile`),
  KEY `ix_accounts_borrower` (`borrower_name`),
  KEY `ix_accounts_village` (`village`),
  KEY `ix_accounts_category` (`loan_category`),
  KEY `ix_accounts_status` (`status`),
  KEY `ix_accounts_npa` (`npa_date`),
  KEY `ix_accounts_asset_class` (`asset_classification`),
  KEY `ix_accounts_import` (`excel_import_id`),
  CONSTRAINT `fk_accounts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_accounts_import` FOREIGN KEY (`excel_import_id`) REFERENCES `excel_imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_accounts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `account_assignments`;
CREATE TABLE `account_assignments` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,
  `bc_supervisor_id` BIGINT UNSIGNED NOT NULL,
  `branch_id`        BIGINT UNSIGNED NOT NULL,
  `method`           ENUM('excel_bc_code','auto_balance','manual','reassign') NOT NULL DEFAULT 'manual',
  `reason`           VARCHAR(255) NULL,
  -- 1 = current owner, NULL = historical. The UNIQUE key below therefore allows
  -- unlimited history rows but exactly one live assignment per account.
  `is_active`        TINYINT(1) NULL DEFAULT 1,
  `assigned_by`      BIGINT UNSIGNED NULL,
  `assigned_at`      DATETIME NULL,
  `unassigned_by`    BIGINT UNSIGNED NULL,
  `unassigned_at`    DATETIME NULL,
  `excel_import_id`  BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_active` (`loan_account_id`, `is_active`),
  KEY `ix_assignments_bc` (`bc_supervisor_id`),
  KEY `ix_assignments_branch` (`branch_id`),
  KEY `ix_assignments_active` (`is_active`),
  CONSTRAINT `fk_assign_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Configurable forms — customer visit (TYPE A) and BC inspection (TYPE B)
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `visit_forms`;
CREATE TABLE `visit_forms` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(160) NOT NULL,
  `description` VARCHAR(255) NULL,
  `visit_type`  ENUM('customer','krm_ots','ckcc_od2') NOT NULL DEFAULT 'customer',
  `version`     INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`  BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_visit_forms_type` (`visit_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `visit_form_fields`;
CREATE TABLE `visit_form_fields` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id`            BIGINT UNSIGNED NOT NULL,
  `field_key`          VARCHAR(80)  NOT NULL,
  `label`              VARCHAR(255) NOT NULL,
  `field_type`         ENUM('section','text','textarea','number','decimal','date','time','dropdown',
                            'radio','checkbox','yes_no','photo','signature','gps','remarks')
                       NOT NULL DEFAULT 'text',
  -- Newline separated choices for dropdown/radio/checkbox.
  `options`            TEXT NULL,
  `placeholder`        VARCHAR(160) NULL,
  -- Long enough for a compliance declaration, which the form must show verbatim.
  `help_text`          VARCHAR(2000) NULL,
  `is_required`        TINYINT(1) NOT NULL DEFAULT 0,
  `min_value`          DECIMAL(15,2) NULL,
  `max_value`          DECIMAL(15,2) NULL,
  `max_length`         INT UNSIGNED NULL,
  `sort_order`         INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  -- Conditional display: show this field only when the parent field matches.
  `condition_field_id` BIGINT UNSIGNED NULL,
  `condition_operator` ENUM('equals','not_equals','in','contains','filled','empty') NULL,
  `condition_value`    VARCHAR(255) NULL,
  `created_at`         DATETIME NULL,
  `updated_at`         DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visit_field_key` (`form_id`, `field_key`),
  KEY `ix_visit_fields_order` (`form_id`, `sort_order`),
  CONSTRAINT `fk_visit_fields_form` FOREIGN KEY (`form_id`) REFERENCES `visit_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visit_fields_condition` FOREIGN KEY (`condition_field_id`) REFERENCES `visit_form_fields` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inspection_forms`;
CREATE TABLE `inspection_forms` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(160) NOT NULL,
  `description` VARCHAR(255) NULL,
  `version`     INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `is_default`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_by`  BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_inspection_forms_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inspection_form_fields`;
CREATE TABLE `inspection_form_fields` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id`            BIGINT UNSIGNED NOT NULL,
  `field_key`          VARCHAR(80)  NOT NULL,
  `label`              VARCHAR(255) NOT NULL,
  `field_type`         ENUM('section','text','textarea','number','decimal','date','time','dropdown',
                            'radio','checkbox','yes_no','photo','signature','gps','remarks')
                       NOT NULL DEFAULT 'text',
  `options`            TEXT NULL,
  `placeholder`        VARCHAR(160) NULL,
  -- Long enough for a compliance declaration, which the form must show verbatim.
  `help_text`          VARCHAR(2000) NULL,
  `is_required`        TINYINT(1) NOT NULL DEFAULT 0,
  `min_value`          DECIMAL(15,2) NULL,
  `max_value`          DECIMAL(15,2) NULL,
  `max_length`         INT UNSIGNED NULL,
  `sort_order`         INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  `condition_field_id` BIGINT UNSIGNED NULL,
  `condition_operator` ENUM('equals','not_equals','in','contains','filled','empty') NULL,
  `condition_value`    VARCHAR(255) NULL,
  `created_at`         DATETIME NULL,
  `updated_at`         DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_field_key` (`form_id`, `field_key`),
  KEY `ix_inspection_fields_order` (`form_id`, `sort_order`),
  CONSTRAINT `fk_insp_fields_form` FOREIGN KEY (`form_id`) REFERENCES `inspection_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_fields_condition` FOREIGN KEY (`condition_field_id`) REFERENCES `inspection_form_fields` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Devices, tokens, offline sync
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `devices`;
CREATE TABLE `devices` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `device_uuid`      VARCHAR(120) NOT NULL,
  `model`            VARCHAR(120) NULL,
  `manufacturer`     VARCHAR(120) NULL,
  `os_version`       VARCHAR(60)  NULL,
  `app_version`      VARCHAR(40)  NULL,
  `fcm_token`        VARCHAR(255) NULL,
  `status`           ENUM('active','blocked','unbound') NOT NULL DEFAULT 'active',
  `bound_at`         DATETIME NULL,
  `last_seen_at`     DATETIME NULL,
  `last_ip`          VARCHAR(45) NULL,
  `last_latitude`    DECIMAL(10,7) NULL,
  `last_longitude`   DECIMAL(10,7) NULL,
  `last_location_at` DATETIME NULL,
  `last_address`     VARCHAR(255) NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_uuid` (`device_uuid`),
  KEY `ix_devices_user` (`user_id`),
  KEY `ix_devices_status` (`status`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `api_tokens`;
CREATE TABLE `api_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `device_id`    BIGINT UNSIGNED NULL,
  `name`         VARCHAR(80) NOT NULL DEFAULT 'android',
  `token_hash`   CHAR(64) NOT NULL,
  `last_used_at` DATETIME NULL,
  `expires_at`   DATETIME NULL,
  `revoked_at`   DATETIME NULL,
  `created_at`   DATETIME NULL,
  `updated_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tokens_hash` (`token_hash`),
  KEY `ix_tokens_user` (`user_id`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tokens_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `purpose`     ENUM('login','password_reset','late_approval') NOT NULL DEFAULT 'login',
  `code_hash`   CHAR(64) NOT NULL,
  `channel`     ENUM('sms','email','log') NOT NULL DEFAULT 'sms',
  `destination` VARCHAR(190) NULL,
  `attempts`    INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`  DATETIME NOT NULL,
  `consumed_at` DATETIME NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_otp_user` (`user_id`, `purpose`),
  KEY `ix_otp_expires` (`expires_at`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sync_batches`;
CREATE TABLE `sync_batches` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_uuid`       CHAR(36) NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `device_id`        BIGINT UNSIGNED NULL,
  `direction`        ENUM('push','pull') NOT NULL DEFAULT 'push',
  `items_received`   INT UNSIGNED NOT NULL DEFAULT 0,
  `items_accepted`   INT UNSIGNED NOT NULL DEFAULT 0,
  `items_duplicate`  INT UNSIGNED NOT NULL DEFAULT 0,
  `items_failed`     INT UNSIGNED NOT NULL DEFAULT 0,
  `app_version`      VARCHAR(40) NULL,
  `network_type`     VARCHAR(20) NULL,
  `started_at`       DATETIME NULL,
  `completed_at`     DATETIME NULL,
  `created_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sync_batch_uuid` (`batch_uuid`),
  KEY `ix_sync_user` (`user_id`),
  CONSTRAINT `fk_sync_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. TYPE A — BC Supervisor customer visits
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `visits`;
CREATE TABLE `visits` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Client generated on the device; makes offline replay idempotent.
  `uuid`                CHAR(36) NOT NULL,
  `loan_account_id`     BIGINT UNSIGNED NOT NULL,
  `bc_supervisor_id`    BIGINT UNSIGNED NOT NULL,
  `branch_id`           BIGINT UNSIGNED NOT NULL,
  `form_id`             BIGINT UNSIGNED NULL,
  -- "Case Type" in section 1 of the field visit verification report. The first
  -- three drive which form and which work stream applies; the rest are
  -- verification visits that use the customer form.
  `visit_type`          ENUM('customer','krm_ots','ckcc_od2','recovery_followup','pre_npa','post_npa','other')
                        NOT NULL DEFAULT 'customer',
  `visit_type_other`    VARCHAR(120) NULL,
  `visit_date`          DATE NOT NULL,
  -- Printed as "Visit Time". Distinct from started_at, which is when the device
  -- opened the visit: a queued offline visit can carry the real time of day.
  `visit_time`          TIME NULL,
  `started_at`          DATETIME NULL,
  `submitted_at`        DATETIME NULL,
  `server_received_at`  DATETIME NULL,
  `client_created_at`   DATETIME NULL,
  `visit_status`        ENUM('customer_met','family_met','phone_contact','house_locked','not_available',
                             'address_not_found','deceased','shifted','refused','other') NULL,
  `customer_available`  TINYINT(1) NULL,
  `family_met`          TINYINT(1) NULL,
  `phone_contact`       TINYINT(1) NULL,
  `house_locked`        TINYINT(1) NULL,
  `is_alive`            TINYINT(1) NULL,
  `current_address`     VARCHAR(500) NULL,
  `address_shifted`     TINYINT(1) NULL,
  `occupation`          VARCHAR(160) NULL,
  -- Section 6, the remaining two physical verification questions.
  `residence_verified`  TINYINT(1) NULL,
  `neighbour_verified`  TINYINT(1) NULL,
  -- Sections 7 and 10: the "documents verified" and "evidence attached"
  -- checklists, stored as a comma separated list of the ticked keys.
  `documents_verified`  VARCHAR(500) NULL,
  `documents_other`     VARCHAR(160) NULL,
  `evidence_attached`   VARCHAR(500) NULL,
  `evidence_other`      VARCHAR(160) NULL,
  `recovery_possibility` ENUM('high','medium','low','nil') NULL,
  `recommendation`      VARCHAR(500) NULL,
  `remarks`             TEXT NULL,
  `status`              ENUM('draft','submitted','late_pending','approved','rejected') NOT NULL DEFAULT 'submitted',
  `is_late`             TINYINT(1) NOT NULL DEFAULT 0,
  `late_reason`         VARCHAR(255) NULL,
  `approved_by`         BIGINT UNSIGNED NULL,
  `approved_at`         DATETIME NULL,
  `gps_verified`        TINYINT(1) NOT NULL DEFAULT 0,
  `gps_note`            VARCHAR(255) NULL,
  `photo_count`         INT UNSIGNED NOT NULL DEFAULT 0,
  `borrower_signature`  VARCHAR(255) NULL,
  -- Section 12: the BC Agent (= BC Supervisor) signs their own report, and an
  -- Admin/Supervisor countersigns it when approving. The verifier's name,
  -- designation and employee code are read from `approved_by`.
  `supervisor_signature` VARCHAR(255) NULL,
  `declaration_accepted` TINYINT(1) NOT NULL DEFAULT 0,
  `declared_at`         DATETIME NULL,
  `verifier_signature`  VARCHAR(255) NULL,
  `device_id`           BIGINT UNSIGNED NULL,
  `sync_batch_id`       BIGINT UNSIGNED NULL,
  `created_at`          DATETIME NULL,
  `updated_at`          DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visits_uuid` (`uuid`),
  KEY `ix_visits_account` (`loan_account_id`),
  KEY `ix_visits_bc_date` (`bc_supervisor_id`, `visit_date`),
  KEY `ix_visits_branch_date` (`branch_id`, `visit_date`),
  KEY `ix_visits_status` (`status`),
  KEY `ix_visits_type` (`visit_type`),
  CONSTRAINT `fk_visits_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visits_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_visits_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_visits_form` FOREIGN KEY (`form_id`) REFERENCES `visit_forms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visits_batch` FOREIGN KEY (`sync_batch_id`) REFERENCES `sync_batches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `visit_form_values`;
CREATE TABLE `visit_form_values` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id`   BIGINT UNSIGNED NOT NULL,
  `field_id`   BIGINT UNSIGNED NULL,
  `field_key`  VARCHAR(80) NOT NULL,
  `label`      VARCHAR(255) NULL,
  `field_type` VARCHAR(30) NULL,
  `value`      TEXT NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visit_value` (`visit_id`, `field_key`),
  KEY `ix_visit_values_field` (`field_id`),
  CONSTRAINT `fk_visit_values_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visit_values_field` FOREIGN KEY (`field_id`) REFERENCES `visit_form_fields` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `visit_gps`;
CREATE TABLE `visit_gps` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id`        BIGINT UNSIGNED NOT NULL,
  `event`           ENUM('start','form','photo','submit') NOT NULL DEFAULT 'start',
  `latitude`        DECIMAL(10,7) NOT NULL,
  `longitude`       DECIMAL(10,7) NOT NULL,
  `accuracy`        DECIMAL(8,2) NULL,
  `altitude`        DECIMAL(8,2) NULL,
  `speed`           DECIMAL(8,2) NULL,
  `provider`        VARCHAR(40) NULL,
  `is_mock`         TINYINT(1) NOT NULL DEFAULT 0,
  `address`         VARCHAR(255) NULL,
  `captured_at`     DATETIME NULL,
  `device_time`     DATETIME NULL,
  `server_time`     DATETIME NULL,
  `is_valid`        TINYINT(1) NOT NULL DEFAULT 1,
  `validation_note` VARCHAR(255) NULL,
  `created_at`      DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_visit_gps_visit` (`visit_id`, `event`),
  CONSTRAINT `fk_visit_gps_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `visit_photos`;
CREATE TABLE `visit_photos` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id`    BIGINT UNSIGNED NOT NULL,
  `photo_type`  ENUM('customer','house','shop','land','document','selfie','other','aadhaar') NOT NULL DEFAULT 'other',
  `file_path`   VARCHAR(255) NOT NULL,
  `file_name`   VARCHAR(190) NOT NULL,
  `mime_type`   VARCHAR(60)  NOT NULL DEFAULT 'image/jpeg',
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width`       INT UNSIGNED NULL,
  `height`      INT UNSIGNED NULL,
  `sha256`      CHAR(64) NULL,
  `latitude`    DECIMAL(10,7) NULL,
  `longitude`   DECIMAL(10,7) NULL,
  `accuracy`    DECIMAL(8,2) NULL,
  `address`     VARCHAR(255) NULL,
  `captured_at` DATETIME NULL,
  `watermarked` TINYINT(1) NOT NULL DEFAULT 0,
  `caption`     VARCHAR(255) NULL,
  `uploaded_by` BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_visit_photos_visit` (`visit_id`, `photo_type`),
  UNIQUE KEY `uq_visit_photo_hash` (`visit_id`, `sha256`),
  CONSTRAINT `fk_visit_photos_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. TYPE B — Admin/Supervisor inspections of BC Supervisors
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `inspections`;
CREATE TABLE `inspections` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36) NOT NULL,
  -- The Admin/Supervisor performing the inspection.
  `admin_user_id`     BIGINT UNSIGNED NOT NULL,
  -- The BC Supervisor whose field work is being verified.
  `bc_supervisor_id`  BIGINT UNSIGNED NOT NULL,
  `branch_id`         BIGINT UNSIGNED NOT NULL,
  `loan_account_id`   BIGINT UNSIGNED NULL,
  -- The specific customer visit being verified, when one exists.
  `visit_id`          BIGINT UNSIGNED NULL,
  `form_id`           BIGINT UNSIGNED NULL,
  `inspection_date`   DATE NOT NULL,
  `started_at`        DATETIME NULL,
  `submitted_at`      DATETIME NULL,
  `result`            ENUM('work_verified','partially_verified','not_satisfactory','customer_not_found',
                           'bc_not_present','visit_not_genuine','incorrect_information','gps_issue',
                           'photo_issue','other') NULL,
  `remarks`           TEXT NULL,
  `followup_required` TINYINT(1) NOT NULL DEFAULT 0,
  `status`            ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  `gps_verified`      TINYINT(1) NOT NULL DEFAULT 0,
  `photo_count`       INT UNSIGNED NOT NULL DEFAULT 0,
  `inspector_signature` VARCHAR(255) NULL,
  `bc_signature`      VARCHAR(255) NULL,
  `created_at`        DATETIME NULL,
  `updated_at`        DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspections_uuid` (`uuid`),
  KEY `ix_inspections_bc_date` (`bc_supervisor_id`, `inspection_date`),
  KEY `ix_inspections_branch_date` (`branch_id`, `inspection_date`),
  KEY `ix_inspections_admin` (`admin_user_id`),
  KEY `ix_inspections_visit` (`visit_id`),
  KEY `ix_inspections_result` (`result`),
  CONSTRAINT `fk_insp_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_insp_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_insp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_insp_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_form` FOREIGN KEY (`form_id`) REFERENCES `inspection_forms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inspection_form_values`;
CREATE TABLE `inspection_form_values` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_id` BIGINT UNSIGNED NOT NULL,
  `field_id`      BIGINT UNSIGNED NULL,
  `field_key`     VARCHAR(80) NOT NULL,
  `label`         VARCHAR(255) NULL,
  `field_type`    VARCHAR(30) NULL,
  `value`         TEXT NULL,
  `created_at`    DATETIME NULL,
  `updated_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_value` (`inspection_id`, `field_key`),
  KEY `ix_insp_values_field` (`field_id`),
  CONSTRAINT `fk_insp_values_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_values_field` FOREIGN KEY (`field_id`) REFERENCES `inspection_form_fields` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inspection_gps`;
CREATE TABLE `inspection_gps` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_id`   BIGINT UNSIGNED NOT NULL,
  `event`           ENUM('start','form','photo','submit') NOT NULL DEFAULT 'start',
  `latitude`        DECIMAL(10,7) NOT NULL,
  `longitude`       DECIMAL(10,7) NOT NULL,
  `accuracy`        DECIMAL(8,2) NULL,
  `provider`        VARCHAR(40) NULL,
  `is_mock`         TINYINT(1) NOT NULL DEFAULT 0,
  `address`         VARCHAR(255) NULL,
  `captured_at`     DATETIME NULL,
  `server_time`     DATETIME NULL,
  `is_valid`        TINYINT(1) NOT NULL DEFAULT 1,
  `validation_note` VARCHAR(255) NULL,
  -- Straight line distance to the BC Supervisor's own captured point, when the
  -- inspection is linked to a visit. This is the core "was it genuine" metric.
  `distance_to_visit_metres` DECIMAL(10,2) NULL,
  `created_at`      DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_insp_gps_inspection` (`inspection_id`, `event`),
  CONSTRAINT `fk_insp_gps_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `inspection_photos`;
CREATE TABLE `inspection_photos` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_id` BIGINT UNSIGNED NOT NULL,
  `photo_type`    ENUM('bc_supervisor','customer','location','document','selfie','other') NOT NULL DEFAULT 'other',
  `file_path`     VARCHAR(255) NOT NULL,
  `file_name`     VARCHAR(190) NOT NULL,
  `mime_type`     VARCHAR(60) NOT NULL DEFAULT 'image/jpeg',
  `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width`         INT UNSIGNED NULL,
  `height`        INT UNSIGNED NULL,
  `sha256`        CHAR(64) NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `accuracy`      DECIMAL(8,2) NULL,
  `address`       VARCHAR(255) NULL,
  `captured_at`   DATETIME NULL,
  `watermarked`   TINYINT(1) NOT NULL DEFAULT 0,
  `caption`       VARCHAR(255) NULL,
  `uploaded_by`   BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NULL,
  `updated_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_insp_photos_inspection` (`inspection_id`, `photo_type`),
  CONSTRAINT `fk_insp_photos_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Recovery, promises to pay, follow-ups
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `recoveries`;
CREATE TABLE `recoveries` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36) NOT NULL,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,
  `visit_id`         BIGINT UNSIGNED NULL,
  `bc_supervisor_id` BIGINT UNSIGNED NULL,
  `branch_id`        BIGINT UNSIGNED NOT NULL,
  `amount`           DECIMAL(15,2) NOT NULL,
  `recovery_date`    DATE NOT NULL,
  `payment_mode`     VARCHAR(40) NOT NULL DEFAULT 'Other',
  `receipt_number`   VARCHAR(80) NULL,
  `remarks`          VARCHAR(500) NULL,
  `status`           ENUM('recorded','verified','rejected') NOT NULL DEFAULT 'recorded',
  `verified_by`      BIGINT UNSIGNED NULL,
  `verified_at`      DATETIME NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recoveries_uuid` (`uuid`),
  KEY `ix_recoveries_account` (`loan_account_id`),
  KEY `ix_recoveries_bc_date` (`bc_supervisor_id`, `recovery_date`),
  KEY `ix_recoveries_branch_date` (`branch_id`, `recovery_date`),
  KEY `ix_recoveries_receipt` (`receipt_number`),
  CONSTRAINT `fk_recoveries_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recoveries_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recoveries_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recoveries_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `promises`;
CREATE TABLE `promises` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36) NOT NULL,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,
  `visit_id`         BIGINT UNSIGNED NULL,
  `bc_supervisor_id` BIGINT UNSIGNED NULL,
  `branch_id`        BIGINT UNSIGNED NOT NULL,
  `promise_amount`   DECIMAL(15,2) NOT NULL,
  `promise_date`     DATE NOT NULL,
  `followup_date`    DATE NULL,
  `remarks`          VARCHAR(500) NULL,
  `status`           ENUM('pending','kept','partially_kept','broken','cancelled') NOT NULL DEFAULT 'pending',
  `kept_amount`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `closed_at`        DATETIME NULL,
  `closed_by`        BIGINT UNSIGNED NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promises_uuid` (`uuid`),
  KEY `ix_promises_account` (`loan_account_id`),
  KEY `ix_promises_bc` (`bc_supervisor_id`),
  KEY `ix_promises_branch_date` (`branch_id`, `promise_date`),
  KEY `ix_promises_status` (`status`),
  CONSTRAINT `fk_promises_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_promises_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_promises_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_promises_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `followups`;
CREATE TABLE `followups` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36) NOT NULL,
  `loan_account_id`  BIGINT UNSIGNED NOT NULL,
  `visit_id`         BIGINT UNSIGNED NULL,
  `promise_id`       BIGINT UNSIGNED NULL,
  `bc_supervisor_id` BIGINT UNSIGNED NULL,
  `branch_id`        BIGINT UNSIGNED NOT NULL,
  `followup_date`    DATE NOT NULL,
  `action`           ENUM('call','visit','notice','legal','other') NOT NULL DEFAULT 'visit',
  `notes`            VARCHAR(500) NULL,
  `status`           ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at`     DATETIME NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_followups_uuid` (`uuid`),
  KEY `ix_followups_account` (`loan_account_id`),
  KEY `ix_followups_bc_date` (`bc_supervisor_id`, `followup_date`),
  KEY `ix_followups_branch_date` (`branch_id`, `followup_date`),
  KEY `ix_followups_status` (`status`),
  CONSTRAINT `fk_followups_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_followups_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_followups_promise` FOREIGN KEY (`promise_id`) REFERENCES `promises` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_followups_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_followups_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Dedicated work streams: KRM OTS and CKCC OD-2 renewal
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `krm_ots_cases`;
CREATE TABLE `krm_ots_cases` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_id`   BIGINT UNSIGNED NOT NULL,
  `branch_id`         BIGINT UNSIGNED NOT NULL,
  `bc_supervisor_id`  BIGINT UNSIGNED NULL,
  `visit_id`          BIGINT UNSIGNED NULL,
  -- Section 4 of the field visit verification report, "KRM OTS DETAILS".
  -- Deliberately holds no CKCC renewal fields: the two streams have separate
  -- reports and section 5 lives on `ckcc_renewals`.
  `ots_eligible`      TINYINT(1) NULL,
  `scheme`            ENUM('krm_ots','general_ots','other') NOT NULL DEFAULT 'krm_ots',
  `scheme_other`      VARCHAR(120) NULL,
  `outstanding`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- "Proposed Settlement" on the report.
  `ots_amount`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `borrower_share`    DECIMAL(15,2) NULL,
  `initial_deposit_required` DECIMAL(15,2) NULL,
  `sanctioned_amount` DECIMAL(15,2) NULL,
  `paid_amount`       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `customer_response` ENUM('agreed','requested_time','financial_difficulty','refused','not_eligible') NULL,
  `ots_status`        ENUM('proposed','under_review','approved','rejected','partly_paid','paid','closed','cancelled')
                      NOT NULL DEFAULT 'proposed',
  `visit_date`        DATE NULL,
  -- "Expected Deposit Date" on the report.
  `promise_date`      DATE NULL,
  -- Section 9, the KRM OTS recommendation options.
  `recommendation`    ENUM('proposal_recommended','followup_required','customer_refused','not_eligible') NULL,
  -- Section 13, "FINAL REPORT STATUS" for the KRM OTS stream.
  `final_status`      ENUM('customer_contacted','customer_verified','ots_accepted','ots_rejected',
                           'initial_deposit_received','ots_closed','followup_required') NULL,
  `remarks`           VARCHAR(500) NULL,
  `created_by`        BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NULL,
  `updated_at`        DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_krm_account` (`loan_account_id`),
  KEY `ix_krm_branch` (`branch_id`),
  KEY `ix_krm_status` (`ots_status`),
  KEY `ix_krm_bc` (`bc_supervisor_id`),
  KEY `ix_krm_final` (`final_status`),
  KEY `ix_krm_response` (`customer_response`),
  CONSTRAINT `fk_krm_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_krm_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_krm_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_krm_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ckcc_renewals`;
CREATE TABLE `ckcc_renewals` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_account_id`      BIGINT UNSIGNED NOT NULL,
  `branch_id`            BIGINT UNSIGNED NOT NULL,
  `bc_supervisor_id`     BIGINT UNSIGNED NULL,
  `visit_id`             BIGINT UNSIGNED NULL,
  `loan_type`            VARCHAR(120) NULL,
  `limit_amount`         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `outstanding`          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `overdue`              DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- Section 5 of the field visit verification report, "CKCC OD-2 RENEWAL
  -- DETAILS". Holds no KRM OTS fields; section 4 lives on `krm_ots_cases`.
  `renewal_eligible`     TINYINT(1) NULL,
  `renewal_due_bucket`   ENUM('within_30_days','within_15_days','within_7_days','overdue') NULL,
  `renewal_due_date`     DATE NULL,
  `expected_npa_date`    DATE NULL,
  -- Stored rather than derived: the report must reproduce what the supervisor
  -- was told in the field, even if the due date is later corrected.
  `days_remaining`       INT NULL,
  `kyc_status`           ENUM('complete','pending') NULL,
  `aadhaar_seeded`       TINYINT(1) NULL,
  `mobile_linked`        TINYINT(1) NULL,
  `aadhaar_authentication` ENUM('completed','pending') NULL,
  `renewal_consent`      TINYINT(1) NULL,
  `renewal_form_signed`  TINYINT(1) NULL,
  `biometrics_completed` TINYINT(1) NULL,
  `renewal_status`       ENUM('pending','documents_awaited','submitted','renewed','rejected','not_eligible','closed')
                         NOT NULL DEFAULT 'pending',
  `visit_date`           DATE NULL,
  `customer_availability` ENUM('available','not_available','shifted','deceased','refused') NULL,
  `documents_status`     ENUM('complete','partial','pending','not_submitted') NOT NULL DEFAULT 'pending',
  `documents_remarks`    VARCHAR(500) NULL,
  `renewed_on`           DATE NULL,
  -- Section 9, the CKCC renewal recommendation options.
  `recommendation`       ENUM('renew_immediately','documents_complete','documents_pending',
                              'customer_not_interested','branch_followup_required') NULL,
  -- Section 13, "FINAL REPORT STATUS" for the CKCC OD-2 stream.
  `final_status`         ENUM('customer_contacted','customer_verified','documents_collected','renewal_submitted',
                              'renewal_approved','pending_at_branch','became_npa','followup_required') NULL,
  `remarks`              VARCHAR(500) NULL,
  `created_by`           BIGINT UNSIGNED NULL,
  `created_at`           DATETIME NULL,
  `updated_at`           DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ckcc_account` (`loan_account_id`),
  KEY `ix_ckcc_branch` (`branch_id`),
  KEY `ix_ckcc_status` (`renewal_status`),
  KEY `ix_ckcc_bc` (`bc_supervisor_id`),
  KEY `ix_ckcc_final` (`final_status`),
  KEY `ix_ckcc_due` (`renewal_due_date`),
  CONSTRAINT `fk_ckcc_account` FOREIGN KEY (`loan_account_id`) REFERENCES `loan_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ckcc_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_ckcc_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ckcc_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Attendance and targets
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36) NOT NULL,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `bc_supervisor_id`  BIGINT UNSIGNED NOT NULL,
  `branch_id`         BIGINT UNSIGNED NOT NULL,
  `attendance_date`   DATE NOT NULL,
  `check_in_at`       DATETIME NULL,
  `check_in_lat`      DECIMAL(10,7) NULL,
  `check_in_lng`      DECIMAL(10,7) NULL,
  `check_in_accuracy` DECIMAL(8,2) NULL,
  `check_in_address`  VARCHAR(255) NULL,
  `selfie_path`       VARCHAR(255) NULL,
  `check_out_at`      DATETIME NULL,
  `check_out_lat`     DECIMAL(10,7) NULL,
  `check_out_lng`     DECIMAL(10,7) NULL,
  `check_out_accuracy` DECIMAL(8,2) NULL,
  `check_out_address` VARCHAR(255) NULL,
  `working_minutes`   INT UNSIGNED NOT NULL DEFAULT 0,
  `visits_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `status`            ENUM('present','half_day','absent','leave','holiday') NOT NULL DEFAULT 'present',
  `remarks`           VARCHAR(255) NULL,
  `device_id`         BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NULL,
  `updated_at`        DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_uuid` (`uuid`),
  UNIQUE KEY `uq_attendance_day` (`bc_supervisor_id`, `attendance_date`),
  KEY `ix_attendance_branch_date` (`branch_id`, `attendance_date`),
  CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `targets`;
CREATE TABLE `targets` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`            ENUM('bc_supervisor','branch') NOT NULL DEFAULT 'bc_supervisor',
  `bc_supervisor_id` BIGINT UNSIGNED NULL,
  `branch_id`        BIGINT UNSIGNED NULL,
  `period`           ENUM('daily','monthly') NOT NULL DEFAULT 'daily',
  `period_start`     DATE NOT NULL,
  `period_end`       DATE NOT NULL,
  `visit_target`     INT UNSIGNED NOT NULL DEFAULT 0,
  `recovery_target`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes`            VARCHAR(255) NULL,
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NULL,
  `updated_at`       DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_target_period` (`scope`, `bc_supervisor_id`, `branch_id`, `period`, `period_start`),
  KEY `ix_targets_branch` (`branch_id`),
  KEY `ix_targets_bc` (`bc_supervisor_id`),
  CONSTRAINT `fk_targets_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_targets_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Reporting: deadlines, submissions, exports
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `report_types`;
CREATE TABLE `report_types` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(60) NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NULL,
  -- Daily submissions are what the report deadline applies to.
  `is_daily`    TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_types_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `report_submissions`;
CREATE TABLE `report_submissions` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bc_supervisor_id`  BIGINT UNSIGNED NOT NULL,
  `branch_id`         BIGINT UNSIGNED NOT NULL,
  `report_type_id`    BIGINT UNSIGNED NOT NULL,
  `report_date`       DATE NOT NULL,
  -- Server computed; the device clock is never trusted for this.
  `deadline_at`       DATETIME NOT NULL,
  `submitted_at`      DATETIME NULL,
  `is_late`           TINYINT(1) NOT NULL DEFAULT 0,
  `status`            ENUM('pending','submitted','late_pending','late_approved','late_rejected','locked')
                      NOT NULL DEFAULT 'pending',
  `visits_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `recovery_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `promises_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `summary`           VARCHAR(500) NULL,
  `late_reason`       VARCHAR(500) NULL,
  `approved_by`       BIGINT UNSIGNED NULL,
  `approved_at`       DATETIME NULL,
  `approval_remarks`  VARCHAR(500) NULL,
  `device_id`         BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NULL,
  `updated_at`        DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_day` (`bc_supervisor_id`, `report_type_id`, `report_date`),
  KEY `ix_submissions_branch_date` (`branch_id`, `report_date`),
  KEY `ix_submissions_status` (`status`),
  CONSTRAINT `fk_submissions_bc` FOREIGN KEY (`bc_supervisor_id`) REFERENCES `bc_supervisors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submissions_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_submissions_type` FOREIGN KEY (`report_type_id`) REFERENCES `report_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submissions_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `report_exports`;
CREATE TABLE `report_exports` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `report_type_id` BIGINT UNSIGNED NULL,
  `report_slug`    VARCHAR(60) NOT NULL,
  `format`         ENUM('pdf','excel','csv') NOT NULL,
  `filters`        LONGTEXT NULL,
  `file_path`      VARCHAR(255) NULL,
  `file_name`      VARCHAR(190) NULL,
  `file_size`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `row_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `status`         ENUM('queued','completed','failed') NOT NULL DEFAULT 'queued',
  `error_message`  VARCHAR(255) NULL,
  `created_at`     DATETIME NULL,
  `updated_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_exports_user` (`user_id`),
  KEY `ix_exports_slug` (`report_slug`),
  CONSTRAINT `fk_exports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exports_type` FOREIGN KEY (`report_type_id`) REFERENCES `report_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Notifications, documents, settings, audit trail
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Exactly one of user_id / role_slug / branch_id targeting is used per row.
  `user_id`      BIGINT UNSIGNED NULL,
  `role_slug`    VARCHAR(40) NULL,
  `branch_id`    BIGINT UNSIGNED NULL,
  `title`        VARCHAR(190) NOT NULL,
  `body`         VARCHAR(500) NULL,
  `type`         ENUM('info','warning','alert','deadline','assignment','approval','inspection')
                 NOT NULL DEFAULT 'info',
  `link`         VARCHAR(255) NULL,
  `related_type` VARCHAR(60) NULL,
  `related_id`   BIGINT UNSIGNED NULL,
  `is_read`      TINYINT(1) NOT NULL DEFAULT 0,
  `read_at`      DATETIME NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   DATETIME NULL,
  `updated_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notifications_user` (`user_id`, `is_read`),
  KEY `ix_notifications_role` (`role_slug`),
  KEY `ix_notifications_branch` (`branch_id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_type`  ENUM('loan_account','visit','inspection','user','branch','import') NOT NULL,
  `owner_id`    BIGINT UNSIGNED NOT NULL,
  `title`       VARCHAR(190) NOT NULL,
  `doc_type`    VARCHAR(60) NULL,
  `file_path`   VARCHAR(255) NOT NULL,
  `file_name`   VARCHAR(190) NOT NULL,
  `mime_type`   VARCHAR(80) NULL,
  `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256`      CHAR(64) NULL,
  `notes`       VARCHAR(255) NULL,
  `uploaded_by` BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_documents_owner` (`owner_type`, `owner_id`),
  CONSTRAINT `fk_documents_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`        VARCHAR(100) NOT NULL,
  `value`      LONGTEXT NULL,
  `group`      VARCHAR(60) NOT NULL DEFAULT 'general',
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`key`),
  KEY `ix_settings_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NULL,
  -- Denormalised so the trail survives user deletion.
  `user_name`      VARCHAR(160) NULL,
  `role_slug`      VARCHAR(40)  NULL,
  `action`         VARCHAR(80)  NOT NULL,
  `entity_type`    VARCHAR(60)  NULL,
  `entity_id`      BIGINT UNSIGNED NULL,
  `description`    VARCHAR(500) NULL,
  `old_values`     LONGTEXT NULL,
  `new_values`     LONGTEXT NULL,
  `ip_address`     VARCHAR(45)  NULL,
  `user_agent`     VARCHAR(255) NULL,
  `device_id`      BIGINT UNSIGNED NULL,
  `request_method` VARCHAR(10)  NULL,
  `request_path`   VARCHAR(255) NULL,
  `created_at`     DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `ix_audit_user` (`user_id`),
  KEY `ix_audit_action` (`action`),
  KEY `ix_audit_entity` (`entity_type`, `entity_id`),
  KEY `ix_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
