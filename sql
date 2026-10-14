CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action` varchar(32) NOT NULL,
  `key_type` enum('ip','login') NOT NULL DEFAULT 'ip',
  `key_value` varchar(100) NOT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `last_attempt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_action_key` (`action`, `key_type`, `key_value`),
  KEY `idx_last_attempt` (`last_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;