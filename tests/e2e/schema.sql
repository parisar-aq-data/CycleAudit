
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor_id` int DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `action` varchar(20) NOT NULL,
  `road_group_id` int NOT NULL,
  `road_group_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `user_id` int NOT NULL,
  `road_id` int NOT NULL,
  `status` enum('active','completed','archived') NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_sessions_public_id` (`public_id`),
  KEY `idx_audit_sessions_user` (`user_id`),
  KEY `idx_audit_sessions_road` (`road_id`),
  KEY `idx_as_user_id` (`user_id`),
  KEY `idx_as_road_id` (`road_id`),
  KEY `idx_as_status` (`status`),
  CONSTRAINT `fk_sessions_road` FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cities_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `city_audits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `city_id` int NOT NULL,
  `state` varchar(100) NOT NULL,
  `name` varchar(150) NOT NULL,
  `audit_year` smallint unsigned NOT NULL,
  `audit_date` date DEFAULT NULL,
  `programme_info` text,
  `status` enum('draft','active','in_review','finalised','awaiting_approval','published','voided') NOT NULL DEFAULT 'draft',
  `created_by` int NOT NULL,
  `leader_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `admin_note` text,
  `admin_decided_at` datetime DEFAULT NULL,
  `admin_decided_by` int DEFAULT NULL,
  `public_hidden_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_city_audit_name` (`city_id`,`audit_year`,`name`),
  KEY `idx_city_audits_city_status` (`city_id`,`status`),
  KEY `fk_city_audits_creator` (`created_by`),
  KEY `idx_city_audits_leader` (`leader_user_id`),
  CONSTRAINT `fk_city_audits_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`),
  CONSTRAINT `fk_city_audits_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collaborators` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `role_label` varchar(60) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `logo_mime` varchar(30) DEFAULT NULL,
  `logo_data` mediumblob,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_visible` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `logo_scale` smallint NOT NULL DEFAULT '100',
  `logo_x` smallint NOT NULL DEFAULT '0',
  `logo_y` smallint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_collaborators_live` (`deleted_at`,`is_visible`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `intersections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `audit_id` int NOT NULL,
  `intersection_num` tinyint unsigned NOT NULL DEFAULT '1',
  `gps_coords` varchar(100) DEFAULT NULL,
  `landmark_name` varchar(255) DEFAULT NULL,
  `off_ramp` enum('Comfortable','Uncomfortable','No Ramp') DEFAULT NULL,
  `on_ramp` enum('Comfortable','Uncomfortable','No Ramp') DEFAULT NULL,
  `markings` enum('Present','Absent') DEFAULT NULL,
  `signage` enum('Present','Absent') DEFAULT NULL,
  `traffic_calming` enum('Present','Absent') DEFAULT NULL,
  `discontinuity` enum('Yes','No','NA') DEFAULT NULL,
  `tapering` enum('Yes','No','NA') DEFAULT NULL,
  `obstruction_type` enum('Partial','Total','None') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_intersections_public_id` (`public_id`),
  KEY `idx_int_audit_id` (`audit_id`),
  CONSTRAINT `fk_int_audit` FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `link` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_unread` (`user_id`,`is_read`,`id`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_states` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `state` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_state` (`state`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `obstructions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `audit_id` int NOT NULL,
  `obstruction_category` enum('fixed','movable','parked') NOT NULL,
  `obstruction_type` varchar(100) NOT NULL,
  `cyclist_slowed` smallint unsigned NOT NULL DEFAULT '0',
  `partial_obstructions` smallint unsigned NOT NULL DEFAULT '0',
  `total_obstructions` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_obstructions_public_id` (`public_id`),
  KEY `idx_obs_audit_id` (`audit_id`),
  CONSTRAINT `fk_obs_audit` FOREIGN KEY (`audit_id`) REFERENCES `segment_audits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `approver_role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city_id` int DEFAULT NULL,
  `new_password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_tries` tinyint unsigned NOT NULL DEFAULT '0',
  `status` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `handled_by` int DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pr_inbox` (`status`,`approver_role`,`city_id`,`created_at`),
  KEY `idx_pr_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `requested_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `past_audits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `city_name` varchar(100) NOT NULL,
  `state` varchar(100) NOT NULL DEFAULT '',
  `name` varchar(150) NOT NULL,
  `audit_date` date NOT NULL,
  `road_count` int unsigned NOT NULL DEFAULT '0',
  `segment_count` int unsigned NOT NULL DEFAULT '0',
  `length_km` decimal(8,1) NOT NULL DEFAULT '0.0',
  `score` decimal(5,2) DEFAULT NULL,
  `condition_label` varchar(10) DEFAULT NULL,
  `report_name` varchar(200) DEFAULT NULL,
  `report_data` longblob,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_past_audits_live` (`deleted_at`,`audit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limit_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'login',
  `attempts` tinyint unsigned NOT NULL DEFAULT '1',
  `locked_until` datetime DEFAULT NULL,
  `lockout_count` tinyint unsigned NOT NULL DEFAULT '0',
  `last_attempt_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_action` (`ip_address`,`action`),
  KEY `idx_locked_until` (`locked_until`),
  KEY `idx_last_attempt` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `road_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `road_id` int NOT NULL,
  `segment_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `status` enum('active','completed','revoked') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_road_id` (`road_id`),
  KEY `idx_segment_id` (`segment_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_road_assignments_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_road_assignments_road` FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`),
  CONSTRAINT `fk_road_assignments_segment` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`),
  CONSTRAINT `fk_road_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `road_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `canonical_name` varchar(150) NOT NULL,
  `city_id` int NOT NULL,
  `assigned_surveyor_id` int DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  `assigned_by` int DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `is_flagged` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_road_groups_city_name` (`city_id`,`canonical_name`),
  KEY `idx_road_groups_city_id` (`city_id`),
  KEY `idx_road_groups_assigned_surveyor` (`assigned_surveyor_id`),
  KEY `fk_road_groups_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_road_groups_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_road_groups_assigned_surveyor` FOREIGN KEY (`assigned_surveyor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_road_groups_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roads` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `creator_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `road_group_id` int DEFAULT NULL,
  `audit_id` int DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `start_point` varchar(255) DEFAULT NULL,
  `end_point` varchar(255) DEFAULT NULL,
  `total_length` decimal(8,2) DEFAULT NULL,
  `gps_start` varchar(100) DEFAULT NULL,
  `gps_end` varchar(100) DEFAULT NULL,
  `segment_method` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `segment_length` decimal(8,2) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finalized_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roads_public_id` (`public_id`),
  UNIQUE KEY `uq_roads_audit_group` (`audit_id`,`road_group_id`),
  KEY `fk_roads_creator` (`creator_id`),
  KEY `idx_roads_creator_id` (`creator_id`),
  KEY `fk_roads_road_group` (`road_group_id`),
  CONSTRAINT `fk_roads_audit` FOREIGN KEY (`audit_id`) REFERENCES `city_audits` (`id`),
  CONSTRAINT `fk_roads_road_group` FOREIGN KEY (`road_group_id`) REFERENCES `road_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `segment_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `audit_id` int NOT NULL,
  `segment_id` int NOT NULL,
  `surveyor_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `status` enum('assigned','submitted','needs_revisit','approved') NOT NULL DEFAULT 'assigned',
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_segment_assignment_segment` (`segment_id`),
  KEY `idx_segment_assignments_audit` (`audit_id`),
  KEY `idx_segment_assignments_surveyor` (`surveyor_id`),
  KEY `fk_segasg_assigned_by` (`assigned_by`),
  KEY `idx_sa_surveyor_status` (`surveyor_id`,`status`),
  CONSTRAINT `fk_segasg_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_segasg_audit` FOREIGN KEY (`audit_id`) REFERENCES `city_audits` (`id`),
  CONSTRAINT `fk_segasg_segment` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_segasg_surveyor` FOREIGN KEY (`surveyor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `segment_audits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `session_id` int NOT NULL,
  `segment_id` int NOT NULL,
  `surveyor_id` int NOT NULL,
  `start_landmark` varchar(255) NOT NULL,
  `end_landmark` varchar(255) NOT NULL,
  `gps_start` varchar(100) NOT NULL,
  `gps_end` varchar(100) NOT NULL,
  `cycle_track_missing` enum('Yes','No') DEFAULT NULL,
  `missing_length` decimal(8,2) DEFAULT NULL,
  `cyclist_use` enum('Yes','No') DEFAULT NULL,
  `better_surface` enum('Cycle Track','Road') DEFAULT NULL,
  `surface_material` enum('Interlock Blocks','Concrete','Asphalt') DEFAULT NULL,
  `people_walking` enum('Yes','No') DEFAULT NULL,
  `signage_count` smallint unsigned NOT NULL DEFAULT '0',
  `shade` enum('Yes','No','Partial') DEFAULT NULL,
  `light_after_sunset` enum('Yes','No','Partial') DEFAULT NULL,
  `track_geometry` enum('Road Level','Footpath Level','Segregated from FP&R','NA') DEFAULT NULL,
  `buffer_zone` enum('Segregated','Buffer Zone','None','NA') DEFAULT NULL,
  `surface_issues` longtext,
  `overhead_issues` longtext,
  `footpath_rating` longtext,
  `footpath_score` tinyint unsigned NOT NULL DEFAULT '0',
  `segment_width` decimal(6,2) DEFAULT NULL,
  `comments` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_segment_audits_public_id` (`public_id`),
  KEY `idx_sa_segment_id` (`segment_id`),
  KEY `idx_sa_session_id` (`session_id`),
  KEY `idx_sa_surveyor_id` (`surveyor_id`),
  CONSTRAINT `fk_sa_segment` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sa_session` FOREIGN KEY (`session_id`) REFERENCES `audit_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `segments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `road_id` int NOT NULL,
  `segment_number` smallint unsigned NOT NULL,
  `start_label` varchar(255) DEFAULT NULL,
  `end_label` varchar(255) DEFAULT NULL,
  `start_distance` decimal(8,2) NOT NULL DEFAULT '0.00',
  `end_distance` decimal(8,2) NOT NULL DEFAULT '0.00',
  `length` decimal(8,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_segments_public_id` (`public_id`),
  KEY `idx_segments_road` (`road_id`),
  KEY `idx_seg_road_id` (`road_id`),
  KEY `idx_seg_status` (`status`),
  CONSTRAINT `fk_segments_road` FOREIGN KEY (`road_id`) REFERENCES `roads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_id` varchar(20) NOT NULL DEFAULT '',
  `name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `organisation` varchar(200) DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `age` tinyint unsigned DEFAULT NULL,
  `address` text,
  `password` varchar(255) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `password_changed_at` datetime DEFAULT NULL,
  `role` enum('surveyor','city_leader','admin') NOT NULL DEFAULT 'surveyor',
  `city_id` int DEFAULT NULL,
  `team_leader_id` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `approval_status` varchar(10) NOT NULL DEFAULT 'approved',
  `auth_provider` enum('local','google') NOT NULL DEFAULT 'local',
  `google_id` varchar(100) DEFAULT NULL,
  `profile_picture` longtext,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `home_city` varchar(100) DEFAULT NULL,
  `home_state` varchar(100) DEFAULT NULL,
  `removal_pending_at` datetime DEFAULT NULL,
  `removal_action` varchar(12) DEFAULT NULL,
  `removal_requested_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_city_id` (`city_id`),
  KEY `idx_users_team_leader` (`team_leader_id`),
  CONSTRAINT `fk_users_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

