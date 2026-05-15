-- ============================================================
-- CLARA UNIFIED — Database Schema
-- ============================================================
CREATE DATABASE IF NOT EXISTS clara_unified
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE clara_unified;

-- ------------------------------------------------------------
-- Meta: properties
-- ------------------------------------------------------------
CREATE TABLE `properties` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(30) NOT NULL,
  `name` varchar(160) NOT NULL,
  `address` text DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `properties` (`id`, `key`, `name`, `address`, `status`) VALUES
  (1, 'ewalk',     'E-Walk Simply FUNtastic', 'Balikpapan', 'active'),
  (2, 'pentacity', 'Pentacity Shopping Venue', 'Balikpapan', 'active');

-- ------------------------------------------------------------
-- Users (shared — no property_id)
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'admin',
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `session_last_active` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- user_properties: which users can access which property
-- ------------------------------------------------------------
CREATE TABLE `user_properties` (
  `user_id` int(10) unsigned NOT NULL,
  `property_id` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`user_id`, `property_id`),
  CONSTRAINT `fk_up_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_up_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- role_permissions (shared — roles apply across properties)
-- ------------------------------------------------------------
CREATE TABLE `role_permissions` (
  `role` varchar(50) NOT NULL,
  `permission` varchar(80) NOT NULL,
  PRIMARY KEY (`role`, `permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- master_lookup_options (per property)
-- ------------------------------------------------------------
CREATE TABLE `master_lookup_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `category` varchar(40) NOT NULL,
  `value` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prop_cat_val` (`property_id`, `category`, `value`),
  KEY `idx_category` (`property_id`, `category`, `status`),
  CONSTRAINT `fk_lo_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- master_clients (shared across properties OR per property — decision: shared)
-- client can be linked to transactions in any property
-- ------------------------------------------------------------
CREATE TABLE `master_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(190) NOT NULL,
  `brand_name` varchar(190) DEFAULT NULL,
  `npwp` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `business_type` varchar(60) DEFAULT NULL,
  `business_scale` varchar(30) DEFAULT NULL,
  `brand_origin` varchar(30) DEFAULT NULL,
  `target_segment` text DEFAULT NULL,
  `channel` varchar(30) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `pic_user_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_clients_pic` (`pic_user_id`),
  KEY `idx_business_type` (`business_type`),
  KEY `idx_business_scale` (`business_scale`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_clients_pic` FOREIGN KEY (`pic_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- master_client_contacts (shared — follows master_clients)
-- ------------------------------------------------------------
CREATE TABLE `master_client_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_contacts_client` (`client_id`),
  CONSTRAINT `fk_contacts_client` FOREIGN KEY (`client_id`) REFERENCES `master_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- master_pic (per property)
-- ------------------------------------------------------------
CREATE TABLE `master_pic` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `name` varchar(120) NOT NULL,
  `role_name` varchar(120) DEFAULT NULL,
  `target_share` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_name` (`property_id`, `name`),
  CONSTRAINT `fk_pic_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- master_media (per property)
-- ------------------------------------------------------------
CREATE TABLE `master_media` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `code` varchar(40) NOT NULL,
  `media_type` varchar(120) NOT NULL,
  `location` varchar(160) NOT NULL,
  `point` varchar(160) DEFAULT NULL,
  `size` varchar(80) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `slots` decimal(12,2) NOT NULL DEFAULT 1.00,
  `rate` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pricing_type` varchar(40) NOT NULL DEFAULT 'daily_point',
  `package_note` varchar(120) DEFAULT NULL,
  `projection_monthly` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `effective_from` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_code` (`property_id`, `code`),
  CONSTRAINT `fk_media_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- master_cl_units (per property)
-- ------------------------------------------------------------
CREATE TABLE `master_cl_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `code` varchar(40) NOT NULL,
  `floor` varchar(40) NOT NULL,
  `location_name` varchar(160) NOT NULL,
  `unit_type` varchar(80) DEFAULT NULL,
  `area_sqm` decimal(12,2) NOT NULL DEFAULT 0.00,
  `rate` decimal(18,2) NOT NULL DEFAULT 0.00,
  `projection_monthly` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_code` (`property_id`, `code`),
  CONSTRAINT `fk_cl_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- master_gudang (per property)
-- ------------------------------------------------------------
CREATE TABLE `master_gudang` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `code` varchar(40) NOT NULL,
  `location` varchar(120) NOT NULL,
  `name` varchar(160) NOT NULL,
  `area_sqm` decimal(12,2) NOT NULL DEFAULT 0.00,
  `monthly_rate` decimal(18,2) NOT NULL DEFAULT 0.00,
  `projection_monthly` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(40) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_code` (`property_id`, `code`),
  CONSTRAINT `fk_gudang_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- periods (per property)
-- ------------------------------------------------------------
CREATE TABLE `periods` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `period_key` varchar(7) NOT NULL,
  `label` varchar(80) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_period` (`property_id`, `period_key`),
  CONSTRAINT `fk_periods_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- targets_monthly (per property)
-- ------------------------------------------------------------
CREATE TABLE `targets_monthly` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `period_key` varchar(7) NOT NULL,
  `target_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_period` (`property_id`, `period_key`),
  CONSTRAINT `fk_target_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- transactions (per property)
-- ------------------------------------------------------------
CREATE TABLE `transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `module` varchar(40) NOT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `contact_id` int(10) unsigned DEFAULT NULL,
  `master_code` varchar(40) NOT NULL,
  `period_key` varchar(7) DEFAULT NULL,
  `content_note` text DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `slots` decimal(12,2) NOT NULL DEFAULT 1.00,
  `area_sqm` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pricing_type` varchar(40) NOT NULL,
  `unit_rate` decimal(18,2) NOT NULL DEFAULT 0.00,
  `contract_months` int(11) DEFAULT NULL,
  `billing_method` varchar(40) NOT NULL DEFAULT 'anchor_cycle',
  `total_calculated` decimal(18,2) NOT NULL DEFAULT 0.00,
  `override_amount` decimal(18,2) DEFAULT NULL,
  `final_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pic_name` varchar(120) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `invoice_no` varchar(60) DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'posted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_transactions_dates` (`start_date`,`end_date`),
  KEY `fk_trx_client` (`client_id`),
  KEY `fk_trx_contact` (`contact_id`),
  KEY `idx_deleted` (`deleted_at`),
  KEY `idx_property` (`property_id`),
  CONSTRAINT `fk_trx_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `fk_trx_client` FOREIGN KEY (`client_id`) REFERENCES `master_clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trx_contact` FOREIGN KEY (`contact_id`) REFERENCES `master_client_contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- transaction_allocations (per property via transaction FK)
-- ------------------------------------------------------------
CREATE TABLE `transaction_allocations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `transaction_id` int(10) unsigned NOT NULL,
  `module` varchar(40) NOT NULL,
  `master_code` varchar(40) NOT NULL,
  `period_key` varchar(7) NOT NULL,
  `allocation_start` date NOT NULL,
  `allocation_end` date NOT NULL,
  `allocated_days` int(11) NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `capacity_days` decimal(18,2) NOT NULL DEFAULT 0.00,
  `pic_name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alloc_period_module` (`period_key`,`module`),
  KEY `idx_alloc_master` (`master_code`),
  KEY `fk_alloc_transaction` (`transaction_id`),
  KEY `idx_pic_name` (`pic_name`),
  KEY `idx_property` (`property_id`),
  CONSTRAINT `fk_alloc_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `fk_alloc_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- audit_logs (per property)
-- ------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `user_id` int(10) unsigned DEFAULT NULL,
  `actor` varchar(190) DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `module` varchar(80) DEFAULT NULL,
  `table_name` varchar(80) NOT NULL,
  `record_id` varchar(80) DEFAULT NULL,
  `route` varchar(120) DEFAULT NULL,
  `method` varchar(20) DEFAULT NULL,
  `ip_address` varchar(80) DEFAULT NULL,
  `computer_name` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`,`id`),
  KEY `idx_action` (`action`,`id`),
  KEY `idx_property` (`property_id`),
  CONSTRAINT `fk_audit_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
