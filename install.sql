-- UK Legal Articles Portal — Install Schema
-- Creates all tables and a default admin account.
--
-- Usage:
--   mysql -u root -p legalportal < install.sql
--
-- Default admin credentials (change immediately after first login):
--   Username : admin
--   Password : password

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `author_profiles`
--

CREATE TABLE `author_profiles` (
  `id` int NOT NULL,
  `subscriber_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `bio` text,
  `linkedin_url` varchar(500) DEFAULT NULL,
  `official_profile_url` varchar(500) DEFAULT NULL,
  `ghost_author_id` varchar(100) DEFAULT NULL,
  `ghost_author_slug` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `firm_profiles`
--

CREATE TABLE `firm_profiles` (
  `id` int NOT NULL,
  `subscriber_id` int NOT NULL,
  `firm_name` varchar(500) NOT NULL,
  `tagline` varchar(500) DEFAULT NULL,
  `description` longtext,
  `logo_path` varchar(500) DEFAULT NULL,
  `website` varchar(1000) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `address_line1` varchar(500) DEFAULT NULL,
  `address_line2` varchar(500) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `specialisms` json DEFAULT NULL,
  `linkedin_url` varchar(1000) DEFAULT NULL,
  `twitter_url` varchar(1000) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ghost_config`
--

CREATE TABLE `ghost_config` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'Default',
  `ghost_url` varchar(500) NOT NULL,
  `admin_api_key` varchar(500) NOT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rss_cache`
--

CREATE TABLE `rss_cache` (
  `id` int NOT NULL,
  `feed_id` int NOT NULL,
  `guid` varchar(1000) NOT NULL,
  `title` varchar(1000) DEFAULT NULL,
  `link` varchar(1000) DEFAULT NULL,
  `description` longtext,
  `content` longtext,
  `author` varchar(500) DEFAULT NULL,
  `author_profile_id` int DEFAULT NULL,
  `author_email` varchar(255) DEFAULT NULL,
  `pub_date` datetime DEFAULT NULL,
  `categories` json DEFAULT NULL,
  `featured_image` varchar(1000) DEFAULT NULL,
  `crawled_at` datetime DEFAULT NULL,
  `pushed_to_ghost` tinyint(1) DEFAULT '0',
  `ghost_post_id` varchar(100) DEFAULT NULL,
  `pushed_at` datetime DEFAULT NULL,
  `cached_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rss_feeds`
--

CREATE TABLE `rss_feeds` (
  `id` int NOT NULL,
  `subscriber_id` int NOT NULL,
  `url` varchar(1000) NOT NULL,
  `title` varchar(500) DEFAULT NULL,
  `description` text,
  `author_profile_id` int DEFAULT NULL,
  `crawl_full_content` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('active','paused','error') DEFAULT 'active',
  `last_fetched` datetime DEFAULT NULL,
  `fetch_interval` int DEFAULT '60',
  `error_message` text,
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `description` varchar(500) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tfa_tokens`
--

CREATE TABLE `tfa_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT '0',
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','manager','subscriber') NOT NULL DEFAULT 'subscriber',
  `status` enum('active','suspended','pending') NOT NULL DEFAULT 'active',
  `full_name` varchar(255) DEFAULT NULL,
  `firm_name` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `author_profiles`
--
ALTER TABLE `author_profiles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subscriber_id` (`subscriber_id`);

--
-- Indexes for table `firm_profiles`
--
ALTER TABLE `firm_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subscriber_id` (`subscriber_id`);

--
-- Indexes for table `ghost_config`
--
ALTER TABLE `ghost_config`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rss_cache`
--
ALTER TABLE `rss_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_feed_guid` (`feed_id`,`guid`(500)),
  ADD KEY `fk_cache_author` (`author_profile_id`);

--
-- Indexes for table `rss_feeds`
--
ALTER TABLE `rss_feeds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subscriber_id` (`subscriber_id`),
  ADD KEY `author_profile_id` (`author_profile_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `tfa_tokens`
--
ALTER TABLE `tfa_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `created_by` (`created_by`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `author_profiles`
--
ALTER TABLE `author_profiles`
  ADD CONSTRAINT `author_profiles_ibfk_1` FOREIGN KEY (`subscriber_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `firm_profiles`
--
ALTER TABLE `firm_profiles`
  ADD CONSTRAINT `firm_profiles_ibfk_1` FOREIGN KEY (`subscriber_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rss_cache`
--
ALTER TABLE `rss_cache`
  ADD CONSTRAINT `rss_cache_ibfk_1` FOREIGN KEY (`feed_id`) REFERENCES `rss_feeds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rss_cache_ibfk_2` FOREIGN KEY (`author_profile_id`) REFERENCES `author_profiles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `rss_feeds`
--
ALTER TABLE `rss_feeds`
  ADD CONSTRAINT `rss_feeds_ibfk_1` FOREIGN KEY (`subscriber_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rss_feeds_ibfk_2` FOREIGN KEY (`author_profile_id`) REFERENCES `author_profiles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `rss_feeds_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tfa_tokens`
--
ALTER TABLE `tfa_tokens`
  ADD CONSTRAINT `tfa_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
--
-- Default admin user (password: "password" — change immediately)
--

INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `status`, `full_name`)
VALUES ('admin', 'admin@example.com', '$2y$12$.albh53h0bW9xaATF6K7hOPVPUkQ85ksknNR0LKUL4lFDxhssXk8u', 'admin', 'active', 'Administrator');

--
-- Default system settings
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
('site_name',           'UK Legal Articles Portal', 'Portal display name'),
('mail_from',           'noreply@example.com',      'From address for 2FA emails'),
('mail_from_name',      'UK Legal Articles Portal', 'From name for 2FA emails'),
('max_login_attempts',  '5',                        'Failed logins before lockout'),
('lockout_duration',    '900',                      'Lockout duration in seconds (900 = 15 min)'),
('tfa_expiry',          '600',                      '2FA code lifetime in seconds (600 = 10 min)'),
('rss_cache_hours',     '24',                       'Minimum hours between feed fetches');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
