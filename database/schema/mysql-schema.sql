/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `academic_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_levels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `language_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `sequence_number` int(10) unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_levels_id_language_center_unique` (`id`,`language_id`,`center_id`),
  UNIQUE KEY `academic_levels_language_sequence_unique` (`language_id`,`sequence_number`),
  KEY `academic_levels_language_center_foreign` (`language_id`,`center_id`),
  KEY `academic_levels_center_status_index` (`center_id`,`status`),
  KEY `academic_levels_center_language_status_index` (`center_id`,`language_id`,`status`),
  CONSTRAINT `academic_levels_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `academic_levels_language_center_foreign` FOREIGN KEY (`language_id`, `center_id`) REFERENCES `languages` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `account_identifier_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_identifier_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_identifier_code` char(2) NOT NULL,
  `role_code` varchar(50) NOT NULL,
  `last_sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_identifier_sequences_scope_unique` (`center_identifier_code`,`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `actor_role` varchar(50) NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `subject_type` varchar(100) NOT NULL,
  `subject_id` bigint(20) unsigned NOT NULL,
  `before_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_values`)),
  `after_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_values`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `audit_records_branch_center_foreign` (`branch_id`,`center_id`),
  KEY `audit_records_center_time_index` (`center_id`,`occurred_at`),
  KEY `audit_records_branch_time_index` (`center_id`,`branch_id`,`occurred_at`),
  KEY `audit_records_actor_time_index` (`actor_user_id`,`occurred_at`),
  KEY `audit_records_action_time_index` (`action_type`,`occurred_at`),
  KEY `audit_records_subject_index` (`subject_type`,`subject_id`),
  CONSTRAINT `audit_records_actor_user_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `audit_records_branch_center_foreign` FOREIGN KEY (`branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `audit_records_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `audit_records_branch_requires_center_check` CHECK (`branch_id` is null or `center_id` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_manager_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_manager_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `active_marker` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bm_assignments_active_user_unique` (`user_id`,`active_marker`),
  UNIQUE KEY `bm_assignments_active_branch_unique` (`branch_id`,`active_marker`),
  KEY `bm_assignments_user_center_foreign` (`user_id`,`center_id`),
  KEY `bm_assignments_branch_center_foreign` (`branch_id`,`center_id`),
  KEY `bm_assignments_center_branch_index` (`center_id`,`branch_id`),
  KEY `bm_assignments_center_ended_index` (`center_id`,`ended_at`),
  CONSTRAINT `bm_assignments_branch_center_foreign` FOREIGN KEY (`branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `bm_assignments_user_center_foreign` FOREIGN KEY (`user_id`, `center_id`) REFERENCES `users` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_managers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_managers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `person_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_managers_center_person_unique` (`center_id`,`person_id`),
  UNIQUE KEY `branch_managers_id_center_unique` (`id`,`center_id`),
  UNIQUE KEY `branch_managers_user_unique` (`user_id`),
  KEY `branch_managers_person_center_foreign` (`person_id`,`center_id`),
  KEY `branch_managers_user_person_center_foreign` (`user_id`,`person_id`,`center_id`),
  KEY `branch_managers_center_status_index` (`center_id`,`status`),
  CONSTRAINT `branch_managers_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `branch_managers_person_center_foreign` FOREIGN KEY (`person_id`, `center_id`) REFERENCES `people` (`id`, `center_id`),
  CONSTRAINT `branch_managers_user_person_center_foreign` FOREIGN KEY (`user_id`, `person_id`, `center_id`) REFERENCES `users` (`id`, `person_id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `working_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`working_hours`)),
  `status` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_center_code_unique` (`center_id`,`code`),
  UNIQUE KEY `branches_id_center_unique` (`id`,`center_id`),
  KEY `branches_center_status_index` (`center_id`,`status`),
  CONSTRAINT `branches_center_id_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `centers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `identifier_code` char(2) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `timezone` varchar(100) NOT NULL,
  `operating_currency_code` varchar(10) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'suspended',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `centers_code_unique` (`code`),
  UNIQUE KEY `centers_identifier_code_unique` (`identifier_code`),
  KEY `centers_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `classrooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classrooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `capacity` int(10) unsigned NOT NULL,
  `location` varchar(255) NOT NULL,
  `availability_status` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classrooms_id_branch_center_unique` (`id`,`branch_id`,`center_id`),
  KEY `classrooms_branch_center_foreign` (`branch_id`,`center_id`),
  KEY `classrooms_center_branch_index` (`center_id`,`branch_id`),
  KEY `classrooms_center_status_index` (`center_id`,`status`),
  KEY `classrooms_branch_availability_status_index` (`branch_id`,`availability_status`,`status`),
  CONSTRAINT `classrooms_branch_center_foreign` FOREIGN KEY (`branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `classrooms_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `course_id` bigint(20) unsigned NOT NULL,
  `assigned_classroom_id` bigint(20) unsigned NOT NULL,
  `assigned_teacher_id` bigint(20) unsigned NOT NULL,
  `class_code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `capacity` int(10) unsigned NOT NULL,
  `delivery_mode` varchar(50) NOT NULL,
  `class_status` varchar(20) NOT NULL DEFAULT 'planned',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_classes_center_class_code_unique` (`center_id`,`class_code`),
  UNIQUE KEY `course_classes_id_center_unique` (`id`,`center_id`),
  KEY `course_classes_branch_center_foreign` (`branch_id`,`center_id`),
  KEY `course_classes_course_center_foreign` (`course_id`,`center_id`),
  KEY `course_classes_classroom_branch_center_foreign` (`assigned_classroom_id`,`branch_id`,`center_id`),
  KEY `course_classes_teacher_center_foreign` (`assigned_teacher_id`,`center_id`),
  KEY `course_classes_center_branch_status_index` (`center_id`,`branch_id`,`class_status`),
  KEY `course_classes_center_course_status_index` (`center_id`,`course_id`,`class_status`),
  KEY `course_classes_center_teacher_status_index` (`center_id`,`assigned_teacher_id`,`class_status`),
  KEY `course_classes_center_classroom_index` (`center_id`,`assigned_classroom_id`),
  CONSTRAINT `course_classes_branch_center_foreign` FOREIGN KEY (`branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `course_classes_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `course_classes_classroom_branch_center_foreign` FOREIGN KEY (`assigned_classroom_id`, `branch_id`, `center_id`) REFERENCES `classrooms` (`id`, `branch_id`, `center_id`),
  CONSTRAINT `course_classes_course_center_foreign` FOREIGN KEY (`course_id`, `center_id`) REFERENCES `courses` (`id`, `center_id`),
  CONSTRAINT `course_classes_teacher_center_foreign` FOREIGN KEY (`assigned_teacher_id`, `center_id`) REFERENCES `teachers` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `course_prerequisites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_prerequisites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `course_id` bigint(20) unsigned NOT NULL,
  `prerequisite_course_id` bigint(20) unsigned NOT NULL,
  `requirement_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_prerequisites_pair_unique` (`center_id`,`course_id`,`prerequisite_course_id`),
  KEY `course_prerequisites_course_center_foreign` (`course_id`,`center_id`),
  KEY `course_prerequisites_prerequisite_center_foreign` (`prerequisite_course_id`,`center_id`),
  KEY `course_prerequisites_reverse_index` (`center_id`,`prerequisite_course_id`),
  CONSTRAINT `course_prerequisites_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `course_prerequisites_course_center_foreign` FOREIGN KEY (`course_id`, `center_id`) REFERENCES `courses` (`id`, `center_id`),
  CONSTRAINT `course_prerequisites_prerequisite_center_foreign` FOREIGN KEY (`prerequisite_course_id`, `center_id`) REFERENCES `courses` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `language_id` bigint(20) unsigned NOT NULL,
  `academic_level_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `duration_weeks` int(10) unsigned NOT NULL,
  `total_hours` decimal(8,2) NOT NULL,
  `default_fee` decimal(12,2) NOT NULL,
  `passing_grade` decimal(5,2) NOT NULL,
  `minimum_attendance` decimal(5,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `courses_id_center_unique` (`id`,`center_id`),
  KEY `courses_language_center_foreign` (`language_id`,`center_id`),
  KEY `courses_academic_level_language_center_foreign` (`academic_level_id`,`language_id`,`center_id`),
  KEY `courses_center_status_index` (`center_id`,`status`),
  KEY `courses_center_academic_structure_index` (`center_id`,`language_id`,`academic_level_id`),
  KEY `courses_center_code_index` (`center_id`,`code`),
  CONSTRAINT `courses_academic_level_language_center_foreign` FOREIGN KEY (`academic_level_id`, `language_id`, `center_id`) REFERENCES `academic_levels` (`id`, `language_id`, `center_id`),
  CONSTRAINT `courses_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `courses_language_center_foreign` FOREIGN KEY (`language_id`, `center_id`) REFERENCES `languages` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enrollment_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollment_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `enrollment_id` bigint(20) unsigned NOT NULL,
  `from_class_id` bigint(20) unsigned DEFAULT NULL,
  `to_class_id` bigint(20) unsigned DEFAULT NULL,
  `performed_by_user_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `previous_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `enrollment_histories_enrollment_center_foreign` (`enrollment_id`,`center_id`),
  KEY `enrollment_histories_from_class_center_foreign` (`from_class_id`,`center_id`),
  KEY `enrollment_histories_to_class_center_foreign` (`to_class_id`,`center_id`),
  KEY `enrollment_histories_actor_center_foreign` (`performed_by_user_id`,`center_id`),
  KEY `enrollment_histories_enrollment_time_index` (`center_id`,`enrollment_id`,`occurred_at`),
  KEY `enrollment_histories_from_class_index` (`center_id`,`from_class_id`),
  KEY `enrollment_histories_to_class_index` (`center_id`,`to_class_id`),
  KEY `enrollment_histories_actor_index` (`center_id`,`performed_by_user_id`),
  CONSTRAINT `enrollment_histories_actor_center_foreign` FOREIGN KEY (`performed_by_user_id`, `center_id`) REFERENCES `users` (`id`, `center_id`),
  CONSTRAINT `enrollment_histories_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `enrollment_histories_enrollment_center_foreign` FOREIGN KEY (`enrollment_id`, `center_id`) REFERENCES `enrollments` (`id`, `center_id`),
  CONSTRAINT `enrollment_histories_from_class_center_foreign` FOREIGN KEY (`from_class_id`, `center_id`) REFERENCES `course_classes` (`id`, `center_id`),
  CONSTRAINT `enrollment_histories_to_class_center_foreign` FOREIGN KEY (`to_class_id`, `center_id`) REFERENCES `course_classes` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `student_id` bigint(20) unsigned NOT NULL,
  `class_id` bigint(20) unsigned NOT NULL,
  `enrollment_number` varchar(50) NOT NULL,
  `enrollment_date` date NOT NULL,
  `enrollment_status` varchar(20) NOT NULL DEFAULT 'active',
  `eligibility_status` varchar(50) NOT NULL,
  `withdrawal_date` date DEFAULT NULL,
  `withdrawal_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollments_student_class_unique` (`center_id`,`student_id`,`class_id`),
  UNIQUE KEY `enrollments_id_center_unique` (`id`,`center_id`),
  UNIQUE KEY `enrollments_center_number_unique` (`center_id`,`enrollment_number`),
  KEY `enrollments_student_center_foreign` (`student_id`,`center_id`),
  KEY `enrollments_class_center_foreign` (`class_id`,`center_id`),
  KEY `enrollments_center_class_status_index` (`center_id`,`class_id`,`enrollment_status`),
  KEY `enrollments_center_student_status_index` (`center_id`,`student_id`,`enrollment_status`),
  CONSTRAINT `enrollments_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `enrollments_class_center_foreign` FOREIGN KEY (`class_id`, `center_id`) REFERENCES `course_classes` (`id`, `center_id`),
  CONSTRAINT `enrollments_student_center_foreign` FOREIGN KEY (`student_id`, `center_id`) REFERENCES `students` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_employee_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_employee_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `active_marker` tinyint(3) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fe_assignments_active_user_unique` (`user_id`,`active_marker`),
  KEY `fe_assignments_user_center_foreign` (`user_id`,`center_id`),
  KEY `fe_assignments_branch_center_foreign` (`branch_id`,`center_id`),
  KEY `fe_assignments_branch_active_index` (`branch_id`,`active_marker`),
  KEY `fe_assignments_center_ended_index` (`center_id`,`ended_at`),
  CONSTRAINT `fe_assignments_branch_center_foreign` FOREIGN KEY (`branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `fe_assignments_user_center_foreign` FOREIGN KEY (`user_id`, `center_id`) REFERENCES `users` (`id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `person_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_employees_center_person_unique` (`center_id`,`person_id`),
  UNIQUE KEY `finance_employees_id_center_unique` (`id`,`center_id`),
  UNIQUE KEY `finance_employees_user_unique` (`user_id`),
  KEY `finance_employees_person_center_foreign` (`person_id`,`center_id`),
  KEY `finance_employees_user_person_center_foreign` (`user_id`,`person_id`,`center_id`),
  KEY `finance_employees_center_status_index` (`center_id`,`status`),
  CONSTRAINT `finance_employees_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `finance_employees_person_center_foreign` FOREIGN KEY (`person_id`, `center_id`) REFERENCES `people` (`id`, `center_id`),
  CONSTRAINT `finance_employees_user_person_center_foreign` FOREIGN KEY (`user_id`, `person_id`, `center_id`) REFERENCES `users` (`id`, `person_id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `languages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_id_center_unique` (`id`,`center_id`),
  KEY `languages_center_status_index` (`center_id`,`status`),
  KEY `languages_center_code_index` (`center_id`,`code`),
  CONSTRAINT `languages_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `people` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `national_id_number` varchar(50) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `city_of_residence` varchar(150) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `personal_picture_path` varchar(2048) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `people_center_national_id_unique` (`center_id`,`national_id_number`),
  UNIQUE KEY `people_id_center_unique` (`id`,`center_id`),
  CONSTRAINT `people_center_id_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registration_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `registration_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `national_id_number` varchar(50) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `date_of_birth` date NOT NULL,
  `city_of_residence` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone_number` varchar(50) NOT NULL,
  `personal_picture_path` varchar(2048) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `selected_role_id` bigint(20) unsigned DEFAULT NULL,
  `selected_branch_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `pending_marker` tinyint(3) unsigned DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration_requests_pending_identity_unique` (`center_id`,`national_id_number`,`pending_marker`),
  KEY `registration_requests_selected_role_id_foreign` (`selected_role_id`),
  KEY `registration_requests_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `registration_requests_center_status_index` (`center_id`,`status`,`created_at`),
  KEY `registration_requests_branch_center_foreign` (`selected_branch_id`,`center_id`),
  KEY `registration_requests_center_branch_index` (`center_id`,`selected_branch_id`),
  CONSTRAINT `registration_requests_branch_center_foreign` FOREIGN KEY (`selected_branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `registration_requests_center_id_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `registration_requests_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `registration_requests_selected_role_id_foreign` FOREIGN KEY (`selected_role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `registration_requests_pending_marker_check` CHECK (`status` = 'pending' and `pending_marker` = 1 or `status` in ('approved','rejected') and `pending_marker` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_code_unique` (`code`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `person_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_center_person_unique` (`center_id`,`person_id`),
  UNIQUE KEY `students_id_center_unique` (`id`,`center_id`),
  UNIQUE KEY `students_user_unique` (`user_id`),
  KEY `students_branch_center_foreign` (`branch_id`,`center_id`),
  KEY `students_person_center_foreign` (`person_id`,`center_id`),
  KEY `students_user_person_center_foreign` (`user_id`,`person_id`,`center_id`),
  KEY `students_center_branch_status_index` (`center_id`,`branch_id`,`status`),
  KEY `students_center_status_index` (`center_id`,`status`),
  CONSTRAINT `students_branch_center_foreign` FOREIGN KEY (`branch_id`, `center_id`) REFERENCES `branches` (`id`, `center_id`),
  CONSTRAINT `students_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `students_person_center_foreign` FOREIGN KEY (`person_id`, `center_id`) REFERENCES `people` (`id`, `center_id`),
  CONSTRAINT `students_user_person_center_foreign` FOREIGN KEY (`user_id`, `person_id`, `center_id`) REFERENCES `users` (`id`, `person_id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teachers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned NOT NULL,
  `person_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teachers_center_person_unique` (`center_id`,`person_id`),
  UNIQUE KEY `teachers_id_center_unique` (`id`,`center_id`),
  UNIQUE KEY `teachers_user_unique` (`user_id`),
  KEY `teachers_person_center_foreign` (`person_id`,`center_id`),
  KEY `teachers_user_person_center_foreign` (`user_id`,`person_id`,`center_id`),
  KEY `teachers_center_status_index` (`center_id`,`status`),
  CONSTRAINT `teachers_center_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `teachers_person_center_foreign` FOREIGN KEY (`person_id`, `center_id`) REFERENCES `people` (`id`, `center_id`),
  CONSTRAINT `teachers_user_person_center_foreign` FOREIGN KEY (`user_id`, `person_id`, `center_id`) REFERENCES `users` (`id`, `person_id`, `center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `center_id` bigint(20) unsigned DEFAULT NULL,
  `person_id` bigint(20) unsigned DEFAULT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `account_login_identifier` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `recovery_email` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `failed_login_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `temporary_password_used_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_account_login_identifier_unique` (`account_login_identifier`),
  UNIQUE KEY `users_center_person_role_unique` (`center_id`,`person_id`,`role_id`),
  UNIQUE KEY `users_id_center_unique` (`id`,`center_id`),
  UNIQUE KEY `users_id_person_center_unique` (`id`,`person_id`,`center_id`),
  KEY `users_center_status_index` (`center_id`,`status`),
  KEY `users_status_index` (`status`),
  KEY `users_role_id_foreign` (`role_id`),
  KEY `users_person_center_foreign` (`person_id`,`center_id`),
  CONSTRAINT `users_center_id_foreign` FOREIGN KEY (`center_id`) REFERENCES `centers` (`id`),
  CONSTRAINT `users_person_center_foreign` FOREIGN KEY (`person_id`, `center_id`) REFERENCES `people` (`id`, `center_id`),
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_08_15_154700_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_15_161000_create_centers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_15_161700_add_operating_currency_code_to_centers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_15_163900_create_people_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_15_172000_add_lcms_account_foundation_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_16_135500_add_password_change_state_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_16_202519_create_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_17_063553_create_staff_branch_assignments_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_17_092930_create_classrooms_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_17_180953_create_audit_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_19_192506_create_students_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_20_133012_add_identifier_code_to_centers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_20_133018_add_registration_identity_fields_to_people_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_20_143236_create_registration_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_21_112908_create_account_identifier_sequences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_08_21_122059_add_selected_branch_to_registration_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_21_155553_create_staff_operational_records_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_24_090059_create_languages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_24_090100_create_academic_levels_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_24_090103_create_courses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_24_090109_create_course_prerequisites_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_24_134640_add_composite_identity_to_classrooms_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_24_134645_create_course_classes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_24_175603_create_enrollments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_24_175626_create_enrollment_histories_table',1);
