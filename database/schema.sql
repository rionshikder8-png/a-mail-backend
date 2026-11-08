-- A-Mail by Adverune: MySQL 8 / MariaDB 10.6+ schema (utf8mb4, InnoDB)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------ domains
CREATE TABLE IF NOT EXISTS domains (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(190) NOT NULL,
  is_primary    TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('active','pending','disabled') NOT NULL DEFAULT 'pending',
  dkim_selector VARCHAR(63) NULL,
  spf_ok        TINYINT(1) NOT NULL DEFAULT 0,
  dkim_ok       TINYINT(1) NOT NULL DEFAULT 0,
  dmarc_ok      TINYINT(1) NOT NULL DEFAULT 0,
  mx_ok         TINYINT(1) NOT NULL DEFAULT 0,
  checked_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_domains_name (name)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ subscription plans
CREATE TABLE IF NOT EXISTS subscription_plans (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL,
  name          VARCHAR(80) NOT NULL,
  price_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  currency      CHAR(3) NOT NULL DEFAULT 'USD',
  billing_period ENUM('free','monthly','yearly') NOT NULL DEFAULT 'free',
  quota_bytes   BIGINT UNSIGNED NOT NULL,
  max_attachment_bytes BIGINT UNSIGNED NOT NULL DEFAULT 20971520,
  features      JSON NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ users
CREATE TABLE IF NOT EXISTS users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  domain_id       BIGINT UNSIGNED NOT NULL,
  username        VARCHAR(40) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  status          ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  email_verified  TINYINT(1) NOT NULL DEFAULT 0,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  plan_id         BIGINT UNSIGNED NULL,
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  last_login_at   DATETIME NULL,
  suspended_reason VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  UNIQUE KEY uq_users_addr (domain_id, username),
  KEY ix_users_status (status),
  KEY ix_users_created (created_at),
  CONSTRAINT fk_users_domain FOREIGN KEY (domain_id) REFERENCES domains(id),
  CONSTRAINT fk_users_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_profiles (
  user_id        BIGINT UNSIGNED PRIMARY KEY,
  display_name   VARCHAR(100) NOT NULL,
  avatar_path    VARCHAR(255) NULL,
  recovery_email VARCHAR(190) NULL,
  recovery_phone VARCHAR(32) NULL,
  signature      TEXT NULL,
  language       VARCHAR(8) NOT NULL DEFAULT 'en',
  timezone       VARCHAR(64) NOT NULL DEFAULT 'UTC',
  settings       JSON NULL,
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- every address that delivers to a user (primary + future aliases)
CREATE TABLE IF NOT EXISTS email_addresses (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  address     VARCHAR(255) NOT NULL,
  is_primary  TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_addresses (address),
  KEY ix_email_addresses_user (user_id),
  CONSTRAINT fk_email_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------ auth
CREATE TABLE IF NOT EXISTS devices (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  device_name  VARCHAR(120) NOT NULL DEFAULT 'Unknown device',
  platform     ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  push_token   VARCHAR(512) NULL,
  last_ip      VARCHAR(45) NULL,
  last_active  DATETIME NULL,
  revoked_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_devices_user (user_id, revoked_at),
  KEY ix_devices_push (push_token(191)),
  CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NULL,
  token_hash   CHAR(64) NOT NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  expires_at   DATETIME NOT NULL,
  revoked_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sessions_token (token_hash),
  KEY ix_sessions_user (user_id, revoked_at),
  KEY ix_sessions_exp (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- refresh tokens rotate; family_id lets us revoke the whole chain on reuse
CREATE TABLE IF NOT EXISTS refresh_tokens (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  session_id   BIGINT UNSIGNED NOT NULL,
  family_id    CHAR(32) NOT NULL,
  token_hash   CHAR(64) NOT NULL,
  expires_at   DATETIME NOT NULL,
  used_at      DATETIME NULL,
  revoked_at   DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refresh_hash (token_hash),
  KEY ix_refresh_family (family_id),
  KEY ix_refresh_exp (expires_at),
  CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_refresh_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_codes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  purpose      ENUM('verify_email','reset_password','two_factor','recovery') NOT NULL,
  code_hash    CHAR(64) NOT NULL,
  challenge_id CHAR(32) NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME NOT NULL,
  consumed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_otp_lookup (user_id, purpose, consumed_at),
  UNIQUE KEY uq_otp_challenge (challenge_id),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_history (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  identifier   VARCHAR(255) NOT NULL,
  success      TINYINT(1) NOT NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  device_name  VARCHAR(120) NULL,
  reason       VARCHAR(80) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_login_user (user_id, created_at),
  KEY ix_login_ip (ip, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_logs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NULL,
  event        VARCHAR(80) NOT NULL,
  severity     ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  ip           VARCHAR(45) NULL,
  details      JSON NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_seclog_event (event, created_at),
  KEY ix_seclog_user (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       CHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ mail
CREATE TABLE IF NOT EXISTS email_threads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  subject_norm  VARCHAR(255) NOT NULL DEFAULT '',
  last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_threads_user (user_id, last_message_at),
  KEY ix_threads_subject (user_id, subject_norm(100)),
  CONSTRAINT fk_threads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- one row per mailbox copy: the sender's Sent copy and each recipient's inbox copy are separate rows
CREATE TABLE IF NOT EXISTS emails (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        BIGINT UNSIGNED NOT NULL,
  thread_id      BIGINT UNSIGNED NOT NULL,
  message_id     VARCHAR(255) NOT NULL,
  in_reply_to    VARCHAR(255) NULL,
  references_hdr TEXT NULL,
  from_name      VARCHAR(190) NOT NULL DEFAULT '',
  from_address   VARCHAR(255) NOT NULL,
  subject        VARCHAR(998) NOT NULL DEFAULT '',
  snippet        VARCHAR(255) NOT NULL DEFAULT '',
  body_html      MEDIUMTEXT NULL,
  body_text      MEDIUMTEXT NULL,
  folder         ENUM('inbox','sent','drafts','spam','trash','archive','scheduled') NOT NULL DEFAULT 'inbox',
  prev_folder    VARCHAR(16) NULL,
  is_read        TINYINT(1) NOT NULL DEFAULT 0,
  is_starred     TINYINT(1) NOT NULL DEFAULT 0,
  is_important   TINYINT(1) NOT NULL DEFAULT 0,
  is_draft       TINYINT(1) NOT NULL DEFAULT 0,
  has_attachments TINYINT(1) NOT NULL DEFAULT 0,
  size_bytes     INT UNSIGNED NOT NULL DEFAULT 0,
  spam_score     DECIMAL(5,2) NOT NULL DEFAULT 0,
  snoozed_until  DATETIME NULL,
  scheduled_at   DATETIME NULL,
  sent_state     ENUM('none','queued','sent','failed') NOT NULL DEFAULT 'none',
  received_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  trashed_at     DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_emails_folder (user_id, folder, received_at),
  KEY ix_emails_thread (thread_id, received_at),
  KEY ix_emails_msgid (user_id, message_id(120)),
  KEY ix_emails_star (user_id, is_starred, received_at),
  KEY ix_emails_snooze (snoozed_until),
  KEY ix_emails_sched (scheduled_at, sent_state),
  KEY ix_emails_trashed (trashed_at),
  FULLTEXT KEY ft_emails_search (subject, body_text),
  CONSTRAINT fk_emails_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_emails_thread FOREIGN KEY (thread_id) REFERENCES email_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_recipients (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_id    BIGINT UNSIGNED NOT NULL,
  kind        ENUM('to','cc','bcc') NOT NULL,
  name        VARCHAR(190) NOT NULL DEFAULT '',
  address     VARCHAR(255) NOT NULL,
  KEY ix_recipients_email (email_id),
  KEY ix_recipients_addr (address(120)),
  CONSTRAINT fk_recipients_email FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attachments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  email_id      BIGINT UNSIGNED NULL,
  file_name     VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(127) NOT NULL,
  size_bytes    BIGINT UNSIGNED NOT NULL,
  storage_path  VARCHAR(255) NOT NULL,
  sha256        CHAR(64) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_att_user (user_id),
  KEY ix_att_email (email_id),
  KEY ix_att_orphans (email_id, created_at),
  CONSTRAINT fk_att_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_email FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gmail-style folders: system folders are the ENUM above; custom folders live here
CREATE TABLE IF NOT EXISTS folders (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(80) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_folders_name (user_id, name),
  CONSTRAINT fk_folders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_folders (
  email_id    BIGINT UNSIGNED NOT NULL,
  folder_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (email_id, folder_id),
  CONSTRAINT fk_ef_email FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE,
  CONSTRAINT fk_ef_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS labels (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(80) NOT NULL,
  color       INT UNSIGNED NOT NULL DEFAULT 4280511487,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_labels_name (user_id, name),
  CONSTRAINT fk_labels_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_labels (
  email_id    BIGINT UNSIGNED NOT NULL,
  label_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (email_id, label_id),
  KEY ix_el_label (label_id),
  CONSTRAINT fk_el_email FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE,
  CONSTRAINT fk_el_label FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contacts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  email       VARCHAR(255) NOT NULL,
  phone       VARCHAR(32) NULL,
  note        VARCHAR(500) NULL,
  favourite   TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contacts_email (user_id, email),
  CONSTRAINT fk_contacts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------ notifications
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  type        ENUM('new_email','security','system') NOT NULL DEFAULT 'system',
  title       VARCHAR(160) NOT NULL,
  body        VARCHAR(500) NOT NULL DEFAULT '',
  email_id    BIGINT UNSIGNED NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  pushed_at   DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notif_user (user_id, is_read, created_at),
  KEY ix_notif_push (pushed_at, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------ storage
CREATE TABLE IF NOT EXISTS storage_quotas (
  user_id       BIGINT UNSIGNED PRIMARY KEY,
  quota_bytes   BIGINT UNSIGNED NOT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_quota_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS storage_usage (
  user_id           BIGINT UNSIGNED PRIMARY KEY,
  mail_bytes        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  attachments_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  trash_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  computed_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------ delivery / logs
CREATE TABLE IF NOT EXISTS mail_queue (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_id      BIGINT UNSIGNED NULL,
  user_id       BIGINT UNSIGNED NULL,
  direction     ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
  rcpt_address  VARCHAR(255) NOT NULL,
  raw_path      VARCHAR(255) NULL,
  status        ENUM('pending','sending','sent','deferred','failed') NOT NULL DEFAULT 'pending',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error    VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_queue_due (status, next_attempt_at),
  CONSTRAINT fk_queue_email FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mail_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  direction   ENUM('inbound','outbound','internal') NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  from_address VARCHAR(255) NULL,
  to_address  VARCHAR(255) NULL,
  subject_len SMALLINT UNSIGNED NULL,
  size_bytes  INT UNSIGNED NULL,
  message_id  VARCHAR(255) NULL,
  result      VARCHAR(40) NOT NULL,
  detail      VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_maillog_time (created_at),
  KEY ix_maillog_dir (direction, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue_id    BIGINT UNSIGNED NULL,
  rcpt_address VARCHAR(255) NOT NULL,
  status      ENUM('delivered','deferred','bounced','failed') NOT NULL,
  smtp_code   VARCHAR(8) NULL,
  response    VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_delivery_time (created_at),
  KEY ix_delivery_status (status, created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ billing
CREATE TABLE IF NOT EXISTS subscriptions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  plan_id     BIGINT UNSIGNED NOT NULL,
  status      ENUM('active','cancelled','expired','pending') NOT NULL DEFAULT 'active',
  started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ends_at     DATETIME NULL,
  note        VARCHAR(255) NULL,
  KEY ix_subs_user (user_id, status),
  CONSTRAINT fk_subs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_subs_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------ admin
CREATE TABLE IF NOT EXISTS admins (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('super_admin','admin','support','moderator') NOT NULL DEFAULT 'support',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_activity_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id    BIGINT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id   BIGINT UNSIGNED NULL,
  details     JSON NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_adminlog_admin (admin_id, created_at),
  KEY ix_adminlog_time (created_at),
  CONSTRAINT fk_adminlog_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS abuse_reports (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_user_id BIGINT UNSIGNED NULL,
  reporter_email VARCHAR(255) NULL,
  reported_address VARCHAR(255) NOT NULL,
  category      ENUM('spam','phishing','malware','harassment','other') NOT NULL DEFAULT 'spam',
  description   TEXT NULL,
  status        ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
  resolved_by   BIGINT UNSIGNED NULL,
  resolved_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_abuse_status (status, created_at),
  CONSTRAINT fk_abuse_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_abuse_admin FOREIGN KEY (resolved_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_tickets (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NULL,
  contact_email VARCHAR(255) NULL,
  subject       VARCHAR(190) NOT NULL,
  message       TEXT NOT NULL,
  status        ENUM('open','pending','closed') NOT NULL DEFAULT 'open',
  priority      ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  assigned_to   BIGINT UNSIGNED NULL,
  admin_reply   TEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_tickets_status (status, created_at),
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_admin FOREIGN KEY (assigned_to) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- broadcast notices created in the admin panel; delivered into user notifications by cron
CREATE TABLE IF NOT EXISTS broadcasts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id    BIGINT UNSIGNED NULL,
  title       VARCHAR(160) NOT NULL,
  body        VARCHAR(500) NOT NULL,
  audience    ENUM('all','plan_free','plan_paid') NOT NULL DEFAULT 'all',
  status      ENUM('pending','sent') NOT NULL DEFAULT 'pending',
  recipients  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME NULL,
  CONSTRAINT fk_broadcast_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
