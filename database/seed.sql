-- Reference data only. No users, no mail, no fake accounts.
INSERT INTO domains (name, is_primary, status) VALUES ('adverune.store', 1, 'active')
  ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary);

INSERT INTO subscription_plans (code, name, price_cents, billing_period, quota_bytes, max_attachment_bytes, features, sort_order) VALUES
 ('free', 'Free', 0, 'free', 1073741824, 20971520, JSON_ARRAY('1 GB storage','Standard support'), 1),
 ('plus', 'Plus', 299, 'monthly', 16106127360, 52428800, JSON_ARRAY('15 GB storage','50 MB attachments','Priority support'), 2),
 ('pro', 'Professional', 799, 'monthly', 107374182400, 104857600, JSON_ARRAY('100 GB storage','100 MB attachments','Priority support'), 3)
 ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO system_settings (setting_key, setting_value) VALUES
 ('registration_open', '1'),
 ('maintenance_mode', '0'),
 ('trash_retention_days', '30'),
 ('spam_retention_days', '30'),
 ('max_recipients', '50'),
 ('daily_send_limit', '500')
 ON DUPLICATE KEY UPDATE setting_key = setting_key;
