-- MySQL dump 10.13  Distrib 8.0.46, for Win64 (x86_64)
--
-- Host: localhost    Database: laravelx
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `academic_calendar_activities`
--

DROP TABLE IF EXISTS `academic_calendar_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_calendar_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `academic_calendar_id` bigint unsigned NOT NULL,
  `academic_calendar_version_id` bigint unsigned DEFAULT NULL,
  `activity_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('academic','examination','admission','holiday','co_curricular','administrative','sports_cultural','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'academic',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `month` tinyint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `target_audience` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `department` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `venue_mode` enum('offline','online','hybrid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `venue_or_link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_tentative` tinyint(1) NOT NULL DEFAULT '0',
  `is_notified` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('planned','ongoing','completed','postponed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `description` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_calendar_activities_activity_code_unique` (`activity_code`),
  KEY `academic_calendar_activities_created_by_foreign` (`created_by`),
  KEY `academic_calendar_activities_updated_by_foreign` (`updated_by`),
  KEY `academic_calendar_activities_deleted_by_foreign` (`deleted_by`),
  KEY `ac_act_ver_fk` (`academic_calendar_version_id`),
  KEY `ac_act_cal_mo_yr_idx` (`academic_calendar_id`,`month`,`year`),
  KEY `ac_act_date_cat_idx` (`start_date`,`category`),
  KEY `academic_calendar_activities_category_index` (`category`),
  KEY `academic_calendar_activities_start_date_index` (`start_date`),
  KEY `academic_calendar_activities_month_index` (`month`),
  KEY `academic_calendar_activities_year_index` (`year`),
  KEY `academic_calendar_activities_status_index` (`status`),
  CONSTRAINT `ac_act_cal_fk` FOREIGN KEY (`academic_calendar_id`) REFERENCES `academic_calendars` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ac_act_ver_fk` FOREIGN KEY (`academic_calendar_version_id`) REFERENCES `academic_calendar_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendar_activities_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendar_activities_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendar_activities_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_calendar_activities`
--

LOCK TABLES `academic_calendar_activities` WRITE;
/*!40000 ALTER TABLE `academic_calendar_activities` DISABLE KEYS */;
INSERT INTO `academic_calendar_activities` VALUES (1,1,3,'ACT-2026-0001','Academic Session Commencement & Induction','academic','2026-04-01','2026-04-03',4,2026,'students','Academics','hybrid','Main Auditorium & Zoom Live Stream',0,0,'completed','Inauguration of new academic batches with address by eminent civil servants and syllabus orientation.',NULL,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,1,3,'ACT-2026-0002','Bengali New Year (Poila Boishakh) Holiday','holiday','2026-04-15',NULL,4,2026,'all','Administration','offline','Institute Closed',0,0,'completed','State institutional holiday on the occasion of Noboborsho.',NULL,2,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,1,3,'ACT-2026-0003','UPSC Prelims Mock Assessment Drive - Series 1','examination','2026-05-10','2026-05-10',5,2026,'students','Examination Cell','offline','Exam Halls A, B & C',0,0,'completed','General Studies Paper I & CSAT Paper II full-length simulated simulation.',NULL,3,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,1,3,'ACT-2026-0004','Rabindra Jayanti Observance & Cultural Meet','sports_cultural','2026-05-09',NULL,5,2026,'all','Student Affairs','offline','Seminar Hall 1',0,0,'completed','Commemoration of the birth anniversary of Kabiguru Rabindranath Tagore.',NULL,4,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,1,3,'ACT-2026-0005','Summer Intensive Answer Writing Bootcamp','academic','2026-06-08','2026-06-19',6,2026,'students','Academics','offline','Classrooms 101-105',0,0,'completed','Daily answer writing and structured evaluation under senior mentor guidance.',NULL,5,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(6,1,3,'ACT-2026-0006','Mid-Year Academic Progress Review Meeting','administrative','2026-07-15',NULL,7,2026,'faculty','Administration','offline','Board Room',0,0,'completed','Council review of syllabus coverage, attendance statistics, and student performance.',NULL,6,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(7,1,3,'ACT-2026-0007','Independence Day Flag Hoisting & Patriotic Discourse','holiday','2026-08-15',NULL,8,2026,'all','Administration','offline','Campus Grounds',0,0,'completed','National Celebration with ceremonial flag hoisting, cultural presentations, and student address.',NULL,7,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(8,1,3,'ACT-2026-0008','Teachers Day Special Seminar & Faculty Recognition','co_curricular','2026-09-05',NULL,9,2026,'all','Academics','offline','Auditorium',0,0,'completed','Felicitating faculty and guest professors followed by a pedagogy workshop.',NULL,8,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(9,1,3,'ACT-2026-0009','Durga Puja & Festive Autumn Recess','holiday','2026-10-18','2026-10-28',10,2026,'all','Administration','offline','Campus Closed',0,0,'completed','Annual autumn recess for students and staff on the occasion of Durga Puja and Vijaya Dashami.',NULL,9,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(10,1,3,'ACT-2026-0010','UPSC Mock Interview Cycle - Phase I','examination','2026-11-10','2026-11-14',11,2026,'aspirants','Examination Cell','hybrid','Executive Boardroom & Online Panel Room',0,0,'ongoing','Mock Personality Tests conducted by retired bureaucrats, diplomats, and senior academicians.',NULL,10,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(11,1,3,'ACT-2026-0011','National Colloquium on Public Policy & Administrative Reforms','co_curricular','2026-11-25','2026-11-26',11,2026,'all','Academics','hybrid','Main Conference Hall',0,0,'planned','National academic symposium featuring keynote addresses by distinguished administrative officers.',NULL,11,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(12,1,3,'ACT-2026-0012','Winter Vacation & Library Self-Study Period','holiday','2026-12-25','2027-01-01',12,2026,'all','Administration','offline','Library reading room remains accessible 9 AM - 6 PM',0,0,'planned','Year-end recess with special reading hall access for prelims aspirants.',NULL,12,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(13,1,3,'ACT-2027-0013','Republic Day Ceremonial Celebration','holiday','2027-01-26',NULL,1,2027,'all','Administration','offline','Campus Plaza',0,0,'planned','78th Republic Day commemoration and felicitation of student achievers.',NULL,13,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(14,1,3,'ACT-2027-0014','UPSC Prelims Final Sprint Assessment - Mock Series 5','examination','2027-02-14','2027-02-14',2,2027,'students','Examination Cell','offline','Exam Halls A, B & C',0,0,'planned','Comprehensive state-level benchmark test simulating actual UPSC exam day conditions.',NULL,14,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(15,1,3,'ACT-2027-0015','Annual Convocation, Alumni Conclave & Valedictory Session','academic','2027-03-20','2027-03-21',3,2027,'all','Student Affairs','offline','Grand Auditorium',0,0,'planned','Concluding valedictory assembly, alumni fellowship meet, and student send-off.',NULL,15,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `academic_calendar_activities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_calendar_versions`
--

DROP TABLE IF EXISTS `academic_calendar_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_calendar_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `academic_calendar_id` bigint unsigned NOT NULL,
  `version_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision_number` int unsigned NOT NULL DEFAULT '1',
  `version_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `file_mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `change_summary` text COLLATE utf8mb4_unicode_ci,
  `approved_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_date` date DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `download_count` int unsigned NOT NULL DEFAULT '0',
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `academic_calendar_versions_uploaded_by_foreign` (`uploaded_by`),
  KEY `academic_calendar_versions_created_by_foreign` (`created_by`),
  KEY `academic_calendar_versions_updated_by_foreign` (`updated_by`),
  KEY `academic_calendar_versions_deleted_by_foreign` (`deleted_by`),
  KEY `ac_ver_cal_cur_idx` (`academic_calendar_id`,`is_current`),
  KEY `ac_ver_cal_rev_idx` (`academic_calendar_id`,`revision_number`),
  KEY `academic_calendar_versions_is_current_index` (`is_current`),
  CONSTRAINT `ac_ver_cal_fk` FOREIGN KEY (`academic_calendar_id`) REFERENCES `academic_calendars` (`id`) ON DELETE CASCADE,
  CONSTRAINT `academic_calendar_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendar_versions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendar_versions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendar_versions_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_calendar_versions`
--

LOCK TABLES `academic_calendar_versions` WRITE;
/*!40000 ALTER TABLE `academic_calendar_versions` DISABLE KEYS */;
INSERT INTO `academic_calendar_versions` VALUES (1,1,'v1.0',1,'Initial Approved Annual Calendar','academic_calendars/ac-2026-27/academic_calendar_v1.0.pdf','SNTCSSC_Academic_Calendar_2026_27_v1.0.pdf',2106,'application/pdf','Initial publication of the Academic Calendar for session 2026-2027.','Academic Advisory Council','2026-03-25','2026-04-01',0,18,2,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,1,'v1.1',2,'Mid-Session Vacation & Test Rescheduling','academic_calendars/ac-2026-27/academic_calendar_v1.1.pdf','SNTCSSC_Academic_Calendar_2026_27_v1.1.pdf',2132,'application/pdf','Adjusted Durga Puja recess timeline to synchronize with state government gazette and shifted Prelims Test 3 by one week.','Director & Academic Board','2026-08-14','2026-08-16',0,42,2,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,1,'v1.2',3,'Current Approved Revised Calendar (Interview & Winter Schedule)','academic_calendars/ac-2026-27/academic_calendar_v1.2.pdf','SNTCSSC_Academic_Calendar_2026_27_v1.2_Revised.pdf',2139,'application/pdf','Incorporated national IAS/IPS mentor workshops, UPSC Mock Interview Phase 1 & 2 timelines, and updated classroom seatings.','Apex Governing Body','2026-10-20','2026-10-25',1,87,2,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,2,'v1.0',1,'Approved Annual Calendar 2025-2026','academic_calendars/ac-2026-27/academic_calendar_2025_v1.0.pdf','SNTCSSC_Academic_Calendar_2025_26_v1.0.pdf',2072,'application/pdf','Inaugural publication for academic session 2025-2026.','Academic Council','2025-03-28','2025-04-01',1,134,2,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `academic_calendar_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `academic_calendars`
--

DROP TABLE IF EXISTS `academic_calendars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `academic_calendars` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `calendar_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `academic_year` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `theme_or_motto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_working_days` smallint unsigned NOT NULL DEFAULT '0',
  `total_instructional_days` smallint unsigned NOT NULL DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `current_version_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_calendars_calendar_code_unique` (`calendar_code`),
  KEY `academic_calendars_created_by_foreign` (`created_by`),
  KEY `academic_calendars_updated_by_foreign` (`updated_by`),
  KEY `academic_calendars_deleted_by_foreign` (`deleted_by`),
  KEY `academic_calendars_academic_year_index` (`academic_year`),
  KEY `academic_calendars_status_index` (`status`),
  KEY `academic_calendars_is_current_index` (`is_current`),
  KEY `academic_calendars_current_version_id_index` (`current_version_id`),
  CONSTRAINT `academic_calendars_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendars_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `academic_calendars_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_calendars`
--

LOCK TABLES `academic_calendars` WRITE;
/*!40000 ALTER TABLE `academic_calendars` DISABLE KEYS */;
INSERT INTO `academic_calendars` VALUES (1,'AC-2026-27','2026-2027','Annual Institutional Academic Calendar (2026-2027)','Excellence in Civil Services Guidance & Administrative Leadership','2026-04-01','2027-03-31',248,195,'Comprehensive master schedule outlining foundational coursework, test series, guest lectures, state holiday recesses, and UPSC Mock Interview panels for the academic year 2026-2027.','published',1,3,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'AC-2025-26','2025-2026','Annual Institutional Academic Calendar (2025-2026)','Building Nation Builders & Civil Servants','2025-04-01','2026-03-31',245,192,'Official calendar for academic session 2025-2026, preserved for historical reference and institutional accreditation.','archived',0,NULL,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `academic_calendars` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumni`
--

DROP TABLE IF EXISTS `alumni`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumni` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `student_profile_id` bigint unsigned DEFAULT NULL,
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alternate_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `passing_year` smallint unsigned DEFAULT NULL,
  `service_category_id` bigint unsigned DEFAULT NULL,
  `service_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `designation` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cadre` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `all_india_rank` int unsigned DEFAULT NULL,
  `state_rank` int unsigned DEFAULT NULL,
  `exam_year` smallint unsigned DEFAULT NULL,
  `roll_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_district` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_country` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'India',
  `department_ministry` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_date` date DEFAULT NULL,
  `office_address` text COLLATE utf8mb4_unicode_ci,
  `current_address` text COLLATE utf8mb4_unicode_ci,
  `permanent_address` text COLLATE utf8mb4_unicode_ci,
  `linkedin_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_bio` text COLLATE utf8mb4_unicode_ci,
  `detailed_bio` longtext COLLATE utf8mb4_unicode_ci,
  `success_story` longtext COLLATE utf8mb4_unicode_ci,
  `testimonial` text COLLATE utf8mb4_unicode_ci,
  `achievements` json DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `is_verified` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `display_order` int NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alumni_uuid_unique` (`uuid`),
  KEY `alumni_user_id_foreign` (`user_id`),
  KEY `alumni_student_profile_id_foreign` (`student_profile_id`),
  KEY `alumni_created_by_foreign` (`created_by`),
  KEY `alumni_updated_by_foreign` (`updated_by`),
  KEY `alumni_deleted_by_foreign` (`deleted_by`),
  KEY `alumni_full_name_index` (`full_name`),
  KEY `alumni_email_index` (`email`),
  KEY `alumni_phone_index` (`phone`),
  KEY `alumni_passing_year_index` (`passing_year`),
  KEY `alumni_service_type_index` (`service_type`),
  KEY `alumni_service_name_index` (`service_name`),
  KEY `alumni_designation_index` (`designation`),
  KEY `alumni_exam_year_index` (`exam_year`),
  KEY `alumni_posting_state_index` (`posting_state`),
  KEY `alumni_is_featured_index` (`is_featured`),
  KEY `alumni_is_verified_index` (`is_verified`),
  KEY `alumni_status_index` (`status`),
  KEY `alumni_service_category_id_foreign` (`service_category_id`),
  CONSTRAINT `alumni_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_service_category_id_foreign` FOREIGN KEY (`service_category_id`) REFERENCES `alumni_service_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_student_profile_id_foreign` FOREIGN KEY (`student_profile_id`) REFERENCES `student_profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumni`
--

LOCK TABLES `alumni` WRITE;
/*!40000 ALTER TABLE `alumni` DISABLE KEYS */;
/*!40000 ALTER TABLE `alumni` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumni_course_batches`
--

DROP TABLE IF EXISTS `alumni_course_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumni_course_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumni_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `course_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enrollment_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `roll_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `academic_year` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_year` smallint unsigned DEFAULT NULL,
  `completion_year` smallint unsigned DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumni_course_batches_course_id_foreign` (`course_id`),
  KEY `alumni_course_batches_batch_id_foreign` (`batch_id`),
  KEY `alumni_course_batches_alumni_id_course_id_index` (`alumni_id`,`course_id`),
  KEY `alumni_course_batches_alumni_id_batch_id_index` (`alumni_id`,`batch_id`),
  KEY `alumni_course_batches_enrollment_no_index` (`enrollment_no`),
  CONSTRAINT `alumni_course_batches_alumni_id_foreign` FOREIGN KEY (`alumni_id`) REFERENCES `alumni` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alumni_course_batches_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_course_batches_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumni_course_batches`
--

LOCK TABLES `alumni_course_batches` WRITE;
/*!40000 ALTER TABLE `alumni_course_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `alumni_course_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumni_examinations`
--

DROP TABLE IF EXISTS `alumni_examinations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumni_examinations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumni_id` bigint unsigned NOT NULL,
  `examination_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_year` smallint unsigned NOT NULL,
  `service_allotted` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `all_india_rank` int unsigned DEFAULT NULL,
  `state_rank` int unsigned DEFAULT NULL,
  `roll_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `optional_subject` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `score_or_marks` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_rank` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumni_examinations_alumni_id_exam_year_index` (`alumni_id`,`exam_year`),
  KEY `alumni_examinations_exam_year_index` (`exam_year`),
  CONSTRAINT `alumni_examinations_alumni_id_foreign` FOREIGN KEY (`alumni_id`) REFERENCES `alumni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumni_examinations`
--

LOCK TABLES `alumni_examinations` WRITE;
/*!40000 ALTER TABLE `alumni_examinations` DISABLE KEYS */;
/*!40000 ALTER TABLE `alumni_examinations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumni_postings`
--

DROP TABLE IF EXISTS `alumni_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumni_postings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alumni_id` bigint unsigned NOT NULL,
  `designation` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_ministry` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_district` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posting_country` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'India',
  `office_address` text COLLATE utf8mb4_unicode_ci,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `order_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responsibilities` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `alumni_postings_alumni_id_is_current_index` (`alumni_id`,`is_current`),
  KEY `alumni_postings_alumni_id_from_date_index` (`alumni_id`,`from_date`),
  KEY `alumni_postings_is_current_index` (`is_current`),
  CONSTRAINT `alumni_postings_alumni_id_foreign` FOREIGN KEY (`alumni_id`) REFERENCES `alumni` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumni_postings`
--

LOCK TABLES `alumni_postings` WRITE;
/*!40000 ALTER TABLE `alumni_postings` DISABLE KEYS */;
/*!40000 ALTER TABLE `alumni_postings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `alumni_service_categories`
--

DROP TABLE IF EXISTS `alumni_service_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `alumni_service_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'award',
  `badge_color` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alumni_service_categories_code_unique` (`code`),
  KEY `alumni_service_categories_created_by_foreign` (`created_by`),
  KEY `alumni_service_categories_updated_by_foreign` (`updated_by`),
  KEY `alumni_service_categories_deleted_by_foreign` (`deleted_by`),
  KEY `alumni_service_categories_is_active_index` (`is_active`),
  KEY `alumni_service_categories_display_order_index` (`display_order`),
  CONSTRAINT `alumni_service_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_service_categories_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `alumni_service_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `alumni_service_categories`
--

LOCK TABLES `alumni_service_categories` WRITE;
/*!40000 ALTER TABLE `alumni_service_categories` DISABLE KEYS */;
INSERT INTO `alumni_service_categories` VALUES (1,'Union Public Service Commission (UPSC Civil Services)','UPSC CSE','UPSC CSE','Indian Administrative Service (IAS), Indian Police Service (IPS), Indian Foreign Service (IFS), and Indian Revenue Service (IRS).','award','violet',1,1,1,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(2,'West Bengal Civil Service (Executive)','West Bengal Civil Service (WBCS)','WBCS (Exe)','WBCS Executive cadre, Assistant Magistrate, Deputy Magistrate, and Block Development Officer (BDO).','briefcase','blue',1,1,2,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(3,'West Bengal Police Service','West Bengal Police Service (WBPS)','WBPS','Deputy Superintendent of Police (DSP) and Assistant Commissioner of Police (ACP).','shield','cyan',1,1,3,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(4,'Indian Forest Service','Indian Forest Service (IFoS)','IFoS','All India Service for conservation, forestry, ecology, and wildlife administration.','compass','emerald',1,1,4,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(5,'West Bengal Judicial Service / Higher Judicial','Judicial Services','Judicial Services','Civil Judge (Junior Division), Judicial Magistrate, and Higher Judicial Services officers.','scale','amber',1,1,5,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(6,'Central Armed Police Forces / Allied Services','Central Services / Allied','Central / Allied','CAPF Assistant Commandants (BSF, CRPF, CISF, ITBP, SSB), Combined Geo-Scientist, and Defence Services.','flag','indigo',1,1,6,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(7,'Banking & Finance (RBI / Bank PO / Regulators)','Banking & Finance','Banking & Finance','Reserve Bank of India (RBI Grade B), SEBI, NABARD, and Public Sector Bank Probationary Officers.','landmark','emerald',1,1,7,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(8,'Public Sector Undertaking / Central Autonomous','PSU / Central Govt','PSU / Central Govt','Maharatna / Navratna PSUs, SSC CGL Gazetted Officers, Railway Management, and ISRO/DRDO executive administration.','building-2','teal',1,1,8,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(9,'Other State Civil / Police Services','Other State Civil Services','Other State Services','State Public Service Commission recruits across Assam (ACS), Bihar (BPSC), Odisha (OPSC), Jharkhand (JPSC), and other state cadres.','map-pin','orange',1,1,9,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(10,'Corporate Leadership & Private Sector','Corporate / Private','Corporate','Strategic advisory, enterprise leadership, consulting, technology, and multinational corporate roles.','briefcase','zinc',1,1,10,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(11,'Higher Education & Academia / Faculty','Academia & Research','Academia & Research','University Professors, UGC NET/SET qualifiers, research scientists, and academic leaders.','graduation-cap','purple',1,1,11,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(12,'Other Services / Specialized Sectors','Other','Other','Other governmental, international development, and specialized professional services.','help-circle','slate',1,1,12,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL);
/*!40000 ALTER TABLE `alumni_service_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `api_key_logs`
--

DROP TABLE IF EXISTS `api_key_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_key_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_key_id` bigint unsigned DEFAULT NULL,
  `api_key_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_code` smallint unsigned NOT NULL,
  `duration_ms` double DEFAULT NULL,
  `request_headers` json DEFAULT NULL,
  `request_payload` json DEFAULT NULL,
  `response_summary` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `api_key_logs_api_key_id_created_at_index` (`api_key_id`,`created_at`),
  KEY `api_key_logs_ip_address_index` (`ip_address`),
  KEY `api_key_logs_status_code_index` (`status_code`),
  KEY `api_key_logs_created_at_index` (`created_at`),
  CONSTRAINT `api_key_logs_api_key_id_foreign` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_key_logs`
--

LOCK TABLES `api_key_logs` WRITE;
/*!40000 ALTER TABLE `api_key_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `api_key_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `api_keys`
--

DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'external_app',
  `environment` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `scopes` json DEFAULT NULL,
  `ip_allowlist` json DEFAULT NULL,
  `allowed_domains` json DEFAULT NULL,
  `rate_limit_per_minute` int unsigned NOT NULL DEFAULT '60',
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `last_used_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_requests` bigint unsigned NOT NULL DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by` bigint unsigned DEFAULT NULL,
  `revocation_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_uuid_unique` (`uuid`),
  UNIQUE KEY `api_keys_key_hash_unique` (`key_hash`),
  KEY `api_keys_user_id_foreign` (`user_id`),
  KEY `api_keys_revoked_by_foreign` (`revoked_by`),
  KEY `api_keys_created_by_foreign` (`created_by`),
  KEY `api_keys_updated_by_foreign` (`updated_by`),
  KEY `api_keys_deleted_by_foreign` (`deleted_by`),
  KEY `api_keys_status_environment_index` (`status`,`environment`),
  KEY `api_keys_type_status_index` (`type`,`status`),
  KEY `api_keys_key_prefix_index` (`key_prefix`),
  CONSTRAINT `api_keys_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `api_keys_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `api_keys_revoked_by_foreign` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `api_keys_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `api_keys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `api_keys`
--

LOCK TABLES `api_keys` WRITE;
/*!40000 ALTER TABLE `api_keys` DISABLE KEYS */;
/*!40000 ALTER TABLE `api_keys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_notifications`
--

DROP TABLE IF EXISTS `app_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` json DEFAULT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'database',
  `read_at` timestamp NULL DEFAULT NULL,
  `sound_played_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_notifications_uuid_unique` (`uuid`),
  KEY `app_notifications_created_by_foreign` (`created_by`),
  KEY `app_notifications_deleted_by_foreign` (`deleted_by`),
  KEY `app_notifications_user_id_read_at_index` (`user_id`,`read_at`),
  KEY `app_notifications_user_id_category_index` (`user_id`,`category`),
  KEY `app_notifications_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `app_notifications_type_index` (`type`),
  KEY `app_notifications_category_index` (`category`),
  KEY `app_notifications_channel_index` (`channel`),
  KEY `app_notifications_read_at_index` (`read_at`),
  CONSTRAINT `app_notifications_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `app_notifications_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `app_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_notifications`
--

LOCK TABLES `app_notifications` WRITE;
/*!40000 ALTER TABLE `app_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_holidays`
--

DROP TABLE IF EXISTS `attendance_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_holidays` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `to_date` date DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `course_id` bigint unsigned DEFAULT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_restricted` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_holidays_course_id_foreign` (`course_id`),
  KEY `attendance_holidays_batch_id_foreign` (`batch_id`),
  KEY `attendance_holidays_created_by_foreign` (`created_by`),
  KEY `attendance_holidays_updated_by_foreign` (`updated_by`),
  KEY `attendance_holidays_deleted_by_foreign` (`deleted_by`),
  KEY `attendance_holidays_date_index` (`date`),
  KEY `attendance_holidays_type_index` (`type`),
  KEY `attendance_holidays_target_type_index` (`target_type`),
  CONSTRAINT `attendance_holidays_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_holidays_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_holidays_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_holidays_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_holidays_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_holidays`
--

LOCK TABLES `attendance_holidays` WRITE;
/*!40000 ALTER TABLE `attendance_holidays` DISABLE KEYS */;
INSERT INTO `attendance_holidays` VALUES (1,'Republic Day','2026-01-26',NULL,'govt_holiday','all',NULL,NULL,'National Holiday - Republic Day of India.',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'Independence Day','2026-08-15',NULL,'govt_holiday','all',NULL,NULL,'National Holiday - Independence Day.',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,'Mahatma Gandhi Jayanti','2026-10-02',NULL,'govt_holiday','all',NULL,NULL,'National Holiday - Gandhi Jayanti.',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,'Weekly UPSC Prelims Assessment Test','2026-11-07',NULL,'weekly_test','student',NULL,NULL,'Scheduled Full-Length GS Paper 1 Mock Test for UPSC aspirants.',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `attendance_holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_notifications_log`
--

DROP TABLE IF EXISTS `attendance_notifications_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_notifications_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attendance_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `channel` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `message_preview` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_notifications_log_attendance_id_foreign` (`attendance_id`),
  KEY `attendance_notifications_log_user_id_notification_type_index` (`user_id`,`notification_type`),
  CONSTRAINT `attendance_notifications_log_attendance_id_foreign` FOREIGN KEY (`attendance_id`) REFERENCES `attendances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendance_notifications_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_notifications_log`
--

LOCK TABLES `attendance_notifications_log` WRITE;
/*!40000 ALTER TABLE `attendance_notifications_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_notifications_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendances`
--

DROP TABLE IF EXISTS `attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attendance_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `user_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `course_id` bigint unsigned DEFAULT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `section_id` bigint unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `working_hours` decimal(5,2) NOT NULL DEFAULT '0.00',
  `late_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `overtime_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `entry_source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_single',
  `leave_application_id` bigint unsigned DEFAULT NULL,
  `biometric_device_id` bigint unsigned DEFAULT NULL,
  `marked_by` bigint unsigned DEFAULT NULL,
  `remarks` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_sent` tinyint(1) NOT NULL DEFAULT '0',
  `notification_sent_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendances_attendance_no_unique` (`attendance_no`),
  UNIQUE KEY `attendances_date_user_deleted_unique` (`date`,`user_id`,`deleted_at`),
  KEY `attendances_user_id_foreign` (`user_id`),
  KEY `attendances_batch_id_foreign` (`batch_id`),
  KEY `attendances_section_id_foreign` (`section_id`),
  KEY `attendances_leave_application_id_foreign` (`leave_application_id`),
  KEY `attendances_biometric_device_id_foreign` (`biometric_device_id`),
  KEY `attendances_marked_by_foreign` (`marked_by`),
  KEY `attendances_created_by_foreign` (`created_by`),
  KEY `attendances_updated_by_foreign` (`updated_by`),
  KEY `attendances_deleted_by_foreign` (`deleted_by`),
  KEY `attendances_date_user_type_status_index` (`date`,`user_type`,`status`),
  KEY `attendances_course_id_batch_id_date_index` (`course_id`,`batch_id`,`date`),
  KEY `attendances_date_index` (`date`),
  KEY `attendances_user_type_index` (`user_type`),
  KEY `attendances_status_index` (`status`),
  KEY `attendances_notification_sent_index` (`notification_sent`),
  CONSTRAINT `attendances_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_biometric_device_id_foreign` FOREIGN KEY (`biometric_device_id`) REFERENCES `biometric_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_leave_application_id_foreign` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attendances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendances`
--

LOCK TABLES `attendances` WRITE;
/*!40000 ALTER TABLE `attendances` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `team_id` bigint unsigned DEFAULT NULL,
  `event` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `auditable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auditable_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `url` text COLLATE utf8mb4_unicode_ci,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  KEY `audit_logs_team_id_foreign` (`team_id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_created_at_index` (`created_at`),
  KEY `audit_logs_event_index` (`event`),
  CONSTRAINT `audit_logs_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backups`
--

DROP TABLE IF EXISTS `backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `backups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `disk` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'database_only',
  `db_driver` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sqlite',
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `tables_count` int unsigned NOT NULL DEFAULT '0',
  `records_count` int unsigned NOT NULL DEFAULT '0',
  `files_count` int unsigned NOT NULL DEFAULT '0',
  `checksum` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `duration_seconds` decimal(8,2) NOT NULL DEFAULT '0.00',
  `metadata` json DEFAULT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT '0',
  `email_recipient` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `backups_uuid_unique` (`uuid`),
  KEY `backups_created_by_foreign` (`created_by`),
  KEY `backups_deleted_by_foreign` (`deleted_by`),
  KEY `backups_status_deleted_at_index` (`status`,`deleted_at`),
  KEY `backups_type_index` (`type`),
  KEY `backups_trigger_type_index` (`trigger_type`),
  KEY `backups_created_at_index` (`created_at`),
  CONSTRAINT `backups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `backups_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backups`
--

LOCK TABLES `backups` WRITE;
/*!40000 ALTER TABLE `backups` DISABLE KEYS */;
/*!40000 ALTER TABLE `backups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `batch_courses`
--

DROP TABLE IF EXISTS `batch_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `batch_courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `capacity` int unsigned DEFAULT NULL,
  `fee` decimal(10,2) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_courses_batch_id_course_id_unique` (`batch_id`,`course_id`),
  KEY `batch_courses_course_id_foreign` (`course_id`),
  CONSTRAINT `batch_courses_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `batch_courses_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `batch_courses`
--

LOCK TABLES `batch_courses` WRITE;
/*!40000 ALTER TABLE `batch_courses` DISABLE KEYS */;
INSERT INTO `batch_courses` VALUES (1,1,1,200,1000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(2,2,1,200,1000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(3,1,2,NULL,1500.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(4,2,2,NULL,1500.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(5,1,3,NULL,12000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(6,2,3,NULL,12000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(7,1,4,NULL,10000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(8,2,4,NULL,10000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(9,1,5,NULL,3000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(10,2,5,NULL,3000.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(11,1,6,NULL,0.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(12,2,6,NULL,0.00,NULL,NULL,'active',NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL);
/*!40000 ALTER TABLE `batch_courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `batches`
--

DROP TABLE IF EXISTS `batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `academic_year` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upcoming',
  `description` text COLLATE utf8mb4_unicode_ci,
  `max_students` int unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `batches_code_unique` (`code`),
  KEY `batches_created_by_foreign` (`created_by`),
  KEY `batches_updated_by_foreign` (`updated_by`),
  KEY `batches_deleted_by_foreign` (`deleted_by`),
  KEY `batches_academic_year_index` (`academic_year`),
  KEY `batches_status_index` (`status`),
  CONSTRAINT `batches_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `batches_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `batches_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `batches`
--

LOCK TABLES `batches` WRITE;
/*!40000 ALTER TABLE `batches` DISABLE KEYS */;
INSERT INTO `batches` VALUES (1,'2026 Batch','BATCH-2026','2026','2026-01-01','2026-12-31','active','Academic session for UPSC Civil Services Examination 2026.',200,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(2,'2027 Batch','BATCH-2027','2027','2027-01-01','2027-12-31','upcoming','Academic session for UPSC Civil Services Examination 2027.',250,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(3,'2025 Batch','BATCH-2025','2025','2025-01-01','2025-12-31','completed','Academic session for UPSC Civil Services Examination 2025.',180,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL);
/*!40000 ALTER TABLE `batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `biometric_devices`
--

DROP TABLE IF EXISTS `biometric_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `biometric_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_serial_number` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port` int unsigned NOT NULL DEFAULT '4370',
  `location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hybrid',
  `api_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `last_heartbeat_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `biometric_devices_device_serial_number_unique` (`device_serial_number`),
  UNIQUE KEY `biometric_devices_api_token_unique` (`api_token`),
  KEY `biometric_devices_created_by_foreign` (`created_by`),
  KEY `biometric_devices_updated_by_foreign` (`updated_by`),
  KEY `biometric_devices_deleted_by_foreign` (`deleted_by`),
  KEY `biometric_devices_status_index` (`status`),
  CONSTRAINT `biometric_devices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `biometric_devices_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `biometric_devices_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `biometric_devices`
--

LOCK TABLES `biometric_devices` WRITE;
/*!40000 ALTER TABLE `biometric_devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `biometric_devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `biometric_logs`
--

DROP TABLE IF EXISTS `biometric_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `biometric_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `biometric_device_id` bigint unsigned DEFAULT NULL,
  `biometric_pin` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `card_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `punch_time` datetime NOT NULL,
  `punch_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `raw_payload` json DEFAULT NULL,
  `is_processed` tinyint(1) NOT NULL DEFAULT '0',
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `biometric_logs_biometric_device_id_foreign` (`biometric_device_id`),
  KEY `biometric_logs_user_id_foreign` (`user_id`),
  KEY `biometric_logs_biometric_pin_index` (`biometric_pin`),
  KEY `biometric_logs_card_number_index` (`card_number`),
  KEY `biometric_logs_punch_time_index` (`punch_time`),
  KEY `biometric_logs_is_processed_index` (`is_processed`),
  CONSTRAINT `biometric_logs_biometric_device_id_foreign` FOREIGN KEY (`biometric_device_id`) REFERENCES `biometric_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `biometric_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `biometric_logs`
--

LOCK TABLES `biometric_logs` WRITE;
/*!40000 ALTER TABLE `biometric_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `biometric_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_accession_histories`
--

DROP TABLE IF EXISTS `book_accession_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_accession_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `book_copy_id` bigint unsigned NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_condition` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_condition` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_shelf_location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_shelf_location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `book_accession_histories_book_copy_id_foreign` (`book_copy_id`),
  KEY `book_accession_histories_created_by_foreign` (`created_by`),
  CONSTRAINT `book_accession_histories_book_copy_id_foreign` FOREIGN KEY (`book_copy_id`) REFERENCES `book_copies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_accession_histories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_accession_histories`
--

LOCK TABLES `book_accession_histories` WRITE;
/*!40000 ALTER TABLE `book_accession_histories` DISABLE KEYS */;
INSERT INTO `book_accession_histories` VALUES (1,1,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 01, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(2,2,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 01, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(3,3,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 01, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(4,4,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 01, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(5,5,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 02, Shelf 1','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(6,6,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 02, Shelf 1','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(7,7,'acquired',NULL,'new',NULL,'available',NULL,'Stack A, Rack 02, Shelf 1','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(8,8,'acquired',NULL,'new',NULL,'available',NULL,'Stack B, Rack 01, Shelf 3','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(9,9,'acquired',NULL,'new',NULL,'available',NULL,'Stack B, Rack 01, Shelf 3','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(10,10,'acquired',NULL,'new',NULL,'available',NULL,'Stack B, Rack 01, Shelf 3','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(11,11,'acquired',NULL,'new',NULL,'available',NULL,'Stack B, Rack 02, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(12,12,'acquired',NULL,'new',NULL,'available',NULL,'Stack B, Rack 02, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(13,13,'acquired',NULL,'new',NULL,'available',NULL,'Stack B, Rack 02, Shelf 2','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(14,14,'acquired',NULL,'new',NULL,'available',NULL,'Stack C, Rack 01, Shelf 1','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(15,15,'acquired',NULL,'new',NULL,'available',NULL,'Stack C, Rack 01, Shelf 1','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(16,16,'acquired',NULL,'new',NULL,'available',NULL,'Stack C, Rack 01, Shelf 1','Initial accessioning into collection.',NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06');
/*!40000 ALTER TABLE `book_accession_histories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_author_pivot`
--

DROP TABLE IF EXISTS `book_author_pivot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_author_pivot` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `book_id` bigint unsigned NOT NULL,
  `author_id` bigint unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_author_pivot_book_id_author_id_unique` (`book_id`,`author_id`),
  KEY `book_author_pivot_author_id_foreign` (`author_id`),
  CONSTRAINT `book_author_pivot_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `book_authors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_author_pivot_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_author_pivot`
--

LOCK TABLES `book_author_pivot` WRITE;
/*!40000 ALTER TABLE `book_author_pivot` DISABLE KEYS */;
INSERT INTO `book_author_pivot` VALUES (1,1,1,1),(2,2,2,1),(3,3,3,1),(4,4,4,1),(5,5,5,1);
/*!40000 ALTER TABLE `book_author_pivot` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_authors`
--

DROP TABLE IF EXISTS `book_authors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_authors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `biography` text COLLATE utf8mb4_unicode_ci,
  `country` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_authors_slug_unique` (`slug`),
  KEY `book_authors_created_by_foreign` (`created_by`),
  KEY `book_authors_updated_by_foreign` (`updated_by`),
  KEY `book_authors_deleted_by_foreign` (`deleted_by`),
  KEY `book_authors_status_index` (`status`),
  KEY `book_authors_is_active_index` (`is_active`),
  CONSTRAINT `book_authors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_authors_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_authors_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_authors`
--

LOCK TABLES `book_authors` WRITE;
/*!40000 ALTER TABLE `book_authors` DISABLE KEYS */;
INSERT INTO `book_authors` VALUES (1,'M. Laxmikanth','m-laxmikanth','Author of renowned civil services reference books on Indian Polity and Governance.','India','India',NULL,NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'Bipan Chandra','bipan-chandra','Historian specialising in the economic and political history of modern India.','India','India',NULL,NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'Ramesh Singh','ramesh-singh','Economist and author on Indian Economy for competitive examinations.','India','India',NULL,NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'Majid Husain','majid-husain','Professor of Geography and author of extensive geographical treatises.','India','India',NULL,NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'G. Subba Rao & P.N. Roy Chowdhury','g-subba-rao-pn-roy-chowdhury','Civil servants and educators on ethics and administrative jurisprudence.','India','India',NULL,NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `book_authors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_categories`
--

DROP TABLE IF EXISTS `book_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ddc_classification` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_categories_slug_unique` (`slug`),
  KEY `book_categories_created_by_foreign` (`created_by`),
  KEY `book_categories_updated_by_foreign` (`updated_by`),
  KEY `book_categories_deleted_by_foreign` (`deleted_by`),
  KEY `book_categories_code_index` (`code`),
  KEY `book_categories_status_index` (`status`),
  KEY `book_categories_is_active_index` (`is_active`),
  CONSTRAINT `book_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_categories_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_categories`
--

LOCK TABLES `book_categories` WRITE;
/*!40000 ALTER TABLE `book_categories` DISABLE KEYS */;
INSERT INTO `book_categories` VALUES (1,'Indian Polity & Governance','indian-polity-governance','320.954',NULL,'Constitution, political systems, public administration and governance in India.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'Modern & Ancient Indian History','modern-ancient-indian-history','954.03',NULL,'Freedom struggle, ancient civilisations, and medieval Indian history.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'Indian Economy & Development','indian-economy-development','330.954',NULL,'Macroeconomics, planning, fiscal policy, and socio-economic reforms.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'Geography & Environment','geography-environment','915.4',NULL,'Physical, human, and economic geography of India and the world.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'Ethics, Integrity & Aptitude','ethics-integrity-aptitude','170.2',NULL,'Moral philosophy, administrative ethics, and case studies.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(6,'International Relations & Diplomacy','international-relations-diplomacy','327.54',NULL,'Foreign policy, bilateral ties, and multilateral organisations.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `book_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_copies`
--

DROP TABLE IF EXISTS `book_copies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_copies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `book_id` bigint unsigned NOT NULL,
  `accession_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rfid_tag` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `copy_number` int unsigned NOT NULL DEFAULT '1',
  `acquisition_date` date NOT NULL,
  `purchase_cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `supplier_details` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `funding_source` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classification_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classification_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shelf_location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `book_condition` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'good',
  `condition` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'good',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_copies_accession_no_unique` (`accession_no`),
  UNIQUE KEY `book_copies_barcode_unique` (`barcode`),
  KEY `book_copies_book_id_foreign` (`book_id`),
  KEY `book_copies_created_by_foreign` (`created_by`),
  KEY `book_copies_updated_by_foreign` (`updated_by`),
  KEY `book_copies_deleted_by_foreign` (`deleted_by`),
  KEY `book_copies_rfid_tag_index` (`rfid_tag`),
  KEY `book_copies_classification_number_index` (`classification_number`),
  KEY `book_copies_shelf_location_index` (`shelf_location`),
  KEY `book_copies_status_index` (`status`),
  KEY `book_copies_classification_no_index` (`classification_no`),
  KEY `book_copies_condition_index` (`condition`),
  CONSTRAINT `book_copies_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_copies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_copies_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_copies_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_copies`
--

LOCK TABLES `book_copies` WRITE;
/*!40000 ALTER TABLE `book_copies` DISABLE KEYS */;
INSERT INTO `book_copies` VALUES (1,1,'ACC-2026-00001','ACC-2026-00001',NULL,1,'2026-07-02',850.00,850.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-602','State Government Academic Grant','320.954 LAX','320.954 LAX','Stack A, Rack 01, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,1,'ACC-2026-00002','ACC-2026-00002',NULL,2,'2026-07-02',850.00,850.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-118','State Government Academic Grant','320.954 LAX','320.954 LAX','Stack A, Rack 01, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,1,'ACC-2026-00003','ACC-2026-00003',NULL,3,'2026-10-02',850.00,850.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-170','State Government Academic Grant','320.954 LAX','320.954 LAX','Stack A, Rack 01, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,1,'ACC-2026-00004','ACC-2026-00004',NULL,4,'2026-07-02',850.00,850.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-172','State Government Academic Grant','320.954 LAX','320.954 LAX','Stack A, Rack 01, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,2,'ACC-2026-00005','ACC-2026-00005',NULL,1,'2026-10-02',520.00,520.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-514','State Government Academic Grant','954.03 CHA','954.03 CHA','Stack A, Rack 02, Shelf 1','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(6,2,'ACC-2026-00006','ACC-2026-00006',NULL,2,'2026-08-02',520.00,520.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-924','State Government Academic Grant','954.03 CHA','954.03 CHA','Stack A, Rack 02, Shelf 1','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(7,2,'ACC-2026-00007','ACC-2026-00007',NULL,3,'2026-05-02',520.00,520.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-941','State Government Academic Grant','954.03 CHA','954.03 CHA','Stack A, Rack 02, Shelf 1','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(8,3,'ACC-2026-00008','ACC-2026-00008',NULL,1,'2026-08-02',780.00,780.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-342','State Government Academic Grant','330.954 SIN','330.954 SIN','Stack B, Rack 01, Shelf 3','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(9,3,'ACC-2026-00009','ACC-2026-00009',NULL,2,'2026-07-02',780.00,780.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-865','State Government Academic Grant','330.954 SIN','330.954 SIN','Stack B, Rack 01, Shelf 3','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(10,3,'ACC-2026-00010','ACC-2026-00010',NULL,3,'2026-05-02',780.00,780.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-408','State Government Academic Grant','330.954 SIN','330.954 SIN','Stack B, Rack 01, Shelf 3','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(11,4,'ACC-2026-00011','ACC-2026-00011',NULL,1,'2026-09-02',690.00,690.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-665','State Government Academic Grant','915.4 HUS','915.4 HUS','Stack B, Rack 02, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(12,4,'ACC-2026-00012','ACC-2026-00012',NULL,2,'2026-08-02',690.00,690.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-179','State Government Academic Grant','915.4 HUS','915.4 HUS','Stack B, Rack 02, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(13,4,'ACC-2026-00013','ACC-2026-00013',NULL,3,'2026-05-02',690.00,690.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-788','State Government Academic Grant','915.4 HUS','915.4 HUS','Stack B, Rack 02, Shelf 2','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(14,5,'ACC-2026-00014','ACC-2026-00014',NULL,1,'2026-07-02',590.00,590.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-142','State Government Academic Grant','170.2 SUB','170.2 SUB','Stack C, Rack 01, Shelf 1','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(15,5,'ACC-2026-00015','ACC-2026-00015',NULL,2,'2026-05-02',590.00,590.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-226','State Government Academic Grant','170.2 SUB','170.2 SUB','Stack C, Rack 01, Shelf 1','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(16,5,'ACC-2026-00016','ACC-2026-00016',NULL,3,'2026-10-02',590.00,590.00,'Oxford Book Depot, College Street, Kolkata','INV-BK-2026-532','State Government Academic Grant','170.2 SUB','170.2 SUB','Stack C, Rack 01, Shelf 1','new','new','available','Catalogued and accessioned into institutional central library.',NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `book_copies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_fines`
--

DROP TABLE IF EXISTS `book_fines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_fines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fine_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fine_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fine_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'overdue',
  `book_issue_id` bigint unsigned DEFAULT NULL,
  `library_member_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `overdue_days` int unsigned NOT NULL DEFAULT '0',
  `fine_rate_per_day` decimal(10,2) NOT NULL DEFAULT '0.00',
  `calculated_fine` decimal(10,2) NOT NULL DEFAULT '0.00',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `waived_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payable_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_transaction_id` bigint unsigned DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collected_by_id` bigint unsigned DEFAULT NULL,
  `waived_by` bigint unsigned DEFAULT NULL,
  `waived_by_id` bigint unsigned DEFAULT NULL,
  `waived_at` datetime DEFAULT NULL,
  `waiver_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_by_id` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_fines_fine_no_unique` (`fine_no`),
  KEY `book_fines_book_issue_id_foreign` (`book_issue_id`),
  KEY `book_fines_library_member_id_foreign` (`library_member_id`),
  KEY `book_fines_user_id_foreign` (`user_id`),
  KEY `book_fines_payment_transaction_id_foreign` (`payment_transaction_id`),
  KEY `book_fines_waived_by_foreign` (`waived_by`),
  KEY `book_fines_created_by_foreign` (`created_by`),
  KEY `book_fines_updated_by_foreign` (`updated_by`),
  KEY `book_fines_deleted_by_foreign` (`deleted_by`),
  KEY `book_fines_status_index` (`status`),
  KEY `book_fines_collected_by_id_foreign` (`collected_by_id`),
  KEY `book_fines_waived_by_id_foreign` (`waived_by_id`),
  KEY `book_fines_created_by_id_foreign` (`created_by_id`),
  KEY `book_fines_fine_number_index` (`fine_number`),
  KEY `book_fines_fine_type_index` (`fine_type`),
  CONSTRAINT `book_fines_book_issue_id_foreign` FOREIGN KEY (`book_issue_id`) REFERENCES `book_issues` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_collected_by_id_foreign` FOREIGN KEY (`collected_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_library_member_id_foreign` FOREIGN KEY (`library_member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_fines_payment_transaction_id_foreign` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_waived_by_foreign` FOREIGN KEY (`waived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_fines_waived_by_id_foreign` FOREIGN KEY (`waived_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_fines`
--

LOCK TABLES `book_fines` WRITE;
/*!40000 ALTER TABLE `book_fines` DISABLE KEYS */;
/*!40000 ALTER TABLE `book_fines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_issues`
--

DROP TABLE IF EXISTS `book_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_issues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `issue_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `book_id` bigint unsigned NOT NULL,
  `book_copy_id` bigint unsigned NOT NULL,
  `library_member_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `due_time` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `returned_time` datetime DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'issued',
  `issue_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'circulation',
  `inhouse_session_token` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reader_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reader_phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reader_identifier` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reading_room_seat_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `renewal_count` int unsigned NOT NULL DEFAULT '0',
  `max_renewals_allowed` int unsigned NOT NULL DEFAULT '2',
  `last_renewed_at` datetime DEFAULT NULL,
  `renewal_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `renewal_requested_at` datetime DEFAULT NULL,
  `renewal_requested_days` int unsigned NOT NULL DEFAULT '15',
  `renewal_request_reason` text COLLATE utf8mb4_unicode_ci,
  `renewal_reviewed_by` bigint unsigned DEFAULT NULL,
  `renewal_reviewed_at` datetime DEFAULT NULL,
  `renewal_rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `issued_by` bigint unsigned DEFAULT NULL,
  `returned_by` bigint unsigned DEFAULT NULL,
  `return_condition` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `book_price_at_issue` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_deposit_bypassed` tinyint(1) NOT NULL DEFAULT '0',
  `bypass_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `last_reminder_sent_at` datetime DEFAULT NULL,
  `reminders_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_issues_issue_no_unique` (`issue_no`),
  KEY `book_issues_book_id_foreign` (`book_id`),
  KEY `book_issues_book_copy_id_foreign` (`book_copy_id`),
  KEY `book_issues_library_member_id_foreign` (`library_member_id`),
  KEY `book_issues_user_id_foreign` (`user_id`),
  KEY `book_issues_issued_by_foreign` (`issued_by`),
  KEY `book_issues_returned_by_foreign` (`returned_by`),
  KEY `book_issues_created_by_foreign` (`created_by`),
  KEY `book_issues_updated_by_foreign` (`updated_by`),
  KEY `book_issues_deleted_by_foreign` (`deleted_by`),
  KEY `book_issues_due_date_index` (`due_date`),
  KEY `book_issues_status_index` (`status`),
  KEY `book_issues_issue_type_index` (`issue_type`),
  KEY `book_issues_inhouse_session_token_index` (`inhouse_session_token`),
  KEY `book_issues_renewal_reviewed_by_foreign` (`renewal_reviewed_by`),
  KEY `book_issues_renewal_status_index` (`renewal_status`),
  KEY `book_issues_issue_number_index` (`issue_number`),
  CONSTRAINT `book_issues_book_copy_id_foreign` FOREIGN KEY (`book_copy_id`) REFERENCES `book_copies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_issues_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_issues_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_issues_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_issues_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_issues_library_member_id_foreign` FOREIGN KEY (`library_member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_issues_renewal_reviewed_by_foreign` FOREIGN KEY (`renewal_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_issues_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_issues_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_issues_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_issues`
--

LOCK TABLES `book_issues` WRITE;
/*!40000 ALTER TABLE `book_issues` DISABLE KEYS */;
/*!40000 ALTER TABLE `book_issues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_publishers`
--

DROP TABLE IF EXISTS `book_publishers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_publishers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_publishers_slug_unique` (`slug`),
  KEY `book_publishers_created_by_foreign` (`created_by`),
  KEY `book_publishers_updated_by_foreign` (`updated_by`),
  KEY `book_publishers_deleted_by_foreign` (`deleted_by`),
  KEY `book_publishers_status_index` (`status`),
  KEY `book_publishers_is_active_index` (`is_active`),
  CONSTRAINT `book_publishers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_publishers_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_publishers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_publishers`
--

LOCK TABLES `book_publishers` WRITE;
/*!40000 ALTER TABLE `book_publishers` DISABLE KEYS */;
INSERT INTO `book_publishers` VALUES (1,'McGraw Hill Education (India)','mcgraw-hill-education-india',NULL,'support@mheducation.co.in','+91-11-4500-1200','Noida, Uttar Pradesh, India',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'Oxford University Press','oxford-university-press',NULL,'custserv.in@oup.com','+91-120-4348-000','YMCA Library Building, Jai Singh Road, New Delhi',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'Orient Blackswan','orient-blackswan',NULL,'customercare@orientblackswan.com','+91-40-2766-2849','Himayatnagar, Hyderabad, Telangana',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'Access Publishing','access-publishing',NULL,'editorial@accesspublishing.in','+91-11-2327-4400','Ansari Road, Daryaganj, New Delhi',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `book_publishers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `book_reservations`
--

DROP TABLE IF EXISTS `book_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `book_reservations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reservation_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reservation_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `book_id` bigint unsigned NOT NULL,
  `book_copy_id` bigint unsigned DEFAULT NULL,
  `library_member_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `reserved_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fulfilled_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `book_reservations_reservation_no_unique` (`reservation_no`),
  KEY `book_reservations_book_id_foreign` (`book_id`),
  KEY `book_reservations_book_copy_id_foreign` (`book_copy_id`),
  KEY `book_reservations_library_member_id_foreign` (`library_member_id`),
  KEY `book_reservations_user_id_foreign` (`user_id`),
  KEY `book_reservations_created_by_foreign` (`created_by`),
  KEY `book_reservations_updated_by_foreign` (`updated_by`),
  KEY `book_reservations_deleted_by_foreign` (`deleted_by`),
  KEY `book_reservations_expires_at_index` (`expires_at`),
  KEY `book_reservations_status_index` (`status`),
  KEY `book_reservations_reservation_number_index` (`reservation_number`),
  CONSTRAINT `book_reservations_book_copy_id_foreign` FOREIGN KEY (`book_copy_id`) REFERENCES `book_copies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_reservations_book_id_foreign` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_reservations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_reservations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_reservations_library_member_id_foreign` FOREIGN KEY (`library_member_id`) REFERENCES `library_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_reservations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `book_reservations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `book_reservations`
--

LOCK TABLES `book_reservations` WRITE;
/*!40000 ALTER TABLE `book_reservations` DISABLE KEYS */;
/*!40000 ALTER TABLE `book_reservations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `books`
--

DROP TABLE IF EXISTS `books`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `books` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtitle` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `isbn` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isbn13` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edition` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` bigint unsigned DEFAULT NULL,
  `publisher_id` bigint unsigned DEFAULT NULL,
  `classification_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classification_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'English',
  `pages` int DEFAULT NULL,
  `published_year` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `cover_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `images` json DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_reference_only` tinyint(1) NOT NULL DEFAULT '0',
  `material_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'book',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `total_copies_count` int unsigned NOT NULL DEFAULT '0',
  `available_copies_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `books_slug_unique` (`slug`),
  KEY `books_category_id_foreign` (`category_id`),
  KEY `books_publisher_id_foreign` (`publisher_id`),
  KEY `books_created_by_foreign` (`created_by`),
  KEY `books_updated_by_foreign` (`updated_by`),
  KEY `books_deleted_by_foreign` (`deleted_by`),
  KEY `books_isbn_index` (`isbn`),
  KEY `books_isbn13_index` (`isbn13`),
  KEY `books_classification_number_index` (`classification_number`),
  KEY `books_status_index` (`status`),
  KEY `books_is_active_index` (`is_active`),
  KEY `books_classification_no_index` (`classification_no`),
  KEY `books_material_type_index` (`material_type`),
  CONSTRAINT `books_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `book_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `books_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `books_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `books_publisher_id_foreign` FOREIGN KEY (`publisher_id`) REFERENCES `book_publishers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `books_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `books`
--

LOCK TABLES `books` WRITE;
/*!40000 ALTER TABLE `books` DISABLE KEYS */;
INSERT INTO `books` VALUES (1,'Indian Polity for Civil Services and Other State Examinations','7th Revised & Enlarged Edition','indian-polity-for-civil-services-and-other-state-examinations-qg0f','978-9355325884',NULL,'7th Edition',1,1,'320.954 LAX','320.954 LAX','English',980,2024,'Standard institutional text for Indian Polity & Governance with comprehensive analysis, previous years\' UPSC papers, and curated study modules.',NULL,NULL,850.00,0,'book','active',1,4,4,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'India\'s Struggle for Independence: 1857-1947','The National Movement in Perspective','indias-struggle-for-independence-1857-1947-kXWN','978-0140107814',NULL,'Updated Classic Edition',2,3,'954.03 CHA','954.03 CHA','English',600,2022,'Standard institutional text for Modern & Ancient Indian History with comprehensive analysis, previous years\' UPSC papers, and curated study modules.',NULL,NULL,520.00,0,'book','active',1,3,3,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'Indian Economy','For Civil Services, Universities and Other Examinations','indian-economy-9IoW','978-9355324542',NULL,'15th Edition',3,1,'330.954 SIN','330.954 SIN','English',750,2023,'Standard institutional text for Indian Economy & Development with comprehensive analysis, previous years\' UPSC papers, and curated study modules.',NULL,NULL,780.00,0,'book','active',1,3,3,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'Geography of India','Physical, Human, Economic & Regional Dimensions','geography-of-india-TRad','978-9355321121',NULL,'9th Edition',4,1,'915.4 HUS','915.4 HUS','English',680,2023,'Standard institutional text for Geography & Environment with comprehensive analysis, previous years\' UPSC papers, and curated study modules.',NULL,NULL,690.00,0,'book','active',1,3,3,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'Ethics, Integrity and Aptitude for Civil Services Examination','General Studies Manual Paper IV','ethics-integrity-and-aptitude-for-civil-services-examination-A9FL','978-9389887761',NULL,'6th Edition',5,4,'170.2 SUB','170.2 SUB','English',520,2024,'Standard institutional text for Ethics, Integrity & Aptitude with comprehensive analysis, previous years\' UPSC papers, and curated study modules.',NULL,NULL,590.00,0,'book','active',1,3,3,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `books` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_call_participants`
--

DROP TABLE IF EXISTS `chat_call_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_call_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `call_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `joined_at` timestamp NULL DEFAULT NULL,
  `left_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_call_participants_call_id_user_id_unique` (`call_id`,`user_id`),
  KEY `chat_call_participants_user_id_status_index` (`user_id`,`status`),
  KEY `chat_call_participants_deleted_at_index` (`deleted_at`),
  CONSTRAINT `chat_call_participants_call_id_foreign` FOREIGN KEY (`call_id`) REFERENCES `chat_calls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_call_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_call_participants`
--

LOCK TABLES `chat_call_participants` WRITE;
/*!40000 ALTER TABLE `chat_call_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_call_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_calls`
--

DROP TABLE IF EXISTS `chat_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_calls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversation_id` bigint unsigned DEFAULT NULL,
  `caller_id` bigint unsigned NOT NULL,
  `receiver_id` bigint unsigned DEFAULT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initiated',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_seconds` int unsigned NOT NULL DEFAULT '0',
  `signal_data` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_calls_uuid_unique` (`uuid`),
  KEY `chat_calls_caller_id_created_at_index` (`caller_id`,`created_at`),
  KEY `chat_calls_receiver_id_status_index` (`receiver_id`,`status`),
  KEY `chat_calls_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  CONSTRAINT `chat_calls_caller_id_foreign` FOREIGN KEY (`caller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_calls_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_calls_receiver_id_foreign` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_calls`
--

LOCK TABLES `chat_calls` WRITE;
/*!40000 ALTER TABLE `chat_calls` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_calls` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_conversations`
--

DROP TABLE IF EXISTS `chat_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invite_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_broadcast_only` tinyint(1) NOT NULL DEFAULT '0',
  `team_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `last_message_id` bigint unsigned DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_conversations_uuid_unique` (`uuid`),
  UNIQUE KEY `chat_conversations_invite_code_unique` (`invite_code`),
  KEY `chat_conversations_team_id_foreign` (`team_id`),
  KEY `chat_conversations_created_by_foreign` (`created_by`),
  KEY `chat_conversations_updated_by_foreign` (`updated_by`),
  KEY `chat_conversations_deleted_by_foreign` (`deleted_by`),
  KEY `chat_conversations_type_team_id_index` (`type`,`team_id`),
  KEY `chat_conversations_deleted_at_last_message_at_index` (`deleted_at`,`last_message_at`),
  KEY `chat_conversations_type_index` (`type`),
  KEY `chat_conversations_last_message_at_index` (`last_message_at`),
  CONSTRAINT `chat_conversations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_conversations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_conversations_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_conversations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_conversations`
--

LOCK TABLES `chat_conversations` WRITE;
/*!40000 ALTER TABLE `chat_conversations` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_meeting_participants`
--

DROP TABLE IF EXISTS `chat_meeting_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_meeting_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `meeting_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'participant',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invited',
  `joined_at` timestamp NULL DEFAULT NULL,
  `left_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_meeting_participants_meeting_id_user_id_unique` (`meeting_id`,`user_id`),
  KEY `chat_meeting_participants_user_id_foreign` (`user_id`),
  KEY `chat_meeting_participants_deleted_at_index` (`deleted_at`),
  CONSTRAINT `chat_meeting_participants_meeting_id_foreign` FOREIGN KEY (`meeting_id`) REFERENCES `chat_meetings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_meeting_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_meeting_participants`
--

LOCK TABLES `chat_meeting_participants` WRITE;
/*!40000 ALTER TABLE `chat_meeting_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_meeting_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_meetings`
--

DROP TABLE IF EXISTS `chat_meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_meetings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invite_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `team_id` bigint unsigned DEFAULT NULL,
  `host_id` bigint unsigned NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` enum('instant','scheduled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'instant',
  `mode` enum('video','audio') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video',
  `access_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `repeat_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `repeat_until` timestamp NULL DEFAULT NULL,
  `reminder_offset_minutes` int unsigned DEFAULT '15',
  `reminder_channels` json DEFAULT NULL,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `duration_minutes` int unsigned NOT NULL DEFAULT '60',
  `passcode` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('scheduled','live','ended','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `signal_data` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_meetings_uuid_unique` (`uuid`),
  UNIQUE KEY `chat_meetings_invite_code_unique` (`invite_code`),
  KEY `chat_meetings_team_id_foreign` (`team_id`),
  KEY `chat_meetings_created_by_foreign` (`created_by`),
  KEY `chat_meetings_updated_by_foreign` (`updated_by`),
  KEY `chat_meetings_deleted_by_foreign` (`deleted_by`),
  KEY `chat_meetings_host_id_status_index` (`host_id`,`status`),
  KEY `chat_meetings_scheduled_at_index` (`scheduled_at`),
  CONSTRAINT `chat_meetings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_meetings_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_meetings_host_id_foreign` FOREIGN KEY (`host_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_meetings_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_meetings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_meetings`
--

LOCK TABLES `chat_meetings` WRITE;
/*!40000 ALTER TABLE `chat_meetings` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_meetings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_message_attachments`
--

DROP TABLE IF EXISTS `chat_message_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_message_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `file_extension` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_message_attachments_uuid_unique` (`uuid`),
  KEY `chat_message_attachments_created_by_foreign` (`created_by`),
  KEY `chat_message_attachments_updated_by_foreign` (`updated_by`),
  KEY `chat_message_attachments_deleted_by_foreign` (`deleted_by`),
  KEY `chat_message_attachments_message_id_index` (`message_id`),
  CONSTRAINT `chat_message_attachments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_message_attachments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_message_attachments_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_message_attachments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_message_attachments`
--

LOCK TABLES `chat_message_attachments` WRITE;
/*!40000 ALTER TABLE `chat_message_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_message_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_message_reactions`
--

DROP TABLE IF EXISTS `chat_message_reactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_message_reactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `emoji` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_message_reactions_created_by_foreign` (`created_by`),
  KEY `chat_message_reactions_updated_by_foreign` (`updated_by`),
  KEY `chat_message_reactions_deleted_by_foreign` (`deleted_by`),
  KEY `chat_message_reactions_message_id_emoji_index` (`message_id`,`emoji`),
  KEY `chat_message_reactions_user_id_index` (`user_id`),
  CONSTRAINT `chat_message_reactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_message_reactions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_message_reactions_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_message_reactions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_message_reactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_message_reactions`
--

LOCK TABLES `chat_message_reactions` WRITE;
/*!40000 ALTER TABLE `chat_message_reactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_message_reactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_message_statuses`
--

DROP TABLE IF EXISTS `chat_message_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_message_statuses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `is_delivered` tinyint(1) NOT NULL DEFAULT '0',
  `delivered_at` timestamp NULL DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `is_deleted_for_me` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_message_statuses_message_id_user_id_unique` (`message_id`,`user_id`),
  KEY `chat_message_statuses_user_id_is_read_index` (`user_id`,`is_read`),
  KEY `chat_message_statuses_message_id_is_read_index` (`message_id`,`is_read`),
  CONSTRAINT `chat_message_statuses_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_message_statuses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_message_statuses`
--

LOCK TABLES `chat_message_statuses` WRITE;
/*!40000 ALTER TABLE `chat_message_statuses` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_message_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `reply_to_id` bigint unsigned DEFAULT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `is_edited` tinyint(1) NOT NULL DEFAULT '0',
  `edited_at` timestamp NULL DEFAULT NULL,
  `is_deleted_for_everyone` tinyint(1) NOT NULL DEFAULT '0',
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
  `pinned_at` timestamp NULL DEFAULT NULL,
  `pinned_by` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_messages_uuid_unique` (`uuid`),
  KEY `chat_messages_user_id_foreign` (`user_id`),
  KEY `chat_messages_reply_to_id_foreign` (`reply_to_id`),
  KEY `chat_messages_created_by_foreign` (`created_by`),
  KEY `chat_messages_updated_by_foreign` (`updated_by`),
  KEY `chat_messages_deleted_by_foreign` (`deleted_by`),
  KEY `chat_messages_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  KEY `chat_messages_conversation_id_deleted_at_index` (`conversation_id`,`deleted_at`),
  KEY `chat_messages_type_index` (`type`),
  KEY `chat_messages_pinned_by_foreign` (`pinned_by`),
  KEY `chat_messages_conversation_id_is_pinned_index` (`conversation_id`,`is_pinned`),
  CONSTRAINT `chat_messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_messages_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_pinned_by_foreign` FOREIGN KEY (`pinned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_reply_to_id_foreign` FOREIGN KEY (`reply_to_id`) REFERENCES `chat_messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chat_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_messages`
--

LOCK TABLES `chat_messages` WRITE;
/*!40000 ALTER TABLE `chat_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_participants`
--

DROP TABLE IF EXISTS `chat_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `is_muted` tinyint(1) NOT NULL DEFAULT '0',
  `muted_until` timestamp NULL DEFAULT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `last_read_message_id` bigint unsigned DEFAULT NULL,
  `is_starred` tinyint(1) NOT NULL DEFAULT '0',
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `joined_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_participants_conversation_id_user_id_unique` (`conversation_id`,`user_id`),
  KEY `chat_participants_user_id_is_archived_index` (`user_id`,`is_archived`),
  KEY `chat_participants_conversation_id_role_index` (`conversation_id`,`role`),
  CONSTRAINT `chat_participants_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_participants`
--

LOCK TABLES `chat_participants` WRITE;
/*!40000 ALTER TABLE `chat_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `chat_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_schedule_faculty`
--

DROP TABLE IF EXISTS `class_schedule_faculty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_schedule_faculty` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `class_schedule_id` bigint unsigned NOT NULL,
  `faculty_id` bigint unsigned NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lead_faculty',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_schedule_faculty_class_schedule_id_faculty_id_unique` (`class_schedule_id`,`faculty_id`),
  KEY `class_schedule_faculty_faculty_id_foreign` (`faculty_id`),
  CONSTRAINT `class_schedule_faculty_class_schedule_id_foreign` FOREIGN KEY (`class_schedule_id`) REFERENCES `class_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_schedule_faculty_faculty_id_foreign` FOREIGN KEY (`faculty_id`) REFERENCES `faculties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_schedule_faculty`
--

LOCK TABLES `class_schedule_faculty` WRITE;
/*!40000 ALTER TABLE `class_schedule_faculty` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_schedule_faculty` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_schedules`
--

DROP TABLE IF EXISTS `class_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `section_id` bigint unsigned DEFAULT NULL,
  `course_plan_id` bigint unsigned DEFAULT NULL,
  `class_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `class_date` date NOT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `lecture_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topics` text COLLATE utf8mb4_unicode_ci,
  `description` text COLLATE utf8mb4_unicode_ci,
  `delivery_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `classroom` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classroom_id` bigint unsigned DEFAULT NULL,
  `platform` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meeting_link` text COLLATE utf8mb4_unicode_ci,
  `meeting_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meeting_password` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_other` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_url` text COLLATE utf8mb4_unicode_ci,
  `recording_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_password` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_notes` text COLLATE utf8mb4_unicode_ci,
  `recording_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_available',
  `recurrence_group_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `recurrence_rule` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `cancellation_reason` text COLLATE utf8mb4_unicode_ci,
  `rescheduled_from_id` bigint unsigned DEFAULT NULL,
  `rescheduled_reason` text COLLATE utf8mb4_unicode_ci,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `reminder_count` int unsigned NOT NULL DEFAULT '0',
  `last_reminder_channel` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_schedules_schedule_code_unique` (`schedule_code`),
  KEY `class_schedules_course_id_foreign` (`course_id`),
  KEY `class_schedules_section_id_foreign` (`section_id`),
  KEY `class_schedules_course_plan_id_foreign` (`course_plan_id`),
  KEY `class_schedules_rescheduled_from_id_foreign` (`rescheduled_from_id`),
  KEY `class_schedules_created_by_foreign` (`created_by`),
  KEY `class_schedules_updated_by_foreign` (`updated_by`),
  KEY `class_schedules_deleted_by_foreign` (`deleted_by`),
  KEY `class_schedules_batch_id_course_id_class_date_index` (`batch_id`,`course_id`,`class_date`),
  KEY `class_schedules_class_date_status_index` (`class_date`,`status`),
  KEY `class_schedules_delivery_mode_index` (`delivery_mode`),
  KEY `class_schedules_class_type_index` (`class_type`),
  KEY `class_schedules_recurrence_group_id_index` (`recurrence_group_id`),
  KEY `class_schedules_status_index` (`status`),
  KEY `class_schedules_classroom_id_foreign` (`classroom_id`),
  CONSTRAINT `class_schedules_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_schedules_classroom_id_foreign` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_schedules_course_plan_id_foreign` FOREIGN KEY (`course_plan_id`) REFERENCES `course_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_rescheduled_from_id_foreign` FOREIGN KEY (`rescheduled_from_id`) REFERENCES `class_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_schedules_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_schedules`
--

LOCK TABLES `class_schedules` WRITE;
/*!40000 ALTER TABLE `class_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classrooms`
--

DROP TABLE IF EXISTS `classrooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classrooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `building` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `floor` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity` int unsigned DEFAULT NULL,
  `room_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lecture_hall',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `facilities` json DEFAULT NULL,
  `assigned_courses` json DEFAULT NULL,
  `assigned_sections` json DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classrooms_room_code_unique` (`room_code`),
  KEY `classrooms_created_by_foreign` (`created_by`),
  KEY `classrooms_updated_by_foreign` (`updated_by`),
  KEY `classrooms_deleted_by_foreign` (`deleted_by`),
  KEY `classrooms_building_floor_status_index` (`building`,`floor`,`status`),
  KEY `classrooms_status_index` (`status`),
  CONSTRAINT `classrooms_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classrooms_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classrooms_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classrooms`
--

LOCK TABLES `classrooms` WRITE;
/*!40000 ALTER TABLE `classrooms` DISABLE KEYS */;
/*!40000 ALTER TABLE `classrooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `communication_campaigns`
--

DROP TABLE IF EXISTS `communication_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `recipient_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all_users',
  `recipient_role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_ids` json DEFAULT NULL,
  `template_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_recipients` int unsigned NOT NULL DEFAULT '0',
  `sent_count` int unsigned NOT NULL DEFAULT '0',
  `failed_count` int unsigned NOT NULL DEFAULT '0',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `communication_campaigns_created_by_foreign` (`created_by`),
  KEY `communication_campaigns_updated_by_foreign` (`updated_by`),
  KEY `communication_campaigns_created_at_index` (`created_at`),
  KEY `communication_campaigns_channel_index` (`channel`),
  KEY `communication_campaigns_recipient_type_index` (`recipient_type`),
  KEY `communication_campaigns_template_code_index` (`template_code`),
  KEY `communication_campaigns_status_index` (`status`),
  CONSTRAINT `communication_campaigns_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `communication_campaigns_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `communication_campaigns`
--

LOCK TABLES `communication_campaigns` WRITE;
/*!40000 ALTER TABLE `communication_campaigns` DISABLE KEYS */;
/*!40000 ALTER TABLE `communication_campaigns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `communication_logs`
--

DROP TABLE IF EXISTS `communication_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'custom_individual',
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `template_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_by` bigint unsigned DEFAULT NULL,
  `campaign_id` bigint unsigned DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `resend_count` int unsigned NOT NULL DEFAULT '0',
  `last_resent_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `communication_logs_user_id_foreign` (`user_id`),
  KEY `communication_logs_sent_by_foreign` (`sent_by`),
  KEY `communication_logs_channel_status_index` (`channel`,`status`),
  KEY `communication_logs_created_at_index` (`created_at`),
  KEY `communication_logs_channel_index` (`channel`),
  KEY `communication_logs_type_index` (`type`),
  KEY `communication_logs_recipient_index` (`recipient`),
  KEY `communication_logs_template_code_index` (`template_code`),
  KEY `communication_logs_status_index` (`status`),
  KEY `communication_logs_campaign_id_index` (`campaign_id`),
  KEY `communication_logs_sent_at_index` (`sent_at`),
  CONSTRAINT `communication_logs_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `communication_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `communication_logs`
--

LOCK TABLES `communication_logs` WRITE;
/*!40000 ALTER TABLE `communication_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `communication_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_departments`
--

DROP TABLE IF EXISTS `contact_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_departments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_departments_code_unique` (`code`),
  KEY `contact_departments_created_by_foreign` (`created_by`),
  KEY `contact_departments_updated_by_foreign` (`updated_by`),
  KEY `contact_departments_deleted_by_foreign` (`deleted_by`),
  KEY `contact_departments_sort_order_index` (`sort_order`),
  KEY `contact_departments_is_active_index` (`is_active`),
  CONSTRAINT `contact_departments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_departments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_departments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_departments`
--

LOCK TABLES `contact_departments` WRITE;
/*!40000 ALTER TABLE `contact_departments` DISABLE KEYS */;
INSERT INTO `contact_departments` VALUES (1,'Admissions & Counseling','ADMISSIONS','admissions@sntcssc.in','Inquiries regarding UPSC CSE batches, eligibility criteria, screening tests, and admission selection lists.',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,'Academic & Faculty Guidance','ACADEMIC','academics@sntcssc.in','Lecture schedules, faculty mentoring, optional subject selection, and study material distribution.',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,'Examination & Test Series','EXAMS','exams@sntcssc.in','Prelims mock tests, Mains descriptive answer booklet evaluation, rank lists, and test scorecards.',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,'Fee & Accounts Department','ACCOUNTS','accounts@sntcssc.in','Fee payment receipts, installment schedules, online gateway issues, and refund requests.',4,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,'Technical Support & MIS Helpdesk','TECH_SUPPORT','support@sntcssc.in','Student portal login, OTP verification, password reset, mobile app, and digital dashboard assistance.',5,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(6,'General Administration & Public Relations','GENERAL_ADMIN','info@sntcssc.in','Institutional visits, official correspondence, media queries, and general public inquiries.',6,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL);
/*!40000 ALTER TABLE `contact_departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_subjects`
--

DROP TABLE IF EXISTS `contact_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_subjects_department_id_foreign` (`department_id`),
  KEY `contact_subjects_created_by_foreign` (`created_by`),
  KEY `contact_subjects_updated_by_foreign` (`updated_by`),
  KEY `contact_subjects_deleted_by_foreign` (`deleted_by`),
  KEY `contact_subjects_sort_order_index` (`sort_order`),
  KEY `contact_subjects_is_active_index` (`is_active`),
  CONSTRAINT `contact_subjects_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_subjects_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_subjects_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `contact_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_subjects_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_subjects`
--

LOCK TABLES `contact_subjects` WRITE;
/*!40000 ALTER TABLE `contact_subjects` DISABLE KEYS */;
INSERT INTO `contact_subjects` VALUES (1,1,'UPSC CSE Foundation Batch Admission','UPSC_FOUNDATION',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,1,'WBCS Integrated Course Enrollment','WBCS_ENROLL',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,1,'Scholarship & Fee Concession Eligibility','SCHOLARSHIP',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,1,'Hostel & Library Facility Admission','HOSTEL_LIB',4,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,2,'Classroom Schedule & Timetable Query','TIMETABLE',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(6,2,'Faculty Mentorship & Answer Evaluation','MENTORSHIP',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(7,2,'Optional Subject Strategy Session','OPTIONAL_SUBJECT',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(8,2,'Study Material & Book Bank Request','STUDY_MATERIAL',4,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(9,3,'Prelims All-India Mock Test Series','PRELIMS_MOCK',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(10,3,'Mains Answer Booklet Evaluation Status','MAINS_EVAL',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(11,3,'Simulated Personality Test / Mock Interview','MOCK_INTERVIEW',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(12,4,'Online Fee Payment / Transaction Issue','PAYMENT_ISSUE',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(13,4,'Fee Receipt & Installment Acknowledgement','FEE_RECEIPT',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(14,4,'Course Withdrawal & Refund Request','REFUND_REQUEST',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(15,5,'Student Portal Login & OTP Issue','LOGIN_OTP',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(16,5,'Biometric Passkey / 2FA Reset','PASSKEY_2FA',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(17,5,'Profile Data Correction Request','PROFILE_CORRECTION',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(18,5,'General Technical Assistance','GENERAL_TECH',4,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(19,6,'Campus Visit & Seminar Booking','CAMPUS_VISIT',1,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(20,6,'Official Collaboration & RTI Query','COLLAB_RTI',2,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(21,6,'Other General Inquiry','OTHER_INQUIRY',3,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL);
/*!40000 ALTER TABLE `contact_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_submissions`
--

DROP TABLE IF EXISTS `contact_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mobile` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `whatsapp` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `custom_subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `replied_at` timestamp NULL DEFAULT NULL,
  `replied_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_submissions_reference_no_unique` (`reference_no`),
  KEY `contact_submissions_department_id_foreign` (`department_id`),
  KEY `contact_submissions_subject_id_foreign` (`subject_id`),
  KEY `contact_submissions_replied_by_foreign` (`replied_by`),
  KEY `contact_submissions_deleted_by_foreign` (`deleted_by`),
  KEY `contact_submissions_status_index` (`status`),
  KEY `contact_submissions_priority_index` (`priority`),
  CONSTRAINT `contact_submissions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_submissions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `contact_departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_submissions_replied_by_foreign` FOREIGN KEY (`replied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_submissions_subject_id_foreign` FOREIGN KEY (`subject_id`) REFERENCES `contact_subjects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_submissions`
--

LOCK TABLES `contact_submissions` WRITE;
/*!40000 ALTER TABLE `contact_submissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_plans`
--

DROP TABLE IF EXISTS `course_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plan_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `section_id` bigint unsigned DEFAULT NULL,
  `plan_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'annual',
  `academic_year` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_lectures_planned` int unsigned NOT NULL DEFAULT '0',
  `topics_covered` text COLLATE utf8mb4_unicode_ci,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `document_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_plans_plan_code_unique` (`plan_code`),
  KEY `course_plans_course_id_foreign` (`course_id`),
  KEY `course_plans_section_id_foreign` (`section_id`),
  KEY `course_plans_created_by_foreign` (`created_by`),
  KEY `course_plans_updated_by_foreign` (`updated_by`),
  KEY `course_plans_deleted_by_foreign` (`deleted_by`),
  KEY `course_plans_batch_id_course_id_status_index` (`batch_id`,`course_id`,`status`),
  KEY `course_plans_status_index` (`status`),
  CONSTRAINT `course_plans_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_plans_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_plans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_plans_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_plans_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_plans_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_plans`
--

LOCK TABLES `course_plans` WRITE;
/*!40000 ALTER TABLE `course_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `course_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `course_withdrawal_requests`
--

DROP TABLE IF EXISTS `course_withdrawal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `course_withdrawal_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enrollment_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'withdrawal',
  `reason` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_details` text COLLATE utf8mb4_unicode_ci,
  `supporting_document` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `effective_date` date DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_withdrawal_requests_request_no_unique` (`request_no`),
  KEY `course_withdrawal_requests_batch_id_foreign` (`batch_id`),
  KEY `course_withdrawal_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `course_withdrawal_requests_created_by_foreign` (`created_by`),
  KEY `course_withdrawal_requests_updated_by_foreign` (`updated_by`),
  KEY `course_withdrawal_requests_deleted_by_foreign` (`deleted_by`),
  KEY `course_withdrawal_requests_user_id_status_index` (`user_id`,`status`),
  KEY `course_withdrawal_requests_enrollment_id_status_index` (`enrollment_id`,`status`),
  KEY `course_withdrawal_requests_course_id_status_index` (`course_id`,`status`),
  KEY `course_withdrawal_requests_status_index` (`status`),
  CONSTRAINT `course_withdrawal_requests_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_withdrawal_requests_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_withdrawal_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_withdrawal_requests_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_withdrawal_requests_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `course_withdrawal_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_withdrawal_requests_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `course_withdrawal_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `course_withdrawal_requests`
--

LOCK TABLES `course_withdrawal_requests` WRITE;
/*!40000 ALTER TABLE `course_withdrawal_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `course_withdrawal_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Classroom',
  `description` text COLLATE utf8mb4_unicode_ci,
  `duration` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fee_reserved` decimal(10,2) DEFAULT NULL,
  `fee_structure` json DEFAULT NULL,
  `capacity` int unsigned DEFAULT NULL,
  `has_sections` tinyint(1) NOT NULL DEFAULT '0',
  `identifier_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admit_once_only` tinyint(1) NOT NULL DEFAULT '0',
  `max_attempts` int unsigned DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `rules` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `courses_code_unique` (`code`),
  KEY `courses_created_by_foreign` (`created_by`),
  KEY `courses_updated_by_foreign` (`updated_by`),
  KEY `courses_deleted_by_foreign` (`deleted_by`),
  KEY `courses_category_index` (`category`),
  KEY `courses_status_index` (`status`),
  CONSTRAINT `courses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `courses_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `courses_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,'Composite Course (CC)','CC','Classroom','Flagship 10-month classroom coaching programme covering UPSC CSE Prelims, Mains, and general mentorship with optional subject guidance.','10 Months','monthly',1000.00,500.00,NULL,200,1,'1',1,NULL,'active',NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(2,'Prelims Crash Course (PCC)','PCC','Crash Course','Intensive 3-month (12-week) high-yield revision and test series module dedicated to UPSC Prelims General Studies (Paper 1) and CSAT (Paper 2).','3 Months','one_time',1500.00,750.00,NULL,NULL,0,'3',0,NULL,'active',NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(3,'Mains Guidance Programme (MGP)','MGP','Guidance','Systematic answer writing evaluation, GS Papers 1 to 4 coverage, and essay writing mentorship for UPSC Mains.','16 Weeks','one_time',12000.00,6000.00,NULL,NULL,0,'4',0,NULL,'active',NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(4,'Interview Training and Mock Interview Programme (ITP)','ITP','Interview','Comprehensive personality test grooming, DAF in-depth scrutiny, and mock interview panels with retired diplomats and civil servants.','4 Weeks','one_time',10000.00,5000.00,NULL,NULL,0,'5',0,NULL,'active',NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(5,'Weekend Test Series (WTS)','WTS','Test Series','Rigorous weekend simulated test series with all-India ranking and video discussion analysis.','24 Weeks','one_time',3000.00,1500.00,NULL,NULL,0,'6',0,NULL,'active',NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(6,'Free UPSC CSE Foundation Seminar (FOS)','FOS','Guidance','Free introductory orientation seminar and workshop for all UPSC civil services aspirants across West Bengal.','2 Weeks','free',0.00,0.00,NULL,NULL,0,'7',0,NULL,'active',NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL);
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cron_jobs`
--

DROP TABLE IF EXISTS `cron_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `command` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `arguments` json DEFAULT NULL,
  `expression` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '* * * * *',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `run_in_background` tinyint(1) NOT NULL DEFAULT '1',
  `without_overlapping` tinyint(1) NOT NULL DEFAULT '1',
  `last_run_at` timestamp NULL DEFAULT NULL,
  `last_run_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_run_duration` decimal(8,3) DEFAULT NULL,
  `last_run_output` longtext COLLATE utf8mb4_unicode_ci,
  `next_run_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cron_jobs_created_by_foreign` (`created_by`),
  KEY `cron_jobs_updated_by_foreign` (`updated_by`),
  KEY `cron_jobs_is_active_deleted_at_index` (`is_active`,`deleted_at`),
  KEY `cron_jobs_last_run_status_index` (`last_run_status`),
  CONSTRAINT `cron_jobs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cron_jobs_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cron_jobs`
--

LOCK TABLES `cron_jobs` WRITE;
/*!40000 ALTER TABLE `cron_jobs` DISABLE KEYS */;
INSERT INTO `cron_jobs` VALUES (1,'Prune Expired Team Invitations','teams:prune-invitations',NULL,'0 0 * * *','Deletes expired team member invitations past their expiry date to keep records clean.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-02 18:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(2,'Clear Expired Password Reset Tokens','auth:clear-resets',NULL,'0 */6 * * *','Flushes expired password reset tokens from the password_reset_tokens table.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-02 00:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(3,'Prune Failed Queue Jobs','queue:prune-failed','{\"--hours\": 168}','0 2 * * 0','Prunes failed job records older than 7 days from the failed_jobs database table.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-07 20:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(4,'Database Model Pruning','model:prune',NULL,'0 3 * * *','Executes soft-deleted and prunable model pruning routines across application models.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-01 21:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(5,'Automated Database & Files Backup','app:backup:run',NULL,'0 2 * * *','Executes automated scheduled database and media files backup archive routine.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-01 20:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(6,'Backup Retention Cleanup','app:backup:clean',NULL,'30 3 * * *','Prunes historical backup archives exceeding retention count and age constraints.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-01 22:00:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(7,'Weekly Backup Health Report','app:backup:report',NULL,'0 8 * * 0','Sends periodic database backup health, status, and storage utilization report to admin.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-08 02:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(8,'Automated Library Loan Return Reminders','library:send-reminders',NULL,'0 9 * * *','Scans due soon and overdue library book loans and sends multi-channel reminders to borrowers.',1,1,1,NULL,NULL,NULL,NULL,'2026-11-02 03:30:00',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(9,'Support Tickets SLA Monitor','app:tickets:check-sla',NULL,'*/15 * * * *','Monitors open support tickets and triggers warnings for response and resolution SLA breaches.',1,1,1,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07');
/*!40000 ALTER TABLE `cron_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_categories`
--

DROP TABLE IF EXISTS `document_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'folder',
  `color` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'blue',
  `display_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_categories_slug_unique` (`slug`),
  KEY `document_categories_created_by_foreign` (`created_by`),
  KEY `document_categories_updated_by_foreign` (`updated_by`),
  KEY `document_categories_deleted_by_foreign` (`deleted_by`),
  KEY `doc_cat_active_order_idx` (`is_active`,`display_order`),
  CONSTRAINT `document_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_categories_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_categories`
--

LOCK TABLES `document_categories` WRITE;
/*!40000 ALTER TABLE `document_categories` DISABLE KEYS */;
INSERT INTO `document_categories` VALUES (1,'Academic Circulars & Syllabi','academic-circulars-syllabi','Official curriculum guidelines, course syllabi, foundational reading plans, and batch notices.','graduation-cap','blue',1,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'Administrative Orders & Gazette','administrative-orders-gazette','Government gazettes, executive orders, faculty postings, and governing board resolutions.','landmark','indigo',2,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'Examinations & Evaluation Guidelines','examinations-evaluation-guidelines','Test series rubrics, UPSC prelims/mains evaluative criteria, and mock marksheet templates.','clipboard-check','red',3,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'Policy Manuals & Regulations','policy-manuals-regulations','Hostel conduct regulations, library borrowing bylaws, and IT infrastructure security policies.','shield','emerald',4,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'MoUs & Institutional Partnerships','mous-institutional-partnerships','Bilateral agreements, coaching partner contracts, and university academic linkages.','building-2','purple',5,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(6,'Financial Reports & Tenders','financial-reports-tenders','Audited financial balance sheets, procurement tender notices, and treasury allocations.','banknote','amber',6,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(7,'Presentations & Institutional Media','presentations-institutional-media','Annual orientation slide decks, high-resolution logos, and seminar visual guides.','image','pink',7,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(8,'Archives & Compressed Packages','archives-compressed-packages','Multi-document bundles, past year question archives, and consolidated asset packages.','file-archive','orange',8,1,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `document_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_versions`
--

DROP TABLE IF EXISTS `document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `version_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision_sequence` int unsigned NOT NULL DEFAULT '1',
  `version_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `file_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `change_summary` text COLLATE utf8mb4_unicode_ci,
  `change_description` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `download_count` int NOT NULL DEFAULT '0',
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_versions_uploaded_by_foreign` (`uploaded_by`),
  KEY `document_versions_created_by_foreign` (`created_by`),
  KEY `document_versions_updated_by_foreign` (`updated_by`),
  KEY `document_versions_deleted_by_foreign` (`deleted_by`),
  KEY `doc_ver_doc_cur_idx` (`document_id`,`is_current`),
  KEY `doc_ver_doc_rev_idx` (`document_id`,`revision_sequence`),
  KEY `doc_ver_file_type_idx` (`file_type`),
  CONSTRAINT `document_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_versions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_versions_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_versions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_versions_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_versions`
--

LOCK TABLES `document_versions` WRITE;
/*!40000 ALTER TABLE `document_versions` DISABLE KEYS */;
INSERT INTO `document_versions` VALUES (1,1,'1.0',1,'Initial Approved Syllabus','documents/doc-2026-0001/v1.0_syllabus_initial.pdf','SNTCSSC_UPSC_Syllabus_2026_v1.0.pdf','pdf','application/pdf',2176,'5030160a15bda305a9080b6afe6f97d5bb7183e3800d50a426148429b8e3d5fd','Initial publication of annual civil services curriculum.',NULL,NULL,0,45,2,NULL,NULL,NULL,'2026-04-01','2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,1,'1.1',2,'Interim Science & Environment Module Update','documents/doc-2026-0001/v1.1_syllabus_interim.pdf','SNTCSSC_UPSC_Syllabus_2026_v1.1_ScienceUpdate.pdf','pdf','application/pdf',2194,'84bf435ca832b5d96aa9e48f66cf173a9b2cb8cf566870a829b66a3472c7ee80','Added contemporary Science & Tech references and biodiversity conventions modules.',NULL,NULL,0,38,2,NULL,NULL,NULL,'2026-06-25','2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,1,'2.0',3,'Final Gazette Edition with CSAT Strategy & Ethics Case Matrix','documents/doc-2026-0001/v2.0_syllabus_final.pdf','SNTCSSC_UPSC_Syllabus_2026_v2.0_Final.pdf','pdf','application/pdf',2258,'4dac1fa5f3e46942496b7eb8911e8865f9f35eb72be4aa61e8a8d92939cf2302','Complete overhaul of Ethics Paper IV case study frameworks and CSAT quantitative reasoning guidelines.',NULL,NULL,1,59,2,NULL,NULL,NULL,'2026-10-15','2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,2,'1.0',1,'Initial Hostel Regulations','documents/doc-2026-0002/v1.0_hostel_code.docx','Hostel_Rules_and_Discipline_Manual_2026_v1.0.docx','docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',96,'ecc4455a1212f897d25e6acf69e1674476e3b15f92c6b778d24ce6ece0721f96','Initial hostel regulations release.',NULL,NULL,0,28,2,NULL,NULL,NULL,'2026-04-01','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(5,2,'1.1',2,'Revised Biometric Curfew Timings & Extension Request Policy','documents/doc-2026-0002/v1.1_hostel_code_revised.docx','Hostel_Rules_and_Discipline_Manual_2026_v1.1_Revised.docx','docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document',125,'c5af55a6c51b90e1f269b25cc4c90c7f23385973fd0d40c9ca0f03adce2c94c7','Updated late entry curfew thresholds and emergency contact protocol.',NULL,NULL,1,60,2,NULL,NULL,NULL,'2026-08-10','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(6,3,'1.0',1,'Approved Annual Budget Plan','documents/doc-2026-0003/v1.0_budget_initial.xlsx','SNTCSSC_Budget_Plan_2026_27_Approved.xlsx','xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',50,'f85ea0505050a108cc4c21114ed69f0a692adcfd03091aea557fa383a3367605','Initial approved budget spreadsheet.',NULL,NULL,0,12,2,NULL,NULL,NULL,'2026-04-01','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(7,3,'2.0',2,'Q2 Supplementary Re-appropriation & Actuals','documents/doc-2026-0003/v2.0_budget_q2_adjusted.xlsx','SNTCSSC_Budget_Tracker_2026_27_Q2_Revised.xlsx','xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',76,'89ee66a46df842d7bf9843ceed693b9fcf5ca117cbefdf3a3295535d07b6a074','Mid-term re-allocation of Mock Interview honoraria and digital library funds.',NULL,NULL,1,19,2,NULL,NULL,NULL,'2026-10-01','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(8,4,'1.0',1,'Annual Induction Slide Deck','documents/doc-2026-0004/v1.0_orientation_slides.pptx','SNTCSSC_Induction_Strategy_Deck_2026_Final.pptx','pptx','application/vnd.openxmlformats-officedocument.presentationml.presentation',43,'97f92275877410b405aa0b10d6d0ed8d5abec297310af049ec1156453689e79a','Initial release of foundation induction master slides.',NULL,NULL,1,64,2,NULL,NULL,NULL,'2026-04-10','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(9,5,'1.0',1,'PYQ Archive 2015-2024','documents/doc-2026-0005/v1.0_prelims_pyq.zip','UPSC_Prelims_PYQ_Archive_2015_2024.zip','zip','application/zip',35,'cdab4903d9a670f03f76df9de8354382ce316e9cea3424dedd64e489479ac8c3','Initial package containing papers up to 2024.',NULL,NULL,0,76,2,NULL,NULL,NULL,'2025-05-15','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(10,5,'2.0',2,'Updated Archive Including 2025 Official Answer Key','documents/doc-2026-0005/v2.0_prelims_pyq_2025_added.zip','UPSC_Prelims_PYQ_Archive_2016_2025_Certified.zip','zip','application/zip',61,'3fabfd39d9341891feab8fa308a9354828d351f7d939d4d26fe6c55728722a21','Appended 2025 official question paper with UPSC ratified answer keys and cut-off analysis.',NULL,NULL,1,121,2,NULL,NULL,NULL,'2026-07-01','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(11,6,'1.0',1,'Certified Institutional Crest & Emblem','documents/doc-2026-0006/v1.0_emblem.png','SNTCSSC_Official_Emblem_HighRes_Transparent.png','png','image/png',14,'494dffcac4c70b128c31fb479eb36c56a118fc2b2ac860cb11a8bea9001aae6b','Initial release of high-resolution graphic branding assets.',NULL,NULL,1,43,2,NULL,NULL,NULL,'2026-01-01','2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `document_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_category_id` bigint unsigned DEFAULT NULL,
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `access_level` enum('public','internal','confidential','restricted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `description` text COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `current_version_id` bigint unsigned DEFAULT NULL,
  `total_versions_count` int NOT NULL DEFAULT '1',
  `total_downloads_count` int NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `documents_document_number_unique` (`document_number`),
  KEY `documents_created_by_foreign` (`created_by`),
  KEY `documents_updated_by_foreign` (`updated_by`),
  KEY `documents_deleted_by_foreign` (`deleted_by`),
  KEY `doc_number_idx` (`document_number`),
  KEY `doc_cat_status_idx` (`document_category_id`,`status`),
  KEY `doc_dept_access_idx` (`department`,`access_level`),
  KEY `doc_created_at_idx` (`created_at`),
  KEY `doc_cur_ver_fk` (`current_version_id`),
  CONSTRAINT `doc_cur_ver_fk` FOREIGN KEY (`current_version_id`) REFERENCES `document_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_document_category_id_foreign` FOREIGN KEY (`document_category_id`) REFERENCES `document_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documents`
--

LOCK TABLES `documents` WRITE;
/*!40000 ALTER TABLE `documents` DISABLE KEYS */;
INSERT INTO `documents` VALUES (1,'DOC-2026-0001','UPSC Civil Services Examination Comprehensive Syllabus & Scheme (2026-2027)','Curriculum Blueprint & Reading Roadmap',1,'Academics','[\"upsc\", \"syllabus\", \"prelims\", \"mains\", \"curriculum\", \"2026\"]','public','published','Complete institutional breakdown of General Studies Papers I-IV, CSAT Strategy, Essay Paper framework, and Optional Subjects syllabus approved by the Academic Council.','Mandatory distribution during initial student orientation sessions.','Ratified by Academic Advisory Council on 12 April 2026.',3,3,142,2,2,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'DOC-2026-0002','Institutional Hostel Code of Conduct & Resident Bylaws','Administrative Regulation & Resident Code',4,'Administration','[\"hostel\", \"conduct\", \"regulations\", \"discipline\", \"curfew\"]','internal','published','Statutory regulations governing residential life, attendance check-in biometric procedures, mess timings, visitor norms, and anti-ragging undertakings.','Requires student and guardian physical signatures at check-in.','Enforced across all boys and girls hostel wings.',5,2,88,2,2,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(3,'DOC-2026-0003','Institutional Operational Budget Allocation & Expenditure Tracker (2026-2027)','Financial Planning & Audit Tracking',6,'Finance & Accounts','[\"finance\", \"budget\", \"excel\", \"ledger\", \"accounts\", \"2026\"]','confidential','published','Itemized budgetary heads across academic honoraria, library acquisitions, mock interview panels, hostel maintenance, and scholarship disbursements.','Strict access restricted to Accounts Officer and Principal Secretary.','Submitted to Higher Education Department, Govt. of West Bengal.',7,2,31,2,2,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(4,'DOC-2026-0004','Foundation Course Induction & Civil Services Strategy Presentation (2026)','Induction Seminar Visual Guide',7,'Academics','[\"presentation\", \"induction\", \"orientation\", \"slides\", \"strategy\"]','internal','published','Executive 60-slide induction deck covering curriculum overview, daily study routines, answer writing pedagogy, and previous year topper case studies.','Used by senior faculty during inaugural student bootcamps.','Updated with 2025 UPSC CSE final results statistics.',8,1,64,2,2,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(5,'DOC-2026-0005','UPSC Prelims 10-Year Question Papers & Explanatory Solutions Archive (2016-2025)','Study Repository & Archival Package',8,'Examinations','[\"archive\", \"zip\", \"prelims\", \"pyq\", \"solutions\", \"past-papers\"]','public','published','Comprehensive compressed zip archive packaging past 10 years Prelims GS Paper 1 and CSAT papers with detailed sectional topic-wise analysis.','High-demand package for enrolled batches.','Verified by Department of Examination Research.',10,2,197,2,2,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(6,'DOC-2026-0006','Official Institutional Seal, Crest & Emblem Vector Graphic Assets','Brand & Visual Identity Standards',7,'IT & Media','[\"crest\", \"logo\", \"image\", \"png\", \"branding\", \"graphics\"]','public','published','Certified high-resolution transparent PNG emblem and vector assets for stationery, test question papers, certificates, and publications.','Must not be altered in hue or proportions.','Approved by Director General.',11,1,43,2,2,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_templates`
--

DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'notification',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables` json DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_templates_code_unique` (`code`),
  KEY `email_templates_created_by_foreign` (`created_by`),
  KEY `email_templates_updated_by_foreign` (`updated_by`),
  KEY `email_templates_deleted_by_foreign` (`deleted_by`),
  KEY `email_templates_category_status_index` (`category`,`status`),
  CONSTRAINT `email_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_templates_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `email_templates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_templates`
--

LOCK TABLES `email_templates` WRITE;
/*!40000 ALTER TABLE `email_templates` DISABLE KEYS */;
INSERT INTO `email_templates` VALUES (1,'otp_registration','Account Registration Verification OTP','otp','{otp} is your {app_name} Verification Code','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <div style=\"text-align: center; margin-bottom: 24px;\">\n        <h2 style=\"color: #111827; margin-bottom: 4px;\">{app_name}</h2>\n        <p style=\"color: #6b7280; font-size: 14px; margin-top: 0;\">Email Verification</p>\n    </div>\n    <p style=\"color: #374151; font-size: 15px;\">Hello <strong>{name}</strong>,</p>\n    <p style=\"color: #374151; font-size: 15px;\">Thank you for registering. Use the following One-Time Password (OTP) to verify your account:</p>\n    <div style=\"text-align: center; margin: 28px 0;\">\n        <span style=\"display: inline-block; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #059669; background: #ecfdf5; padding: 12px 28px; border-radius: 8px; border: 1px dashed #059669;\">{otp}</span>\n    </div>\n    <p style=\"color: #6b7280; font-size: 13px;\">This verification code will expire in <strong>{expiry} minutes</strong>. If you did not create an account, please disregard this email.</p>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. All rights reserved.</p>\n</div>','[\"name\", \"otp\", \"expiry\", \"app_name\", \"email\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,'otp_login','Login Authentication Code','otp','{otp} is your {app_name} Login Code','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <div style=\"text-align: center; margin-bottom: 24px;\">\n        <h2 style=\"color: #111827; margin-bottom: 4px;\">{app_name}</h2>\n        <p style=\"color: #6b7280; font-size: 14px; margin-top: 0;\">Secure Sign In</p>\n    </div>\n    <p style=\"color: #374151; font-size: 15px;\">Hello <strong>{name}</strong>,</p>\n    <p style=\"color: #374151; font-size: 15px;\">You requested a one-time login code to sign into your account.</p>\n    <div style=\"text-align: center; margin: 28px 0;\">\n        <span style=\"display: inline-block; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #2563eb; background: #eff6ff; padding: 12px 28px; border-radius: 8px; border: 1px dashed #2563eb;\">{otp}</span>\n    </div>\n    <p style=\"color: #6b7280; font-size: 13px;\">This code is valid for <strong>{expiry} minutes</strong>. If you did not initiate this sign in attempt, please review your account security immediately.</p>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. All rights reserved.</p>\n</div>','[\"name\", \"otp\", \"expiry\", \"app_name\", \"email\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,'otp_password_reset','Password Reset Security OTP','otp','Password Reset OTP for {app_name}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <div style=\"text-align: center; margin-bottom: 24px;\">\n        <h2 style=\"color: #111827; margin-bottom: 4px;\">{app_name}</h2>\n        <p style=\"color: #6b7280; font-size: 14px; margin-top: 0;\">Password Reset Request</p>\n    </div>\n    <p style=\"color: #374151; font-size: 15px;\">Hello <strong>{name}</strong>,</p>\n    <p style=\"color: #374151; font-size: 15px;\">We received a request to reset your password. Use the following code to proceed:</p>\n    <div style=\"text-align: center; margin: 28px 0;\">\n        <span style=\"display: inline-block; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #dc2626; background: #fef2f2; padding: 12px 28px; border-radius: 8px; border: 1px dashed #dc2626;\">{otp}</span>\n    </div>\n    <p style=\"color: #6b7280; font-size: 13px;\">This code will expire in <strong>{expiry} minutes</strong>. If you did not request a password reset, you can safely ignore this email.</p>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. All rights reserved.</p>\n</div>','[\"name\", \"otp\", \"expiry\", \"app_name\", \"email\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,'admission_confirmation','Admission Confirmation Notice','notification','Application Received: {application_no} - {course}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827;\">Application Acknowledgment</h2>\n    <p style=\"color: #374151; font-size: 15px;\">Dear <strong>{name}</strong>,</p>\n    <p style=\"color: #374151; font-size: 15px;\">Your application for <strong>{course}</strong> has been received with Application Reference <strong>#{application_no}</strong>.</p>\n    <p style=\"color: #374151; font-size: 15px;\">Our admissions desk will verify your submission. You can track your status anytime on the student dashboard.</p>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. All rights reserved.</p>\n</div>','[\"name\", \"course\", \"application_no\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,'general_notice','Official Notice & Communication','notice','Notice: {notice_title}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827;\">{notice_title}</h2>\n    <p style=\"color: #374151; font-size: 15px;\">Dear <strong>{name}</strong>,</p>\n    <div style=\"color: #374151; font-size: 14px; line-height: 1.6; background: #f9fafb; padding: 16px; border-radius: 8px; margin: 16px 0;\">\n        {notice_content}\n    </div>\n    <p style=\"color: #6b7280; font-size: 13px;\">Date: {date}</p>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. All rights reserved.</p>\n</div>','[\"name\", \"notice_title\", \"notice_content\", \"date\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(6,'backup_completed','Database Backup Generated Notice','system','[{app_name}] Database Backup Generated ({size})','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">System Backup Successful</h2>\n    <p style=\"color: #374151; font-size: 14px;\">A new system backup archive <strong>{filename}</strong> has been generated.</p>\n    <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;\">\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">File Size:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{size}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Backup Type:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{type}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Tables Dumped:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{tables_count}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Total Records:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{records_count}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">ZIP Attached:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{attached}</td></tr>\n        <tr><td style=\"padding: 8px 0; color: #6b7280;\">Generated At:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{date}</td></tr>\n    </table>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{download_url}\" style=\"background: #0284c7; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">Open Backup Dashboard</a>\n    </div>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. Automated System Notification.</p>\n</div>','[\"app_name\", \"filename\", \"size\", \"type\", \"tables_count\", \"records_count\", \"attached\", \"date\", \"download_url\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(7,'backup_failed','Database Backup Failure Alert','system','[URGENT] [{app_name}] Database Backup Failed','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #fee2e2; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #b91c1c; margin-bottom: 6px;\">Database Backup Failed</h2>\n    <p style=\"color: #374151; font-size: 14px;\">An automated or manual database backup attempt encountered an unexpected error.</p>\n    <div style=\"background: #fef2f2; border: 1px solid #fca5a5; padding: 14px; border-radius: 8px; margin: 16px 0; color: #991b1b; font-family: monospace; font-size: 12px;\">\n        {error_message}\n    </div>\n    <p style=\"color: #6b7280; font-size: 13px;\">Trigger: <strong>{trigger_type}</strong> | Timestamp: <strong>{date}</strong></p>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. Automated System Alert.</p>\n</div>','[\"app_name\", \"error_message\", \"trigger_type\", \"date\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(8,'backup_scheduled_report','Database Backup Health & Status Report','system','[{app_name}] Periodic Backup Health Report','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">Database Backup Health Report</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Here is the current operational summary of your database backups and disk volume.</p>\n    <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;\">\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Total Stored Backups:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{total_backups}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Total Storage Used:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{total_storage}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Latest Backup Archive:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{latest_backup}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Latest Backup Date:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{latest_date}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Automated Schedule:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{schedule_status}</td></tr>\n        <tr><td style=\"padding: 8px 0; color: #6b7280;\">Report Generated:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{report_date}</td></tr>\n    </table>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{dashboard_url}\" style=\"background: #0284c7; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">Manage Backups</a>\n    </div>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. Automated System Report.</p>\n</div>','[\"app_name\", \"total_backups\", \"total_storage\", \"latest_backup\", \"latest_date\", \"latest_status\", \"schedule_status\", \"report_date\", \"dashboard_url\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(9,'subscriber_welcome_email','Subscriber Welcome & Confirmation Email','communication','Welcome to {app_name} Updates & Newsletter','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">Welcome to {app_name}!</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Hello {name},</p>\n    <p style=\"color: #374151; font-size: 14px;\">Thank you for subscribing to the {app_name} newsletter and updates list. You will receive the latest announcements regarding admissions, mock test schedules, lecture updates, and civil services examination insights.</p>\n    <div style=\"background: #f0fdf4; border-left: 4px solid #16a34a; padding: 14px; margin: 18px 0; font-size: 13px; color: #166534;\">\n        <strong>Subscription Active:</strong> Your email <code>{email}</code> is confirmed.\n    </div>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 11px; text-align: center;\">\n        &copy; {app_name}. If you wish to stop receiving these updates, you can <a href=\"{unsubscribe_url}\" style=\"color: #6b7280; text-decoration: underline;\">unsubscribe here</a>.\n    </p>\n</div>','[\"name\", \"email\", \"phone\", \"app_name\", \"unsubscribe_url\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(10,'ticket_created_user','Ticket Created Confirmation (User)','support','[{ticket_number}] Support Ticket Logged: {ticket_subject}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">Support Ticket Logged</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Dear {user_name},</p>\n    <p style=\"color: #374151; font-size: 14px;\">Your support ticket <strong>#{ticket_number}</strong> has been logged into our system.</p>\n    <table style=\"width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;\">\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Subject:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{ticket_subject}</td></tr>\n        <tr style=\"border-bottom: 1px solid #f3f4f6;\"><td style=\"padding: 8px 0; color: #6b7280;\">Category:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{category}</td></tr>\n        <tr><td style=\"padding: 8px 0; color: #6b7280;\">Priority:</td><td style=\"padding: 8px 0; font-weight: 600; color: #111827;\">{priority}</td></tr>\n    </table>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{ticket_url}\" style=\"background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">View Ticket Status</a>\n    </div>\n    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;\" />\n    <p style=\"color: #9ca3af; font-size: 12px; text-align: center;\">&copy; {app_name}. Automated Support Notification.</p>\n</div>','[\"user_name\", \"ticket_number\", \"ticket_subject\", \"category\", \"priority\", \"ticket_url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(11,'ticket_created_staff','New Ticket Alert (Staff)','support','[ASSIGNED] [{ticket_number}] New Support Ticket: {ticket_subject}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">New Ticket Assigned</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Hello {staff_name},</p>\n    <p style=\"color: #374151; font-size: 14px;\">A new support ticket <strong>#{ticket_number}</strong> from <strong>{user_name}</strong> has been assigned to your queue.</p>\n    <p style=\"color: #374151; font-size: 13px;\">Subject: <strong>{ticket_subject}</strong> | Priority: <strong>{priority}</strong></p>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{ticket_url}\" style=\"background: #0284c7; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">Open Ticket Desk</a>\n    </div>\n</div>','[\"staff_name\", \"ticket_number\", \"ticket_subject\", \"user_name\", \"priority\", \"ticket_url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(12,'ticket_replied_user','Ticket Response (User)','support','[{ticket_number}] New Reply: {ticket_subject}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">New Response on Ticket #{ticket_number}</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Dear {user_name},</p>\n    <p style=\"color: #374151; font-size: 14px;\"><strong>{staff_name}</strong> has replied to your support ticket:</p>\n    <div style=\"background: #f9fafb; border-left: 4px solid #2563eb; padding: 14px; margin: 16px 0; font-size: 13px; color: #1f2937;\">\n        {reply_excerpt}\n    </div>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{ticket_url}\" style=\"background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">View Full Thread</a>\n    </div>\n</div>','[\"user_name\", \"staff_name\", \"ticket_number\", \"ticket_subject\", \"reply_excerpt\", \"ticket_url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(13,'ticket_status_changed','Ticket Status Update (User)','support','[{ticket_number}] Status Updated to {new_status}','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #111827; margin-bottom: 6px;\">Ticket Status Changed</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Dear {user_name},</p>\n    <p style=\"color: #374151; font-size: 14px;\">The status of your support ticket <strong>#{ticket_number}</strong> ({ticket_subject}) has been updated from <strong>{old_status}</strong> to <strong>{new_status}</strong>.</p>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{ticket_url}\" style=\"background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">Review Ticket</a>\n    </div>\n</div>','[\"user_name\", \"ticket_number\", \"ticket_subject\", \"old_status\", \"new_status\", \"ticket_url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(14,'ticket_sla_warning','Ticket SLA Breach Warning (Staff)','support','[SLA WARNING] [{ticket_number}] {breach_type} Breached','<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #fee2e2; border-radius: 12px; background: #ffffff;\">\n    <h2 style=\"color: #b91c1c; margin-bottom: 6px;\">SLA Breach Alert</h2>\n    <p style=\"color: #374151; font-size: 14px;\">Hello {staff_name},</p>\n    <p style=\"color: #374151; font-size: 14px;\">Support ticket <strong>#{ticket_number}</strong> has exceeded its target <strong>{breach_type}</strong> deadline.</p>\n    <p style=\"color: #374151; font-size: 13px;\">Subject: <strong>{ticket_subject}</strong> | Priority: <strong>{priority}</strong></p>\n    <div style=\"text-align: center; margin: 24px 0;\">\n        <a href=\"{ticket_url}\" style=\"background: #b91c1c; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;\">Address Ticket Now</a>\n    </div>\n</div>','[\"staff_name\", \"ticket_number\", \"ticket_subject\", \"breach_type\", \"priority\", \"ticket_url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `email_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `section_id` bigint unsigned DEFAULT NULL,
  `roll_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enrolled_at` date NOT NULL,
  `completed_at` date DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fee_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `cancellation_reason` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discontinued_at` date DEFAULT NULL,
  `discontinued_by` bigint unsigned DEFAULT NULL,
  `reinstated_at` timestamp NULL DEFAULT NULL,
  `reinstated_by` bigint unsigned DEFAULT NULL,
  `reinstatement_reason` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollments_enrollment_no_unique` (`enrollment_no`),
  KEY `enrollments_course_id_foreign` (`course_id`),
  KEY `enrollments_section_id_foreign` (`section_id`),
  KEY `enrollments_created_by_foreign` (`created_by`),
  KEY `enrollments_updated_by_foreign` (`updated_by`),
  KEY `enrollments_deleted_by_foreign` (`deleted_by`),
  KEY `enrollments_user_id_course_id_index` (`user_id`,`course_id`),
  KEY `enrollments_batch_id_course_id_index` (`batch_id`,`course_id`),
  KEY `enrollments_roll_no_index` (`roll_no`),
  KEY `enrollments_enrolled_at_index` (`enrolled_at`),
  KEY `enrollments_status_index` (`status`),
  KEY `enrollments_fee_status_index` (`fee_status`),
  KEY `enrollments_student_id_index` (`student_id`),
  KEY `enrollments_discontinued_by_foreign` (`discontinued_by`),
  KEY `enrollments_reinstated_by_foreign` (`reinstated_by`),
  CONSTRAINT `enrollments_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_discontinued_by_foreign` FOREIGN KEY (`discontinued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_reinstated_by_foreign` FOREIGN KEY (`reinstated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `enrollments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollments`
--

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
INSERT INTO `enrollments` VALUES (1,'EN-2026-CC-0001',3,'202600001',1,1,1,'202600001','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'EN-2026-CC-0002',4,'202600002',1,1,1,'202600002','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,'EN-2026-CC-0003',5,'202600003',1,1,1,'202600003','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL),(4,'EN-2026-CC-0004',6,'202600004',1,1,1,'202600004','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL),(5,'EN-2026-CC-0005',7,'202600005',1,1,2,'202600005','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL),(6,'EN-2026-CC-0006',8,'202600006',1,1,2,'202600006','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL),(7,'EN-2026-CC-0007',9,'202600007',1,1,1,'202600007','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL),(8,'EN-2026-CC-0008',10,'202600008',1,1,2,'202600008','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL),(9,'EN-2026-CC-0009',11,'202600009',1,1,1,'202600009','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL),(10,'EN-2026-CC-0010',12,'202600010',1,1,2,'202600010','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL),(11,'EN-2026-CC-0011',13,'202600011',1,1,1,'202600011','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL),(12,'EN-2026-CC-0012',14,'202600012',1,1,2,'202600012','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL),(13,'EN-2026-CC-0013',15,'202600013',1,1,1,'202600013','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL),(14,'EN-2026-CC-0014',16,'202600014',1,1,2,'202600014','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL),(15,'EN-2026-CC-0015',17,'202600015',1,1,2,'202600015','2026-09-02',NULL,'active',0.00,'paid',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_candidates`
--

DROP TABLE IF EXISTS `exam_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_candidates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `candidate_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `candidate_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `candidate_phone` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `roll_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `examination_center_id` bigint unsigned DEFAULT NULL,
  `examination_center_room_id` bigint unsigned DEFAULT NULL,
  `seat_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admit_card_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admit_card_downloaded_at` timestamp NULL DEFAULT NULL,
  `attendance_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attendance_marked_at` timestamp NULL DEFAULT NULL,
  `marks_obtained` decimal(8,2) DEFAULT NULL,
  `rank` int unsigned DEFAULT NULL,
  `is_qualified` tinyint(1) NOT NULL DEFAULT '0',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_candidates_exam_id_roll_number_unique` (`exam_id`,`roll_number`),
  UNIQUE KEY `exam_candidates_admit_card_token_unique` (`admit_card_token`),
  KEY `exam_candidates_user_id_foreign` (`user_id`),
  KEY `exam_candidates_examination_center_id_foreign` (`examination_center_id`),
  KEY `exam_candidates_examination_center_room_id_foreign` (`examination_center_room_id`),
  KEY `exam_candidates_student_id_index` (`student_id`),
  KEY `exam_candidates_roll_number_index` (`roll_number`),
  KEY `exam_candidates_attendance_status_index` (`attendance_status`),
  KEY `exam_candidates_is_qualified_index` (`is_qualified`),
  CONSTRAINT `exam_candidates_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_candidates_examination_center_id_foreign` FOREIGN KEY (`examination_center_id`) REFERENCES `examination_centers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exam_candidates_examination_center_room_id_foreign` FOREIGN KEY (`examination_center_room_id`) REFERENCES `examination_center_rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exam_candidates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_candidates`
--

LOCK TABLES `exam_candidates` WRITE;
/*!40000 ALTER TABLE `exam_candidates` DISABLE KEYS */;
INSERT INTO `exam_candidates` VALUES (1,1,NULL,NULL,'Arunava Dasgupta','arunava.dasgupta@example.com','+91 98301 22334','CSCEE-27-KOL-0001',1,1,'Hall-A-S001','c9iwRg0wETUTxFpwuHz5oSX3LBo2Ut42',NULL,'present',NULL,142.50,1,1,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,1,NULL,NULL,'Priyanka Banerjee','priyanka.b@example.com','+91 98310 99887','CSCEE-27-KOL-0002',1,1,'Hall-A-S002','RzHqF8gXTUdIYYURaiS7jphmN7861b6O',NULL,'present',NULL,138.00,2,1,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,1,NULL,NULL,'Sourav Mukherjee','sourav.m@example.com','+91 94330 11223','CSCEE-27-SIL-0001',2,3,'Seminar-1-S001','o7EKcpWWRQEeVazy8RH7xCVYhamm9o1h',NULL,'pending',NULL,NULL,NULL,0,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `exam_candidates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_center_mappings`
--

DROP TABLE IF EXISTS `exam_center_mappings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_center_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `examination_center_id` bigint unsigned NOT NULL,
  `examination_center_room_id` bigint unsigned DEFAULT NULL,
  `allocated_seats` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exam_center_mappings_exam_id_foreign` (`exam_id`),
  KEY `exam_center_mappings_examination_center_id_foreign` (`examination_center_id`),
  KEY `exam_center_mappings_examination_center_room_id_foreign` (`examination_center_room_id`),
  CONSTRAINT `exam_center_mappings_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_center_mappings_examination_center_id_foreign` FOREIGN KEY (`examination_center_id`) REFERENCES `examination_centers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_center_mappings_examination_center_room_id_foreign` FOREIGN KEY (`examination_center_room_id`) REFERENCES `examination_center_rooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_center_mappings`
--

LOCK TABLES `exam_center_mappings` WRITE;
/*!40000 ALTER TABLE `exam_center_mappings` DISABLE KEYS */;
INSERT INTO `exam_center_mappings` VALUES (1,1,1,NULL,120,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,1,2,NULL,80,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,1,3,NULL,60,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,2,1,NULL,120,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `exam_center_mappings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `examination_center_rooms`
--

DROP TABLE IF EXISTS `examination_center_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `examination_center_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `examination_center_id` bigint unsigned NOT NULL,
  `room_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `floor` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `building_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacity` int unsigned NOT NULL DEFAULT '30',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `examination_center_rooms_examination_center_id_foreign` (`examination_center_id`),
  KEY `examination_center_rooms_is_active_index` (`is_active`),
  CONSTRAINT `examination_center_rooms_examination_center_id_foreign` FOREIGN KEY (`examination_center_id`) REFERENCES `examination_centers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `examination_center_rooms`
--

LOCK TABLES `examination_center_rooms` WRITE;
/*!40000 ALTER TABLE `examination_center_rooms` DISABLE KEYS */;
INSERT INTO `examination_center_rooms` VALUES (1,1,'Hall-A','Ground Floor','Vidya Bhavan',60,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,1,'Hall-B','1st Floor','Vidya Bhavan',60,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,2,'Seminar-1','Ground Floor','Administrative Wing',40,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,2,'Seminar-2','1st Floor','Administrative Wing',40,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(5,3,'Auditorium-1','Ground Floor','Main Center',60,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `examination_center_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `examination_centers`
--

DROP TABLE IF EXISTS `examination_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `examination_centers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'West Bengal',
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_capacity` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `examination_centers_code_unique` (`code`),
  KEY `examination_centers_is_active_index` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `examination_centers`
--

LOCK TABLES `examination_centers` WRITE;
/*!40000 ALTER TABLE `examination_centers` DISABLE KEYS */;
INSERT INTO `examination_centers` VALUES (1,'KOL-SL-01','SNTCSSC Salt Lake Main Campus','Block FC, Sector III, Salt Lake City','Kolkata','North 24 Parganas','West Bengal','700106','Prof. Debabrata Sen','+91 33 2337 4000','saltlake.exams@sntcssc.in',120,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'SIL-RC-02','North Bengal University Regional Examination Hall','Raja Rammohunpur, Siliguri','Siliguri','Darjeeling','West Bengal','734013','Dr. Subhash Roy','+91 353 2776 331','siliguri.exams@sntcssc.in',80,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,'DGP-CT-03','Durgapur Administrative Examination Center','City Centre Campus, Mahatma Gandhi Road','Durgapur','Paschim Bardhaman','West Bengal','713216','Shri Anirban Mukherjee','+91 343 2545 100','durgapur.exams@sntcssc.in',60,1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `examination_centers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exams`
--

DROP TABLE IF EXISTS `exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exam_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `academic_year` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conducting_body` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_date` date DEFAULT NULL,
  `apply_start` date DEFAULT NULL,
  `apply_end` date DEFAULT NULL,
  `exam_date` date DEFAULT NULL,
  `exam_time` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reporting_time` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_minutes` int unsigned NOT NULL DEFAULT '120',
  `fee_general` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fee_reserved` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_vacancies` int unsigned DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `admit_card_instructions` text COLLATE utf8mb4_unicode_ci,
  `admit_card_release_date` date DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exams_uuid_unique` (`uuid`),
  UNIQUE KEY `exams_exam_code_unique` (`exam_code`),
  KEY `exams_created_by_foreign` (`created_by`),
  KEY `exams_academic_year_index` (`academic_year`),
  KEY `exams_exam_date_index` (`exam_date`),
  KEY `exams_status_index` (`status`),
  CONSTRAINT `exams_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
INSERT INTO `exams` VALUES (1,'aa7e450f-0149-4767-94bf-be02b96f2bbc','CSCEE-2027','Civil Services Coaching Entrance Examination 2027','2026-2027','Satyendranath Tagore Civil Services Study Centre','2026-10-18','2026-10-23','2026-11-22','2026-12-17','11:00 AM - 01:00 PM','10:00 AM',120,100.00,50.00,200,'published','1. Two hours objective paper consisting of General Studies (Paper I) and CSAT (Paper II).\n2. Negative marking of 0.33 marks for each incorrect response.','1. Admit card printout is mandatory along with Government Photo ID (Aadhaar/EPIC/Passport).\n2. Reach the examination center at least 60 minutes before test commencement.\n3. Digital watches, smartphones, and bags are prohibited inside test rooms.','2026-12-02',1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'54f26ca3-1a80-4a80-b543-d5e56cf1823d','WBCS-MOCK-26','WBCS Foundation Prelims Full Mock Test Series 2026','2026-2027','Academic Evaluation Board, SNTCSSC','2026-10-28','2026-10-30','2026-11-12','2026-11-16','10:30 AM - 01:00 PM','09:30 AM',150,0.00,0.00,150,'published','OMR based 200 marks simulated paper covering English, Science, History, Geography, Polity & Reasoning.','Bring Black Ballpoint Pen and institutional ID card.','2026-11-09',1,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `faculties`
--

DROP TABLE IF EXISTS `faculties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `faculties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `partner_institute_id` bigint unsigned DEFAULT NULL,
  `faculty_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faculty_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'regular',
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specialization` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qualification` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `avatar_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cv_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `subjects` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `faculties_faculty_code_unique` (`faculty_code`),
  KEY `faculties_user_id_foreign` (`user_id`),
  KEY `faculties_created_by_foreign` (`created_by`),
  KEY `faculties_updated_by_foreign` (`updated_by`),
  KEY `faculties_deleted_by_foreign` (`deleted_by`),
  KEY `faculties_status_index` (`status`),
  KEY `faculties_partner_institute_id_foreign` (`partner_institute_id`),
  KEY `faculties_faculty_type_partner_institute_id_index` (`faculty_type`,`partner_institute_id`),
  CONSTRAINT `faculties_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faculties_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faculties_partner_institute_id_foreign` FOREIGN KEY (`partner_institute_id`) REFERENCES `partner_institutes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faculties_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faculties_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `faculties`
--

LOCK TABLES `faculties` WRITE;
/*!40000 ALTER TABLE `faculties` DISABLE KEYS */;
/*!40000 ALTER TABLE `faculties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_schedules`
--

DROP TABLE IF EXISTS `fee_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fee_type_id` bigint unsigned NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `academic_year` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_month` tinyint unsigned DEFAULT NULL,
  `period_year` smallint unsigned DEFAULT NULL,
  `billing_cycle` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `due_date` date NOT NULL,
  `grace_period_days` smallint unsigned NOT NULL DEFAULT '7',
  `late_fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `total_targeted_students` int unsigned NOT NULL DEFAULT '0',
  `total_invoiced_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_collected_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fee_schedules_schedule_no_unique` (`schedule_no`),
  KEY `fee_schedules_fee_type_id_foreign` (`fee_type_id`),
  KEY `fee_schedules_course_id_foreign` (`course_id`),
  KEY `fee_schedules_created_by_foreign` (`created_by`),
  KEY `fee_schedules_updated_by_foreign` (`updated_by`),
  KEY `fee_schedules_deleted_by_foreign` (`deleted_by`),
  KEY `fee_schedules_batch_id_course_id_status_index` (`batch_id`,`course_id`,`status`),
  CONSTRAINT `fee_schedules_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fee_schedules_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fee_schedules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fee_schedules_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fee_schedules_fee_type_id_foreign` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fee_schedules_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_schedules`
--

LOCK TABLES `fee_schedules` WRITE;
/*!40000 ALTER TABLE `fee_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `fee_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_types`
--

DROP TABLE IF EXISTS `fee_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fee_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'academic',
  `frequency` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `description` text COLLATE utf8mb4_unicode_ci,
  `default_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_refundable` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fee_types_code_unique` (`code`),
  KEY `fee_types_created_by_foreign` (`created_by`),
  KEY `fee_types_updated_by_foreign` (`updated_by`),
  KEY `fee_types_deleted_by_foreign` (`deleted_by`),
  CONSTRAINT `fee_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fee_types_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fee_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_types`
--

LOCK TABLES `fee_types` WRITE;
/*!40000 ALTER TABLE `fee_types` DISABLE KEYS */;
INSERT INTO `fee_types` VALUES (1,'tuition','Monthly Tuition Fee','academic','monthly','Standard monthly academic tuition and mentoring fees for course enrollments.',1000.00,0,'active',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,'library_security','Library Security Deposit','library','one_time','Fully refundable institutional security deposit for reading room, book lending, and digital library resources.',1000.00,1,'active',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,'registration','Admission Registration Fee','admission','one_time','One-time student enrolment and document verification administrative fee.',500.00,0,'active',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,'admission_test','Entrance Screening Test Fee','admission','one_time','Preliminary candidate screening and computer-based admission test processing charge.',200.00,0,'active',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,'examination','Periodic Assessment & Test Series Fee','academic','quarterly','Evaluation, OMR scanning, mock interview, and question booklet printing charge.',300.00,0,'active',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL);
/*!40000 ALTER TABLE `fee_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `health_check_result_history_items`
--

DROP TABLE IF EXISTS `health_check_result_history_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `health_check_result_history_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `check_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_message` text COLLATE utf8mb4_unicode_ci,
  `short_summary` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json NOT NULL,
  `ended_at` timestamp NOT NULL,
  `batch` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `health_check_result_history_items_created_at_index` (`created_at`),
  KEY `health_check_result_history_items_batch_index` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `health_check_result_history_items`
--

LOCK TABLES `health_check_result_history_items` WRITE;
/*!40000 ALTER TABLE `health_check_result_history_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `health_check_result_history_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_allocations`
--

DROP TABLE IF EXISTS `hostel_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `allocation_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `resident_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `hostel_room_id` bigint unsigned NOT NULL,
  `hostel_bed_id` bigint unsigned NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `duration_type` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_value` int unsigned DEFAULT NULL,
  `actual_vacated_date` date DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `hard_copy_submitted` tinyint(1) NOT NULL DEFAULT '0',
  `hard_copy_submitted_at` date DEFAULT NULL,
  `hard_copy_received_by` bigint unsigned DEFAULT NULL,
  `hard_copy_notes` text COLLATE utf8mb4_unicode_ci,
  `soft_copy_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `soft_copy_uploaded_at` datetime DEFAULT NULL,
  `soft_copy_uploaded_by` bigint unsigned DEFAULT NULL,
  `soft_copy_verified` tinyint(1) NOT NULL DEFAULT '0',
  `soft_copy_verified_at` datetime DEFAULT NULL,
  `soft_copy_verified_by` bigint unsigned DEFAULT NULL,
  `is_previous_batch_student` tinyint(1) NOT NULL DEFAULT '0',
  `previous_batch_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostel_allocations_allocation_no_unique` (`allocation_no`),
  KEY `hostel_allocations_user_id_foreign` (`user_id`),
  KEY `hostel_allocations_hostel_room_id_foreign` (`hostel_room_id`),
  KEY `hostel_allocations_hostel_bed_id_foreign` (`hostel_bed_id`),
  KEY `hostel_allocations_hard_copy_received_by_foreign` (`hard_copy_received_by`),
  KEY `hostel_allocations_soft_copy_uploaded_by_foreign` (`soft_copy_uploaded_by`),
  KEY `hostel_allocations_soft_copy_verified_by_foreign` (`soft_copy_verified_by`),
  KEY `hostel_allocations_created_by_foreign` (`created_by`),
  KEY `hostel_allocations_updated_by_foreign` (`updated_by`),
  KEY `hostel_allocations_deleted_by_foreign` (`deleted_by`),
  KEY `hostel_allocations_resident_type_index` (`resident_type`),
  KEY `hostel_allocations_status_index` (`status`),
  KEY `hostel_allocations_hard_copy_submitted_index` (`hard_copy_submitted`),
  KEY `hostel_allocations_soft_copy_verified_index` (`soft_copy_verified`),
  KEY `hostel_allocations_is_previous_batch_student_index` (`is_previous_batch_student`),
  CONSTRAINT `hostel_allocations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_allocations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_allocations_hard_copy_received_by_foreign` FOREIGN KEY (`hard_copy_received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_allocations_hostel_bed_id_foreign` FOREIGN KEY (`hostel_bed_id`) REFERENCES `hostel_beds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_allocations_hostel_room_id_foreign` FOREIGN KEY (`hostel_room_id`) REFERENCES `hostel_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_allocations_soft_copy_uploaded_by_foreign` FOREIGN KEY (`soft_copy_uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_allocations_soft_copy_verified_by_foreign` FOREIGN KEY (`soft_copy_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_allocations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_allocations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_allocations`
--

LOCK TABLES `hostel_allocations` WRITE;
/*!40000 ALTER TABLE `hostel_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `hostel_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_beds`
--

DROP TABLE IF EXISTS `hostel_beds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_beds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hostel_room_id` bigint unsigned NOT NULL,
  `bed_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_bed_unique` (`hostel_room_id`,`bed_number`),
  KEY `hostel_beds_created_by_foreign` (`created_by`),
  KEY `hostel_beds_updated_by_foreign` (`updated_by`),
  KEY `hostel_beds_deleted_by_foreign` (`deleted_by`),
  KEY `hostel_beds_status_index` (`status`),
  CONSTRAINT `hostel_beds_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_beds_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_beds_hostel_room_id_foreign` FOREIGN KEY (`hostel_room_id`) REFERENCES `hostel_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_beds_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_beds`
--

LOCK TABLES `hostel_beds` WRITE;
/*!40000 ALTER TABLE `hostel_beds` DISABLE KEYS */;
INSERT INTO `hostel_beds` VALUES (1,1,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,2,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,2,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,3,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(5,3,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(6,3,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(7,4,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(8,5,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(9,5,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(10,6,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(11,6,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(12,6,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(13,7,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(14,8,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(15,8,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(16,9,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(17,9,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(18,9,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(19,10,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(20,11,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(21,11,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(22,12,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(23,12,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(24,12,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(25,13,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(26,14,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(27,14,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(28,15,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(29,15,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(30,15,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(31,15,'Bed 4','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(32,16,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(33,17,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(34,17,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(35,18,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(36,18,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(37,18,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(38,18,'Bed 4','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(39,19,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(40,20,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(41,20,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(42,21,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(43,21,'Bed 2','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(44,21,'Bed 3','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(45,21,'Bed 4','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(46,22,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(47,23,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(48,23,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(49,24,'Bed 1','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(50,25,'Bed A','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(51,25,'Bed B','available',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `hostel_beds` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_buildings`
--

DROP TABLE IF EXISTS `hostel_buildings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_buildings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'coed',
  `total_floors` int unsigned NOT NULL DEFAULT '1',
  `warden_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warden_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warden_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_contact` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostel_buildings_code_unique` (`code`),
  KEY `hostel_buildings_created_by_foreign` (`created_by`),
  KEY `hostel_buildings_updated_by_foreign` (`updated_by`),
  KEY `hostel_buildings_deleted_by_foreign` (`deleted_by`),
  KEY `hostel_buildings_gender_type_index` (`gender_type`),
  KEY `hostel_buildings_status_index` (`status`),
  CONSTRAINT `hostel_buildings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_buildings_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_buildings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_buildings`
--

LOCK TABLES `hostel_buildings` WRITE;
/*!40000 ALTER TABLE `hostel_buildings` DISABLE KEYS */;
INSERT INTO `hostel_buildings` VALUES (1,'Ganga Hostel','GH','boys_only',4,'Prof. S. K. Banerjee','+91 98301 23456','warden.ganga@sntcssc.edu.in','Mr. Biplab Karmakar','+91 98305 11223','manager.ganga@sntcssc.edu.in','Dedicated Boys Residential Hostel with study halls, Wi-Fi, and 24/7 power backup.','active',1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'Mayurakhshi Hostel','MH','girls_only',3,'Dr. Ananya Sen','+91 98302 34567','warden.mayurakhshi@sntcssc.edu.in','Mrs. Sumana Roy','+91 98305 22334','manager.mayurakhshi@sntcssc.edu.in','Dedicated Girls Residential Hostel featuring biometric security, library annex, and infirmary.','active',2,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,'Teesta Hostel','TH','coed',2,'Dr. R. K. Mukherjee','+91 98303 45678','warden.teesta@sntcssc.edu.in','Mr. Debasis Roy','+91 98305 33445','manager.teesta@sntcssc.edu.in','Executive & Faculty Residence with separated male and female accommodation wings.','active',3,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `hostel_buildings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_caretakers`
--

DROP TABLE IF EXISTS `hostel_caretakers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_caretakers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hostel_building_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shift` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Day Shift',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hostel_caretakers_created_by_foreign` (`created_by`),
  KEY `hostel_caretakers_updated_by_foreign` (`updated_by`),
  KEY `hostel_caretakers_deleted_by_foreign` (`deleted_by`),
  KEY `hc_building_status_idx` (`hostel_building_id`,`status`),
  KEY `hostel_caretakers_is_primary_index` (`is_primary`),
  KEY `hostel_caretakers_status_index` (`status`),
  CONSTRAINT `hostel_caretakers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_caretakers_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_caretakers_hostel_building_id_foreign` FOREIGN KEY (`hostel_building_id`) REFERENCES `hostel_buildings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_caretakers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_caretakers`
--

LOCK TABLES `hostel_caretakers` WRITE;
/*!40000 ALTER TABLE `hostel_caretakers` DISABLE KEYS */;
INSERT INTO `hostel_caretakers` VALUES (1,1,'Ramesh Chandra Das','+91 98311 44556','caretaker1.ganga@sntcssc.edu.in','Day Shift (6:00 AM - 2:00 PM)',1,'active','Main Gate Security & Key Registry Desk',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,1,'Subal Naskar','+91 98311 55667','caretaker2.ganga@sntcssc.edu.in','Evening Shift (2:00 PM - 10:00 PM)',0,'active','Floor 1-3 Maintenance & Study Hall Supervision',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,1,'Tapan Mondal','+91 98311 66778','caretaker3.ganga@sntcssc.edu.in','Night Shift (10:00 PM - 6:00 AM)',0,'active','Night Emergency Assistance & Gate Closure Desk',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,2,'Meena Halder','+91 98312 11223','caretaker1.mayurakhshi@sntcssc.edu.in','Day Shift (6:00 AM - 2:00 PM)',1,'active','Reception Desk, Visitor Log & First Aid Care',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(5,2,'Aparna Ghosh','+91 98312 22334','caretaker2.mayurakhshi@sntcssc.edu.in','Evening Shift (2:00 PM - 10:00 PM)',0,'active','Hostel Library & Common Facilities',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(6,2,'Shanti Barman','+91 98312 33445','caretaker3.mayurakhshi@sntcssc.edu.in','Night Shift (10:00 PM - 6:00 AM)',0,'active','Night Biometric Gate & Warden Liaison',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(7,3,'Prabir Sen','+91 98313 11223','caretaker1.teesta@sntcssc.edu.in','Rotating Shift (Day/Evening)',1,'active','Executive Wing Supervisor & Concierge Desk',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(8,3,'Kanika Dey','+91 98313 22334','caretaker2.teesta@sntcssc.edu.in','Day Shift (8:00 AM - 4:00 PM)',0,'active','Faculty Wing Care & Housekeeping Audit',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `hostel_caretakers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_extension_requests`
--

DROP TABLE IF EXISTS `hostel_extension_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_extension_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hostel_allocation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `current_end_date` date DEFAULT NULL,
  `requested_end_date` date NOT NULL,
  `extension_duration_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'months',
  `extension_duration_value` int unsigned NOT NULL DEFAULT '1',
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostel_extension_requests_request_no_unique` (`request_no`),
  KEY `hostel_extension_requests_hostel_allocation_id_foreign` (`hostel_allocation_id`),
  KEY `hostel_extension_requests_user_id_foreign` (`user_id`),
  KEY `hostel_extension_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `hostel_extension_requests_created_by_foreign` (`created_by`),
  KEY `hostel_extension_requests_updated_by_foreign` (`updated_by`),
  KEY `hostel_extension_requests_deleted_by_foreign` (`deleted_by`),
  KEY `hostel_extension_requests_status_index` (`status`),
  CONSTRAINT `hostel_extension_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_extension_requests_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_extension_requests_hostel_allocation_id_foreign` FOREIGN KEY (`hostel_allocation_id`) REFERENCES `hostel_allocations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_extension_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_extension_requests_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_extension_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_extension_requests`
--

LOCK TABLES `hostel_extension_requests` WRITE;
/*!40000 ALTER TABLE `hostel_extension_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `hostel_extension_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_floors`
--

DROP TABLE IF EXISTS `hostel_floors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_floors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hostel_building_id` bigint unsigned NOT NULL,
  `floor_number` int NOT NULL DEFAULT '0',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `gender_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'coed',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hb_floor_unique` (`hostel_building_id`,`floor_number`),
  KEY `hostel_floors_created_by_foreign` (`created_by`),
  KEY `hostel_floors_updated_by_foreign` (`updated_by`),
  KEY `hostel_floors_deleted_by_foreign` (`deleted_by`),
  KEY `hostel_floors_gender_type_index` (`gender_type`),
  KEY `hostel_floors_status_index` (`status`),
  CONSTRAINT `hostel_floors_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_floors_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_floors_hostel_building_id_foreign` FOREIGN KEY (`hostel_building_id`) REFERENCES `hostel_buildings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_floors_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_floors`
--

LOCK TABLES `hostel_floors` WRITE;
/*!40000 ALTER TABLE `hostel_floors` DISABLE KEYS */;
INSERT INTO `hostel_floors` VALUES (1,1,0,'Ground Floor','boys_only',NULL,'active',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,1,1,'1st Floor','boys_only',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,1,2,'2nd Floor','boys_only',NULL,'active',2,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,1,3,'3rd Floor','boys_only',NULL,'active',3,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(5,2,0,'Ground Floor','girls_only',NULL,'active',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(6,2,1,'1st Floor','girls_only',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(7,2,2,'2nd Floor','girls_only',NULL,'active',2,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(8,3,0,'Ground Floor (Wing A - Male)','boys_only',NULL,'active',0,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(9,3,1,'1st Floor (Wing B - Female)','girls_only',NULL,'active',1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `hostel_floors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hostel_rooms`
--

DROP TABLE IF EXISTS `hostel_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hostel_rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `hostel_building_id` bigint unsigned NOT NULL,
  `hostel_floor_id` bigint unsigned NOT NULL,
  `room_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sharing',
  `capacity` int unsigned NOT NULL DEFAULT '2',
  `gender_allowed` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'male_only',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `amenities` json DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hb_room_unique` (`hostel_building_id`,`room_number`),
  KEY `hostel_rooms_hostel_floor_id_foreign` (`hostel_floor_id`),
  KEY `hostel_rooms_created_by_foreign` (`created_by`),
  KEY `hostel_rooms_updated_by_foreign` (`updated_by`),
  KEY `hostel_rooms_deleted_by_foreign` (`deleted_by`),
  KEY `hostel_rooms_room_number_index` (`room_number`),
  KEY `hostel_rooms_room_type_index` (`room_type`),
  KEY `hostel_rooms_gender_allowed_index` (`gender_allowed`),
  KEY `hostel_rooms_status_index` (`status`),
  CONSTRAINT `hostel_rooms_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_rooms_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `hostel_rooms_hostel_building_id_foreign` FOREIGN KEY (`hostel_building_id`) REFERENCES `hostel_buildings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_rooms_hostel_floor_id_foreign` FOREIGN KEY (`hostel_floor_id`) REFERENCES `hostel_floors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hostel_rooms_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hostel_rooms`
--

LOCK TABLES `hostel_rooms` WRITE;
/*!40000 ALTER TABLE `hostel_rooms` DISABLE KEYS */;
INSERT INTO `hostel_rooms` VALUES (1,1,1,'G-001','non_sharing',1,'male_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]','Single occupancy room with balcony.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,1,1,'G-002','sharing',2,'male_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]','Double sharing room with dedicated study desks.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,1,1,'G-003','sharing',3,'male_only','available','[\"study_table\", \"wifi\"]','Triple sharing room.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,1,2,'G-101','non_sharing',1,'male_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]','Single occupancy room with balcony.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(5,1,2,'G-102','sharing',2,'male_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]','Double sharing room with dedicated study desks.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(6,1,2,'G-103','sharing',3,'male_only','available','[\"study_table\", \"wifi\"]','Triple sharing room.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(7,1,3,'G-201','non_sharing',1,'male_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]','Single occupancy room with balcony.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(8,1,3,'G-202','sharing',2,'male_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]','Double sharing room with dedicated study desks.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(9,1,3,'G-203','sharing',3,'male_only','available','[\"study_table\", \"wifi\"]','Triple sharing room.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(10,1,4,'G-301','non_sharing',1,'male_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]','Single occupancy room with balcony.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(11,1,4,'G-302','sharing',2,'male_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]','Double sharing room with dedicated study desks.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(12,1,4,'G-303','sharing',3,'male_only','available','[\"study_table\", \"wifi\"]','Triple sharing room.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(13,2,5,'M-001','non_sharing',1,'female_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(14,2,5,'M-002','sharing',2,'female_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(15,2,5,'M-003','sharing',4,'female_only','available','[\"study_table\", \"wifi\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(16,2,6,'M-101','non_sharing',1,'female_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(17,2,6,'M-102','sharing',2,'female_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(18,2,6,'M-103','sharing',4,'female_only','available','[\"study_table\", \"wifi\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(19,2,7,'M-201','non_sharing',1,'female_only','available','[\"attached_washroom\", \"study_table\", \"wifi\", \"balcony\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(20,2,7,'M-202','sharing',2,'female_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(21,2,7,'M-203','sharing',4,'female_only','available','[\"study_table\", \"wifi\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(22,3,8,'T-001','non_sharing',1,'male_only','available','[\"attached_washroom\", \"ac\", \"study_table\", \"wifi\"]','Executive Single AC room.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(23,3,8,'T-002','sharing',2,'male_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(24,3,9,'T-101','non_sharing',1,'female_only','available','[\"attached_washroom\", \"ac\", \"study_table\", \"wifi\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(25,3,9,'T-102','sharing',2,'female_only','available','[\"study_table\", \"wifi\", \"attached_washroom\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `hostel_rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `interview_board_members`
--

DROP TABLE IF EXISTS `interview_board_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `interview_board_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `member_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `organization` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specialization` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `interview_board_members_member_code_unique` (`member_code`),
  KEY `fk_mi_board_user` (`user_id`),
  KEY `fk_mi_board_created` (`created_by`),
  KEY `fk_mi_board_updated` (`updated_by`),
  KEY `fk_mi_board_deleted` (`deleted_by`),
  KEY `mi_members_status_idx` (`status`),
  KEY `mi_members_name_idx` (`name`),
  CONSTRAINT `fk_mi_board_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_board_deleted` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_board_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_board_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `interview_board_members`
--

LOCK TABLES `interview_board_members` WRITE;
/*!40000 ALTER TABLE `interview_board_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `interview_board_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `native_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ltr',
  `flag` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `languages`
--

LOCK TABLES `languages` WRITE;
/*!40000 ALTER TABLE `languages` DISABLE KEYS */;
INSERT INTO `languages` VALUES (1,'en','English','English','ltr','??',1,1,1,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(2,'hi','Hindi','हिन्दी','ltr','??',0,1,2,'2026-11-01 20:15:00','2026-11-01 20:15:00'),(3,'bn','Bengali','বাংলা','ltr','??',0,1,3,'2026-11-01 20:15:00','2026-11-01 20:15:00');
/*!40000 ALTER TABLE `languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_allocations`
--

DROP TABLE IF EXISTS `leave_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_allocations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `leave_category_id` bigint unsigned NOT NULL,
  `year` smallint unsigned NOT NULL DEFAULT '2026',
  `total_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `used_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `pending_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `carried_forward_days` decimal(5,1) NOT NULL DEFAULT '0.0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_alloc_user_cat_year_unique` (`user_id`,`leave_category_id`,`year`),
  KEY `leave_allocations_leave_category_id_foreign` (`leave_category_id`),
  KEY `leave_allocations_created_by_foreign` (`created_by`),
  KEY `leave_allocations_updated_by_foreign` (`updated_by`),
  KEY `leave_allocations_deleted_by_foreign` (`deleted_by`),
  KEY `leave_allocations_year_index` (`year`),
  CONSTRAINT `leave_allocations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_allocations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_allocations_leave_category_id_foreign` FOREIGN KEY (`leave_category_id`) REFERENCES `leave_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_allocations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_allocations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_allocations`
--

LOCK TABLES `leave_allocations` WRITE;
/*!40000 ALTER TABLE `leave_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_applications`
--

DROP TABLE IF EXISTS `leave_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `leave_category_id` bigint unsigned NOT NULL,
  `user_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `total_days` decimal(5,1) NOT NULL DEFAULT '1.0',
  `is_half_day` tinyint(1) NOT NULL DEFAULT '0',
  `half_day_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `action_by` bigint unsigned DEFAULT NULL,
  `action_at` timestamp NULL DEFAULT NULL,
  `action_remarks` text COLLATE utf8mb4_unicode_ci,
  `is_synced_to_attendance` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_applications_application_no_unique` (`application_no`),
  KEY `leave_applications_leave_category_id_foreign` (`leave_category_id`),
  KEY `leave_applications_action_by_foreign` (`action_by`),
  KEY `leave_applications_created_by_foreign` (`created_by`),
  KEY `leave_applications_updated_by_foreign` (`updated_by`),
  KEY `leave_applications_deleted_by_foreign` (`deleted_by`),
  KEY `leave_applications_user_id_status_index` (`user_id`,`status`),
  KEY `leave_applications_user_type_index` (`user_type`),
  KEY `leave_applications_from_date_index` (`from_date`),
  KEY `leave_applications_to_date_index` (`to_date`),
  KEY `leave_applications_status_index` (`status`),
  KEY `leave_applications_is_synced_to_attendance_index` (`is_synced_to_attendance`),
  CONSTRAINT `leave_applications_action_by_foreign` FOREIGN KEY (`action_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_leave_category_id_foreign` FOREIGN KEY (`leave_category_id`) REFERENCES `leave_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_applications_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_applications`
--

LOCK TABLES `leave_applications` WRITE;
/*!40000 ALTER TABLE `leave_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_categories`
--

DROP TABLE IF EXISTS `leave_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applicable_to` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'both',
  `color` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'blue',
  `annual_quota` decimal(5,1) NOT NULL DEFAULT '12.0',
  `monthly_limit` decimal(4,1) DEFAULT NULL,
  `requires_attachment` tinyint(1) NOT NULL DEFAULT '0',
  `requires_approval` tinyint(1) NOT NULL DEFAULT '1',
  `is_paid` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_categories_code_unique` (`code`),
  KEY `leave_categories_created_by_foreign` (`created_by`),
  KEY `leave_categories_updated_by_foreign` (`updated_by`),
  KEY `leave_categories_deleted_by_foreign` (`deleted_by`),
  KEY `leave_categories_applicable_to_index` (`applicable_to`),
  KEY `leave_categories_is_active_index` (`is_active`),
  CONSTRAINT `leave_categories_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_categories_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_categories_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_categories`
--

LOCK TABLES `leave_categories` WRITE;
/*!40000 ALTER TABLE `leave_categories` DISABLE KEYS */;
INSERT INTO `leave_categories` VALUES (1,'Casual Leave','CL','both','blue',12.0,2.0,0,1,1,1,'Standard short-duration personal leave for urgent private affairs.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'Medical / Sick Leave','ML','both','rose',10.0,NULL,1,1,1,1,'Leave on grounds of sickness or medical treatment. Doctor certificate required for > 2 days.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(3,'Academic / Study Leave','AL','student','purple',15.0,5.0,0,1,0,1,'Leave for external UPSC coaching, seminars, or field survey academic study.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(4,'Duty / Official Leave','DL','staff','emerald',10.0,NULL,0,1,1,1,'Official outstation deputation, exam invigilation, or institutional meetings.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(5,'Maternity / Paternity Leave','MAT','staff','amber',90.0,NULL,1,1,1,1,'Statutory parental and childcare leave for office staff as per state government rules.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(6,'Exam Leave','EXAM','student','teal',7.0,NULL,1,1,0,1,'Leave for appearing in UPSC Prelims/Mains, WBCS, or university degree semester exams.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(7,'Compensatory Off','COMP','staff','sky',5.0,NULL,0,1,1,1,'Leave granted in lieu of attending official duties on holidays or Sundays.',NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `leave_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `library_deposits`
--

DROP TABLE IF EXISTS `library_deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `library_deposits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deposit_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_transaction_id` bigint unsigned DEFAULT NULL,
  `deposit_date` date NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `clearance_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `clearance_certificate_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cleared_at` timestamp NULL DEFAULT NULL,
  `cleared_by_id` bigint unsigned DEFAULT NULL,
  `clearance_remarks` text COLLATE utf8mb4_unicode_ci,
  `refund_requested_at` datetime DEFAULT NULL,
  `refund_requested_by` bigint unsigned DEFAULT NULL,
  `refund_reason` text COLLATE utf8mb4_unicode_ci,
  `bank_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ifsc_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_holder_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `upi_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refund_reviewed_by` bigint unsigned DEFAULT NULL,
  `refund_reviewed_at` datetime DEFAULT NULL,
  `refund_approved_amount` decimal(10,2) DEFAULT NULL,
  `refund_processed_by` bigint unsigned DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  `refund_transaction_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refund_receipt_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `library_deposits_deposit_no_unique` (`deposit_no`),
  UNIQUE KEY `library_deposits_clearance_certificate_no_unique` (`clearance_certificate_no`),
  KEY `library_deposits_payment_transaction_id_foreign` (`payment_transaction_id`),
  KEY `library_deposits_refund_requested_by_foreign` (`refund_requested_by`),
  KEY `library_deposits_refund_reviewed_by_foreign` (`refund_reviewed_by`),
  KEY `library_deposits_refund_processed_by_foreign` (`refund_processed_by`),
  KEY `library_deposits_created_by_foreign` (`created_by`),
  KEY `library_deposits_updated_by_foreign` (`updated_by`),
  KEY `library_deposits_deleted_by_foreign` (`deleted_by`),
  KEY `library_deposits_user_id_status_index` (`user_id`,`status`),
  KEY `library_deposits_student_id_index` (`student_id`),
  KEY `library_deposits_cleared_by_id_foreign` (`cleared_by_id`),
  KEY `library_deposits_clearance_status_index` (`clearance_status`),
  CONSTRAINT `library_deposits_cleared_by_id_foreign` FOREIGN KEY (`cleared_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_payment_transaction_id_foreign` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_refund_processed_by_foreign` FOREIGN KEY (`refund_processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_refund_requested_by_foreign` FOREIGN KEY (`refund_requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_refund_reviewed_by_foreign` FOREIGN KEY (`refund_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_deposits_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `library_deposits`
--

LOCK TABLES `library_deposits` WRITE;
/*!40000 ALTER TABLE `library_deposits` DISABLE KEYS */;
/*!40000 ALTER TABLE `library_deposits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `library_members`
--

DROP TABLE IF EXISTS `library_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `library_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `member_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `member_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'student',
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `date_of_birth` date DEFAULT NULL,
  `joining_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `photo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `security_deposit_override` decimal(10,2) DEFAULT NULL,
  `bypass_deposit_check` tinyint(1) NOT NULL DEFAULT '0',
  `deposit_bypass_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `active_issued_count` int unsigned NOT NULL DEFAULT '0',
  `total_issued_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `library_members_member_number_unique` (`member_number`),
  KEY `library_members_user_id_foreign` (`user_id`),
  KEY `library_members_created_by_foreign` (`created_by`),
  KEY `library_members_updated_by_foreign` (`updated_by`),
  KEY `library_members_deleted_by_foreign` (`deleted_by`),
  KEY `library_members_student_id_index` (`student_id`),
  KEY `library_members_email_index` (`email`),
  KEY `library_members_phone_index` (`phone`),
  KEY `library_members_status_index` (`status`),
  CONSTRAINT `library_members_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_members_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_members_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `library_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `library_members`
--

LOCK TABLES `library_members` WRITE;
/*!40000 ALTER TABLE `library_members` DISABLE KEYS */;
INSERT INTO `library_members` VALUES (1,'LIB-MEM-2026-0001',3,'202600001','student','Subhankar Basak','subhankarbasakmail@gmail.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'LIB-MEM-2026-0002',4,'202600002','student','Ananya Mukherjee','ananya.m@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'LIB-MEM-2026-0003',5,'202600003','student','Debjit Chakraborty','debjit.c@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'LIB-MEM-2026-0004',6,'202600004','student','Priyanka Banerjee','priyanka.b@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'LIB-MEM-2026-0005',7,'202600005','student','Rohit Sen','rohit.sen@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(6,'LIB-MEM-2026-0006',8,'202600006','student','Sourav Ghosh','sourav.g@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(7,'LIB-MEM-2026-0007',9,'202600007','student','Rupa Roy','rupa.roy@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(8,'LIB-MEM-2026-0008',10,'202600008','student','Arnab Bhattacharya','arnab.b@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(9,'LIB-MEM-2026-0009',11,'202600009','student','Moumita Das','moumita.d@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(10,'LIB-MEM-2026-0010',12,'202600010','student','Siddhartha Dutta','siddhartha.d@example.com',NULL,NULL,NULL,NULL,'2026-11-02','2027-11-02',NULL,NULL,0,NULL,'active',0,0,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `library_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2024_01_01_000000_create_passkeys_table',1),(5,'2025_08_14_170933_add_two_factor_columns_to_users_table',1),(6,'2026_01_27_000001_create_teams_table',1),(7,'2026_01_27_000002_add_current_team_id_to_users_table',1),(8,'2026_08_21_183306_create_permission_tables',1),(9,'2026_08_21_184324_create_personal_access_tokens_table',1),(10,'2026_08_22_120730_create_health_tables',1),(11,'2026_08_22_130000_create_settings_table',1),(12,'2026_08_23_105840_create_languages_table',1),(13,'2026_08_23_105853_create_audit_logs_table',1),(14,'2026_08_23_133153_add_avatar_to_users_table',1),(15,'2026_08_23_140000_add_phone_to_users_table',1),(16,'2026_08_23_140001_create_otp_codes_table',1),(17,'2026_08_23_140002_create_message_templates_tables',1),(18,'2026_08_24_000001_create_communication_logs_table',1),(19,'2026_08_24_000002_create_communication_campaigns_table',1),(20,'2026_08_24_000003_create_cron_jobs_table',1),(21,'2026_08_24_000004_create_pages_and_translations_tables',1),(22,'2026_08_24_000005_create_contact_management_tables',1),(23,'2026_08_24_100000_add_rbac_and_profile_fields_to_users_table',1),(24,'2026_08_24_185710_create_backups_table',1),(25,'2026_08_25_004500_create_ticket_system_tables',1),(26,'2026_08_25_013000_create_subscribers_table',1),(27,'2026_08_26_130439_create_app_notifications_table',1),(28,'2026_08_26_193000_create_chat_tables',1),(29,'2026_08_26_194500_add_invite_code_to_chat_conversations_table',1),(30,'2026_08_26_201500_create_chat_meetings_table',1),(31,'2026_08_26_204500_add_meeting_access_and_waiting_room_support',1),(32,'2026_08_26_210500_add_pinned_fields_to_chat_messages_table',1),(33,'2026_08_26_234500_add_advanced_scheduling_and_reminders_to_chat_meetings_table',1),(34,'2026_08_27_161000_create_chat_message_reactions_table',1),(35,'2026_09_01_210500_add_soft_deletes_to_chat_call_participants_table',1),(36,'2026_09_04_232000_add_soft_deletes_to_chat_meeting_participants_table',1),(37,'2026_10_02_100000_add_student_id_to_users_table',1),(38,'2026_10_02_110000_create_batches_courses_sections_enrollments_tables',1),(39,'2026_10_02_120000_create_student_profile_dossier_tables',1),(40,'2026_10_02_130000_create_profile_update_requests_table',1),(41,'2026_10_02_140000_add_extended_fields_and_upsc_attempts_table',1),(42,'2026_10_02_153234_make_file_path_nullable_on_student_documents_table',1),(43,'2026_10_02_160944_replace_subcategory_with_distance_in_student_profiles_table',1),(44,'2026_10_02_191547_add_is_working_professional_to_student_profiles_table',1),(45,'2026_10_03_073639_update_courses_and_enrollments_for_fees_and_capacities',1),(46,'2026_10_03_093000_create_course_withdrawals_and_disciplinary_actions_tables',1),(47,'2026_10_03_120000_create_fees_and_payments_tables',1),(48,'2026_10_03_140000_add_reinstatement_fields_to_enrollments_table',1),(49,'2026_10_13_000000_create_library_management_tables',1),(50,'2026_10_13_000001_add_is_active_to_library_tables',1),(51,'2026_10_13_000002_add_library_clearance_to_library_deposits',1),(52,'2026_10_13_000003_add_inhouse_reading_to_library_tables',1),(53,'2026_10_13_000004_add_renewal_approval_to_book_issues',1),(54,'2026_10_13_000005_add_missing_columns_to_library_tables',1),(55,'2026_10_13_000006_add_ddc_classification_to_book_categories',1),(56,'2026_10_13_000007_add_condition_and_library_master_columns',1),(57,'2026_10_22_000000_create_leave_and_attendance_management_tables',1),(58,'2026_10_26_000000_create_alumni_management_tables',1),(59,'2026_10_26_010000_create_alumni_examinations_and_postings_tables',1),(60,'2026_10_26_020000_create_study_materials_management_tables',1),(61,'2026_10_26_030000_create_api_keys_and_logs_tables',1),(62,'2026_10_26_040000_create_study_material_types_and_lead_captures_table',1),(63,'2026_10_26_050000_create_alumni_service_categories_table',1),(64,'2026_10_26_115522_add_thumbnail_class_date_and_display_order_to_study_materials_table',1),(65,'2026_10_27_000000_create_hostel_management_tables',1),(66,'2026_10_27_010000_add_manager_and_caretakers_to_hostel_tables',1),(67,'2026_10_28_000000_create_examination_management_tables',1),(68,'2026_10_29_000000_create_student_tests_management_tables',1),(69,'2026_10_29_100000_create_optional_subjects_management_tables',1),(70,'2026_11_01_010000_create_global_search_and_fullscreen_settings',1),(71,'2026_11_01_012709_create_class_routine_and_faculty_management_tables',1),(72,'2026_11_01_030000_create_classrooms_and_update_schedules_tables',1),(73,'2026_11_01_034030_create_partner_institutes_and_update_faculties_table',1),(74,'2026_11_01_044500_create_partner_institute_academic_assignments_tables',1),(75,'2026_11_01_060000_create_mock_interview_management_tables',1),(76,'2026_11_01_070000_create_academic_calendar_management_tables',1),(77,'2026_11_01_080000_create_document_management_tables',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mock_interview_candidates`
--

DROP TABLE IF EXISTS `mock_interview_candidates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mock_interview_candidates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mock_interview_session_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `sequence_number` int unsigned NOT NULL DEFAULT '1',
  `scheduled_start_time` time NOT NULL,
  `scheduled_end_time` time NOT NULL,
  `duration_minutes` int unsigned NOT NULL DEFAULT '30',
  `attending_mode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `venue_address` text COLLATE utf8mb4_unicode_ci,
  `room_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_other` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meeting_link` text COLLATE utf8mb4_unicode_ci,
  `meeting_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meeting_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `candidate_instructions` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `interview_score` decimal(5,2) DEFAULT NULL,
  `board_feedback` text COLLATE utf8mb4_unicode_ci,
  `recording_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'url',
  `recording_url` text COLLATE utf8mb4_unicode_ci,
  `recording_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recording_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_available',
  `recording_uploaded_at` timestamp NULL DEFAULT NULL,
  `recording_notes` text COLLATE utf8mb4_unicode_ci,
  `invitation_sent_at` timestamp NULL DEFAULT NULL,
  `invitation_channels` json DEFAULT NULL,
  `recording_sent_at` timestamp NULL DEFAULT NULL,
  `recording_sent_channels` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_mi_cand_batch` (`batch_id`),
  KEY `fk_mi_cand_course` (`course_id`),
  KEY `fk_mi_cand_created` (`created_by`),
  KEY `fk_mi_cand_updated` (`updated_by`),
  KEY `fk_mi_cand_deleted` (`deleted_by`),
  KEY `mi_cand_seq_idx` (`mock_interview_session_id`,`sequence_number`),
  KEY `mi_cand_user_idx` (`user_id`),
  KEY `mi_cand_mode_idx` (`attending_mode`),
  KEY `mi_cand_stat_idx` (`status`),
  CONSTRAINT `fk_mi_cand_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_cand_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_cand_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_cand_deleted` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_cand_sess` FOREIGN KEY (`mock_interview_session_id`) REFERENCES `mock_interview_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mi_cand_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_cand_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mock_interview_candidates`
--

LOCK TABLES `mock_interview_candidates` WRITE;
/*!40000 ALTER TABLE `mock_interview_candidates` DISABLE KEYS */;
/*!40000 ALTER TABLE `mock_interview_candidates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mock_interview_invitation_logs`
--

DROP TABLE IF EXISTS `mock_interview_invitation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mock_interview_invitation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mock_interview_session_id` bigint unsigned NOT NULL,
  `mock_interview_candidate_id` bigint unsigned DEFAULT NULL,
  `mock_interview_session_member_id` bigint unsigned DEFAULT NULL,
  `recipient_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invitation',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `sent_by` bigint unsigned DEFAULT NULL,
  `sent_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_mi_log_cand` (`mock_interview_candidate_id`),
  KEY `fk_mi_log_smem` (`mock_interview_session_member_id`),
  KEY `fk_mi_log_user` (`user_id`),
  KEY `fk_mi_log_sent_by` (`sent_by`),
  KEY `mi_logs_recip_idx` (`mock_interview_session_id`,`recipient_type`),
  KEY `mi_logs_chan_stat_idx` (`channel`,`status`),
  CONSTRAINT `fk_mi_log_cand` FOREIGN KEY (`mock_interview_candidate_id`) REFERENCES `mock_interview_candidates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mi_log_sent_by` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_log_sess` FOREIGN KEY (`mock_interview_session_id`) REFERENCES `mock_interview_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mi_log_smem` FOREIGN KEY (`mock_interview_session_member_id`) REFERENCES `mock_interview_session_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mi_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mock_interview_invitation_logs`
--

LOCK TABLES `mock_interview_invitation_logs` WRITE;
/*!40000 ALTER TABLE `mock_interview_invitation_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `mock_interview_invitation_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mock_interview_session_members`
--

DROP TABLE IF EXISTS `mock_interview_session_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mock_interview_session_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mock_interview_session_id` bigint unsigned NOT NULL,
  `interview_board_member_id` bigint unsigned NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `order_index` int unsigned NOT NULL DEFAULT '1',
  `attendance_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `invitation_sent_at` timestamp NULL DEFAULT NULL,
  `invitation_channels` json DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mi_sess_mem_uniq` (`mock_interview_session_id`,`interview_board_member_id`),
  KEY `fk_mi_smem_member` (`interview_board_member_id`),
  KEY `mi_sess_mem_role_idx` (`mock_interview_session_id`,`role`),
  CONSTRAINT `fk_mi_smem_member` FOREIGN KEY (`interview_board_member_id`) REFERENCES `interview_board_members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mi_smem_sess` FOREIGN KEY (`mock_interview_session_id`) REFERENCES `mock_interview_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mock_interview_session_members`
--

LOCK TABLES `mock_interview_session_members` WRITE;
/*!40000 ALTER TABLE `mock_interview_session_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `mock_interview_session_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mock_interview_sessions`
--

DROP TABLE IF EXISTS `mock_interview_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mock_interview_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_number` int unsigned NOT NULL DEFAULT '1',
  `academic_year` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2026',
  `session_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `reporting_time` time DEFAULT NULL,
  `venue_address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `room_number` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_interview_duration` int unsigned NOT NULL DEFAULT '30',
  `default_buffer_duration` int unsigned NOT NULL DEFAULT '5',
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `chairman_invitation_template` text COLLATE utf8mb4_unicode_ci,
  `member_invitation_template` text COLLATE utf8mb4_unicode_ci,
  `student_invitation_template` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mock_interview_sessions_session_code_unique` (`session_code`),
  KEY `fk_mi_sess_created` (`created_by`),
  KEY `fk_mi_sess_updated` (`updated_by`),
  KEY `fk_mi_sess_deleted` (`deleted_by`),
  KEY `mi_sessions_date_stat_idx` (`session_date`,`status`),
  KEY `mi_sessions_year_idx` (`academic_year`),
  KEY `mi_sessions_num_idx` (`session_number`),
  CONSTRAINT `fk_mi_sess_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_sess_deleted` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mi_sess_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mock_interview_sessions`
--

LOCK TABLES `mock_interview_sessions` WRITE;
/*!40000 ALTER TABLE `mock_interview_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `mock_interview_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (1,'App\\Models\\User',2),(8,'App\\Models\\User',3),(8,'App\\Models\\User',4),(8,'App\\Models\\User',5),(8,'App\\Models\\User',6),(8,'App\\Models\\User',7),(8,'App\\Models\\User',8),(8,'App\\Models\\User',9),(8,'App\\Models\\User',10),(8,'App\\Models\\User',11),(8,'App\\Models\\User',12),(8,'App\\Models\\User',13),(8,'App\\Models\\User',14),(8,'App\\Models\\User',15),(8,'App\\Models\\User',16),(8,'App\\Models\\User',17);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `optional_subject_change_requests`
--

DROP TABLE IF EXISTS `optional_subject_change_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `optional_subject_change_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `current_subject` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_subject` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `optional_subject_id` bigint unsigned DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_remarks` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `optional_subject_change_requests_request_no_unique` (`request_no`),
  KEY `optional_subject_change_requests_user_id_foreign` (`user_id`),
  KEY `optional_subject_change_requests_optional_subject_id_foreign` (`optional_subject_id`),
  KEY `optional_subject_change_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `optional_subject_change_requests_created_by_foreign` (`created_by`),
  KEY `optional_subject_change_requests_updated_by_foreign` (`updated_by`),
  KEY `optional_subject_change_requests_deleted_by_foreign` (`deleted_by`),
  KEY `optional_subject_change_requests_status_index` (`status`),
  CONSTRAINT `optional_subject_change_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subject_change_requests_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subject_change_requests_optional_subject_id_foreign` FOREIGN KEY (`optional_subject_id`) REFERENCES `optional_subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subject_change_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subject_change_requests_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subject_change_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `optional_subject_change_requests`
--

LOCK TABLES `optional_subject_change_requests` WRITE;
/*!40000 ALTER TABLE `optional_subject_change_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `optional_subject_change_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `optional_subjects`
--

DROP TABLE IF EXISTS `optional_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `optional_subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Humanities & Social Sciences',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `max_capacity` int unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `optional_subjects_name_unique` (`name`),
  UNIQUE KEY `optional_subjects_code_unique` (`code`),
  KEY `optional_subjects_created_by_foreign` (`created_by`),
  KEY `optional_subjects_updated_by_foreign` (`updated_by`),
  KEY `optional_subjects_deleted_by_foreign` (`deleted_by`),
  KEY `optional_subjects_is_active_index` (`is_active`),
  CONSTRAINT `optional_subjects_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subjects_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `optional_subjects_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `optional_subjects`
--

LOCK TABLES `optional_subjects` WRITE;
/*!40000 ALTER TABLE `optional_subjects` DISABLE KEYS */;
INSERT INTO `optional_subjects` VALUES (1,'Agriculture','AGRI','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Agriculture.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(2,'Animal Husbandry and Veterinary Science','AHVS','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Animal Husbandry and Veterinary Science.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(3,'Anthropology','ANTH','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Anthropology.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(4,'Botany','BOT','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Botany.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(5,'Chemistry','CHEM','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Chemistry.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(6,'Civil Engineering','CE','Engineering','Official UPSC Civil Services Examination Optional Subject - Civil Engineering.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(7,'Commerce and Accountancy','COMM','Commerce & Management','Official UPSC Civil Services Examination Optional Subject - Commerce and Accountancy.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(8,'Economics','ECON','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Economics.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(9,'Electrical Engineering','EE','Engineering','Official UPSC Civil Services Examination Optional Subject - Electrical Engineering.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(10,'Geography','GEOG','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Geography.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(11,'Geology','GEOL','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Geology.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(12,'History','HIST','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - History.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(13,'Law','LAW','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Law.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(14,'Literature of Assamese Language','LIT-ASM','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Assamese Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(15,'Literature of Bengali Language','LIT-BEN','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Bengali Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(16,'Literature of Bodo Language','LIT-BOD','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Bodo Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(17,'Literature of Dogri Language','LIT-DOG','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Dogri Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(18,'Literature of English Language','LIT-ENG','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of English Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(19,'Literature of Gujarati Language','LIT-GUJ','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Gujarati Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(20,'Literature of Hindi Language','LIT-HIN','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Hindi Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(21,'Literature of Kannada Language','LIT-KAN','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Kannada Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(22,'Literature of Kashmiri Language','LIT-KAS','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Kashmiri Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(23,'Literature of Konkani Language','LIT-KON','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Konkani Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(24,'Literature of Maithili Language','LIT-MAI','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Maithili Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(25,'Literature of Malayalam Language','LIT-MAL','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Malayalam Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(26,'Literature of Manipuri Language','LIT-MAN','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Manipuri Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(27,'Literature of Marathi Language','LIT-MAR','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Marathi Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(28,'Literature of Nepali Language','LIT-NEP','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Nepali Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(29,'Literature of Odia Language','LIT-ODI','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Odia Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(30,'Literature of Punjabi Language','LIT-PUN','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Punjabi Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(31,'Literature of Sanskrit Language','LIT-SKT','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Sanskrit Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(32,'Literature of Santhali Language','LIT-SNT','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Santhali Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(33,'Literature of Sindhi Language','LIT-SND','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Sindhi Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(34,'Literature of Tamil Language','LIT-TAM','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Tamil Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(35,'Literature of Telugu Language','LIT-TEL','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Telugu Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(36,'Literature of Urdu Language','LIT-URD','Literature of Languages','Official UPSC Civil Services Examination Optional Subject - Literature of Urdu Language.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(37,'Management','MGMT','Commerce & Management','Official UPSC Civil Services Examination Optional Subject - Management.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(38,'Mathematics','MATH','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Mathematics.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(39,'Mechanical Engineering','ME','Engineering','Official UPSC Civil Services Examination Optional Subject - Mechanical Engineering.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(40,'Medical Science','MED','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Medical Science.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(41,'Philosophy','PHIL','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Philosophy.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(42,'Physics','PHYS','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Physics.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(43,'Political Science and International Relations','PSIR','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Political Science and International Relations.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(44,'Psychology','PSYC','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Psychology.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(45,'Public Administration','PUBAD','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Public Administration.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(46,'Sociology','SOC','Humanities & Social Sciences','Official UPSC Civil Services Examination Optional Subject - Sociology.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(47,'Statistics','STAT','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Statistics.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(48,'Zoology','ZOOL','Science & Mathematics','Official UPSC Civil Services Examination Optional Subject - Zoology.',1,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `optional_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_codes`
--

DROP TABLE IF EXISTS `otp_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `identifier` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL DEFAULT '0',
  `max_attempts` smallint unsigned NOT NULL DEFAULT '5',
  `expires_at` timestamp NOT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `otp_codes_user_id_foreign` (`user_id`),
  KEY `otp_codes_identifier_type_verified_at_index` (`identifier`,`type`,`verified_at`),
  KEY `otp_codes_identifier_index` (`identifier`),
  KEY `otp_codes_type_index` (`type`),
  KEY `otp_codes_expires_at_index` (`expires_at`),
  KEY `otp_codes_verified_at_index` (`verified_at`),
  CONSTRAINT `otp_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_codes`
--

LOCK TABLES `otp_codes` WRITE;
/*!40000 ALTER TABLE `otp_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `page_translations`
--

DROP TABLE IF EXISTS `page_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `page_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `page_id` bigint unsigned NOT NULL,
  `locale` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` text COLLATE utf8mb4_unicode_ci,
  `meta_keywords` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page_translations_page_id_locale_unique` (`page_id`,`locale`),
  KEY `page_translations_created_by_foreign` (`created_by`),
  KEY `page_translations_updated_by_foreign` (`updated_by`),
  KEY `page_translations_deleted_by_foreign` (`deleted_by`),
  KEY `page_translations_locale_index` (`locale`),
  CONSTRAINT `page_translations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `page_translations_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `page_translations_page_id_foreign` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `page_translations_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `page_translations`
--

LOCK TABLES `page_translations` WRITE;
/*!40000 ALTER TABLE `page_translations` DISABLE KEYS */;
INSERT INTO `page_translations` VALUES (1,1,'en','About Us','About Us — Satyendranath Tagore Civil Services Study Centre','Learn about Satyendranath Tagore Civil Services Study Centre (SNT CSSC), our mission, state-of-the-art coaching facilities, and dedication to mentoring civil service aspirants.','about snt cssc, civil services coaching, upsc west bengal, upsc mentorship, sntcssc mis','<h2>Welcome to Satyendranath Tagore Civil Services Study Centre</h2>\n<p>The <strong>Satyendranath Tagore Civil Services Study Centre (SNTCSSC)</strong> was established with the vision of nurturing and mentoring promising candidates from West Bengal and across India to achieve distinction in the UPSC Civil Services Examination and state civil services.</p>\n\n<h3>Our Core Mission</h3>\n<p>Our mission is to democratize quality civil services preparation through structured classroom programs, comprehensive test series, personalized answer evaluation, and mentorship from veteran bureaucrats, academicians, and subject matter experts.</p>\n\n<h3>What We Offer</h3>\n<ul>\n    <li><strong>Expert Faculty & Bureaucratic Mentorship:</strong> Direct guidance from serving and retired IAS, IPS, and WBCS officers alongside distinguished academicians.</li>\n    <li><strong>Modern Digital Infrastructure:</strong> Fully integrated Management Information System (MIS), computer labs, digital library access, and performance analytics.</li>\n    <li><strong>Rigorous Evaluation Framework:</strong> Weekly Prelims mock drills, Mains descriptive answer reviews, and simulated personality test interview boards.</li>\n    <li><strong>Inclusive & Accessible Education:</strong> Subsidized fee structures and scholarship opportunities for meritorious students from all socio-economic backgrounds.</li>\n</ul>\n\n<h3>Governance & Academic Excellence</h3>\n<p>SNTCSSC operates with high institutional governance standards, ensuring transparency in admissions, automated attendance tracking, detailed candidate dossiers, and continuous curriculum updates aligned with changing UPSC patterns.</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,1,'hi','हमारे बारे में','हमारे बारे में — सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र','सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र (SNTCSSC) के उद्देश्य, उच्च स्तरीय कोचिंग सुविधाओं और सिविल सेवा अभ्यर्थियों के मार्गदर्शन के बारे में जानें।','SNTCSSC के बारे में, यूपीएससी कोचिंग, सिविल सेवा तैयारी, सत्येंद्रनाथ टैगोर केंद्र','<h2>सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र में आपका स्वागत है</h2>\n<p><strong>सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र (SNTCSSC)</strong> की स्थापना पश्चिम बंगाल एवं सम्पूर्ण भारत के प्रतिभावान अभ्यर्थियों को संघ लोक सेवा आयोग (UPSC) सिविल सेवा परीक्षा एवं राज्य प्रशासनिक सेवाओं में सफलता हेतु मार्गदर्शन प्रदान करने के लिए की गई है।</p>\n\n<h3>हमारा मुख्य उद्देश्य</h3>\n<p>हमारा उद्देश्य उच्च स्तरीय एवं सुनियोजित शैक्षणिक मार्गदर्शन, गुणवत्तापूर्ण अध्ययन सामग्री, और अनुभवी प्रशासकों व प्राध्यापकों के सान्निध्य में उत्कृष्ट तैयारी सुनिश्चित करना है।</p>\n\n<h3>हमारी मुख्य विशेषताएँ</h3>\n<ul>\n    <li><strong>विशेषज्ञ संकाय एवं प्रशासनिक मार्गदर्शन:</strong> कार्यरत एवं सेवानिवृत्त आईएएस, आईपीएस अधिकारियों और विषय विशेषज्ञों द्वारा नियमित मार्गदर्शन।</li>\n    <li><strong>आधुनिक डिजिटल ढांचा:</strong> एकीकृत प्रबंधन सूचना प्रणाली (MIS), डिजिटल लाइब्रेरी एवं व्यक्तिगत प्रदर्शन विश्लेषण।</li>\n    <li><strong>नियमित मूल्यांकन प्रणाली:</strong> साप्ताहिक प्रारंभिक मॉक टेस्ट, मुख्य परीक्षा उत्तर लेखन मूल्यांकन एवं साक्षात्कार मार्गदर्शन।</li>\n    <li><strong>समावेशी एवं सुलभ शिक्षा:</strong> मेधावी अभ्यर्थियों के लिए रियायती शुल्क एवं छात्रवृत्ति की सुविधा।</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,1,'bn','আমাদের সম্পর্কে','আমাদের সম্পর্কে — সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার','সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার (SNTCSSC)-এর লক্ষ্য, দৃষ্টিভঙ্গি এবং সিভিল সার্ভিস প্রার্থীদের জন্য আধুনিক প্রশিক্ষণ ব্যবস্থা সম্পর্কে জানুন।','আমাদের সম্পর্কে, SNTCSSC, ইউপিএসসি কোচিং, সিভিল সার্ভিস প্রস্তুতি, পশ্চিমবঙ্গ','<h2>সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টারে আপনাকে স্বাগতম</h2>\n<p><strong>সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার (SNTCSSC)</strong> পশ্চিমবঙ্গ তথা সমগ্র ভারতের সিভিল সার্ভিস পরীক্ষার্থীদের ইউপিএসসি ও রাজ্য প্রশাসনিক পরীক্ষার জন্য উচ্চমানের প্রস্তুতি ও দিকনির্দেশনা প্রদানের লক্ষ্যে প্রতিষ্ঠিত একটি শীর্ষস্থানীয় প্রতিষ্ঠান।</p>\n\n<h3>আমাদের মূল লক্ষ্য</h3>\n<p>আমাদের উদ্দেশ্য হল সুসংগঠিত ক্লাসরুম প্রশিক্ষণ, অভিজ্ঞ আমলা ও শিক্ষাবিদদের তত্ত্বাবধান, এবং আধুনিক ডিজিটাল ব্যবস্থার মাধ্যমে শিক্ষার্থীদের সিভিল সার্ভিসের কঠিন যাত্রায় সফল করে তোলা।</p>\n\n<h3>আমাদের প্রধান সুবিধাসমূহ</h3>\n<ul>\n    <li><strong>অভিজ্ঞ অনুষদ ও প্রশাসনিক পরামর্শদাতা:</strong> প্রাক্তন ও কর্মরত আইএএস, আইপিএস এবং শিক্ষাবিদদের প্রত্যক্ষ তত্ত্বাবধান।</li>\n    <li><strong>আধুনিক ডিজিটাল এমআইএস পোর্টাল:</strong> অনলাইন ভর্তি, উপস্থিতি ট্র্যাকিং, টেস্ট সিরিজ রেজাল্ট এবং ডিজিটাল লাইব্রেরি।</li>\n    <li><strong>নিয়মিত টেস্ট সিরিজ ও মূল্যায়ন:</strong> প্রিলিমিনারি ও মেইনস পরীক্ষার জন্য নিয়মিত মক টেস্ট এবং খাতা মূল্যায়ন।</li>\n    <li><strong>সাশ্রয়ী ও অন্তর্ভুক্তিমূলক শিক্ষা:</strong> মেধাবী ছাত্রছাত্রীদের জন্য স্কলারশিপ এবং সুলভ শিক্ষাদান ব্যবস্থা।</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,2,'en','Privacy Policy','Privacy Policy — SNT CSSC Management Information System','Privacy Policy of SNT CSSC MIS explaining data collection, protection, cookies, and user rights.','privacy policy, sntcssc privacy, data protection, student data privacy','<h2>Privacy Policy</h2>\n<p class=\"text-sm text-muted-foreground\">Last updated: August 2026</p>\n\n<p>Satyendranath Tagore Civil Services Study Centre (SNTCSSC) is dedicated to protecting the privacy and security of all candidates, staff, and visitors who access our portal and services.</p>\n\n<h3>1. Information We Collect</h3>\n<ul>\n    <li><strong>Personal Information:</strong> Full name, date of birth, gender, category, residential address, mobile phone number, WhatsApp contact, and email address.</li>\n    <li><strong>Academic & Identity Records:</strong> Educational qualifications, marksheets, caste/category certificates, photograph, and identity proof documents during admission.</li>\n    <li><strong>System Logs & Analytics:</strong> IP address, device specifications, browser type, login timestamp, and audit trail events.</li>\n</ul>\n\n<h3>2. How We Use Your Information</h3>\n<p>Collected information is used exclusively for:</p>\n<ul>\n    <li>Processing admission applications, screening tests, and enrollment verifications.</li>\n    <li>Issuing admit cards, student ID cards, fee receipts, and test performance reports.</li>\n    <li>Dispatching official communications, lecture schedules, OTP verifications, and system alerts via SMS/Email.</li>\n    <li>Maintaining statutory compliance, audit integrity, and institutional safety.</li>\n</ul>\n\n<h3>3. Data Protection & Security</h3>\n<p>All sensitive records and credentials are encrypted at rest using industry-standard cryptography. Access to personal candidate data is restricted strictly to authorized institutional officials under strict role-based access control (RBAC).</p>\n\n<h3>4. Third-Party Sharing</h3>\n<p>We do <strong>not</strong> sell, rent, or trade your personal information. Data may only be shared with authorized payment gateway partners (e.g., Razorpay/PhonePe) for secure transaction processing or when required by statutory regulatory bodies.</p>\n\n<h3>5. Contact Us Regarding Privacy</h3>\n<p>For any queries or grievances regarding data privacy, contact our Data Protection Officer at <code>privacy@sntcssc.in</code>.</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,2,'hi','गोपनीयता नीति','गोपनीयता नीति — सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र','SNTCSSC पोर्टल की गोपनीयता नीति और डेटा सुरक्षा सम्बन्धी नियम।','गोपनीयता नीति, डेटा सुरक्षा, SNTCSSC privacy policy','<h2>गोपनीयता नीति (Privacy Policy)</h2>\n<p>सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र (SNTCSSC) अपने सभी छात्रों एवं पोर्टल उपयोगकर्ताओं की व्यक्तिगत जानकारी की सुरक्षा एवं गोपनीयता बनाए रखने के लिए प्रतिबद्ध है।</p>\n\n<h3>1. एकत्रित की जाने वाली जानकारी</h3>\n<p>प्रवेश प्रक्रिया एवं पोर्टल उपयोग के दौरान हम नाम, ईमेल, मोबाइल नंबर, शैक्षणिक योग्यता एवं पहचान प्रमाण पत्र जैसी आवश्यक जानकारी एकत्र करते हैं।</p>\n\n<h3>2. जानकारी का उपयोग</h3>\n<p>एकत्रित डेटा का उपयोग केवल प्रवेश सत्यापन, परीक्षा आयोजन, प्रवेश पत्र वितरण एवं महत्वपूर्ण सूचनाएँ प्रेषित करने हेतु किया जाता है।</p>\n\n<h3>3. डेटा सुरक्षा</h3>\n<p>हमारा सिस्टम आधुनिक एन्क्रिप्शन तकनीकों द्वारा संरक्षित है एवं अनाधिकृत पहुंच को रोकने हेतु कठोर सुरक्षा उपायों का पालन किया जाता है।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(6,2,'bn','গোপনীয়তা নীতি','গোপনীয়তা নীতি — SNTCSSC MIS','SNTCSSC পোর্টালের গোপনীয়তা নীতি এবং ডেটা সুরক্ষা সংক্রান্ত নির্দেশাবলী।','গোপনীয়তা নীতি, ডেটা সুরক্ষা, SNTCSSC privacy policy','<h2>গোপনীয়তা নীতি (Privacy Policy)</h2>\n<p>সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টার শিক্ষার্থীদের সমস্ত ব্যক্তিগত তথ্যের নিরাপত্তা ও গোপনীয়তা বজায় রাখতে দৃঢ় প্রতিশ্রুতিবদ্ধ।</p>\n\n<h3>১. সংগৃহীত তথ্যাবলী</h3>\n<p>ভর্তি প্রক্রিয়া, পরীক্ষা ও যোগাযোগের জন্য আমরা নাম, ঠিকানা, ইমেইল, ফোন নম্বর এবং শিক্ষাগত যোগ্যতার প্রমাণাদি সংগ্রহ করি।</p>\n\n<h3>২. তথ্যের ব্যবহার</h3>\n<p>এই তথ্যগুলি কেবলমাত্র প্রাতিষ্ঠানিক কার্যাবলী, পরীক্ষার নোটিশ, অ্যাডমিট কার্ড প্রদান ও অভ্যন্তরীণ রেকর্ডের জন্য ব্যবহৃত হয়।</p>\n\n<h3>৩. তথ্যের নিরাপত্তা</h3>\n<p>শিক্ষার্থীদের সমস্ত ডেটা আধুনিক এনক্রিপশন প্রযুক্তির মাধ্যমে সুরক্ষিত থাকে এবং কোনও তৃতীয় পক্ষের সাথে বাণিজ্যিক উদ্দেশ্যে শেয়ার করা হয় না।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(7,3,'en','Terms and Conditions','Terms and Conditions — SNTCSSC Academic MIS','Terms and Conditions governing the use of SNTCSSC MIS portal, courses, admissions, and campus rules.','terms and conditions, sntcssc terms, student guidelines, code of conduct','<h2>Terms and Conditions of Use</h2>\n<p class=\"text-sm text-muted-foreground\">Effective Date: August 2026</p>\n\n<h3>1. Acceptance of Terms</h3>\n<p>By accessing this Management Information System (MIS) or enrolling in any academic program of Satyendranath Tagore Civil Services Study Centre, you agree to be bound by these Terms and Conditions and all applicable regulations.</p>\n\n<h3>2. Student Account & Credential Responsibility</h3>\n<ul>\n    <li>Candidates are responsible for maintaining the strict confidentiality of their portal credentials, OTPs, and passkeys.</li>\n    <li>Sharing credentials or permitting unauthorized access to proprietary study materials and test series will lead to immediate cancellation of admission and portal access.</li>\n</ul>\n\n<h3>3. Academic Code of Conduct</h3>\n<ul>\n    <li>Strict academic integrity is required during all mock examinations and evaluation tests. Malpractice or plagiarism will result in disqualification.</li>\n    <li>Minimum 75% classroom attendance is mandatory to remain eligible for sponsored test series and mock interview panels.</li>\n    <li>Disciplinary decorum must be maintained across physical campus premises as well as digital discussion forums.</li>\n</ul>\n\n<h3>4. Fee Payment & Installments</h3>\n<p>All program fees must be cleared within designated due dates. Failure to settle installments may result in temporary suspension of digital batch access and study material distribution.</p>\n\n<h3>5. Governing Law & Jurisdiction</h3>\n<p>These terms shall be governed by and construed in accordance with the laws of the State of West Bengal, India. Any disputes arising hereunder shall be subject to the exclusive jurisdiction of the competent courts in Kolkata.</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(8,3,'hi','नियम एवं शर्तें','नियम एवं शर्तें — सत्येंद्रनाथ टैगोर सिविल सेवा अध्ययन केंद्र','SNTCSSC पोर्टल और अध्ययन कार्यक्रमों के उपयोग सम्बन्धी नियम एवं शर्तें।','नियम और शर्तें, sntcssc terms and conditions','<h2>नियम एवं शर्तें (Terms and Conditions)</h2>\n<p>SNTCSSC पोर्टल का उपयोग करने एवं हमारे किसी भी पाठ्यक्रम में प्रवेश लेने पर आप निम्नलिखित नियमों एवं शर्तों से बाध्य होंगे।</p>\n\n<h3>१. छात्र आचार संहिता</h3>\n<ul>\n    <li>सभी विद्यार्थियों को अध्ययन एवं परीक्षाओं के दौरान अनुशासन का पालन करना अनिवार्य है।</li>\n    <li>पोर्टल लॉगिन पासवर्ड या ओटीपी किसी अन्य व्यक्ति के साथ साझा करना पूर्णतः वर्जित है।</li>\n    <li>कक्षाओं में न्यूनतम उपस्थिति प्रतिशत बनाए रखना आवश्यक है।</li>\n</ul>\n\n<h3>२. शुल्क भुगतान</h3>\n<p>निर्धारित तिथि तक पाठ्यक्रम शुल्क का भुगतान अनिवार्य है।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(9,3,'bn','নিয়ম ও শর্তাবলী','নিয়ম ও শর্তাবলী — SNTCSSC MIS','SNTCSSC পোর্টাল এবং একাডেমিক কোর্স ব্যবহারের নিয়ম ও শর্তাবলী।','নিয়ম ও শর্তাবলী, terms and conditions sntcssc','<h2>নিয়ম ও শর্তাবলী (Terms and Conditions)</h2>\n<p>এই পোর্টাল ব্যবহার এবং SNTCSSC-এর যেকোনো কোর্সে ভর্তির ক্ষেত্রে নিচের নিয়মাবলী প্রযোজ্য হবে।</p>\n\n<h3>১. ছাত্র আচরণবিধি ও শৃঙ্খলা</h3>\n<ul>\n    <li>পরীক্ষা এবং ক্লাসরুমে পূর্ণ শৃঙ্খলা বজায় রাখা আবশ্যিক।</li>\n    <li>ব্যক্তিগত আইডি এবং পাসওয়ার্ড অন্য কারও সাথে আদান-প্রদান করা সম্পূর্ণ নিষিদ্ধ।</li>\n    <li>ক্লাসে নিয়মিত উপস্থিতি বজায় রাখা বাধ্যতামূলক।</li>\n</ul>\n\n<h3>২. আইনি এখতিয়ার</h3>\n<p>যেকোনো প্রকার আইনি বিরোধের ক্ষেত্রে কলকাতার উপযুক্ত আদালতের বিচারিক এখতিয়ার প্রযোজ্য হবে।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(10,4,'en','Refund and Cancellation Policy','Refund & Cancellation Policy — SNT CSSC','Refund and cancellation policy guidelines for course admissions, examination fees, and registration charges.','refund policy, cancellation policy, fee refund sntcssc, admission cancellation','<h2>Refund and Cancellation Policy</h2>\n\n<p>Satyendranath Tagore Civil Services Study Centre maintains a clear and transparent policy regarding fee refunds, withdrawals, and course cancellations.</p>\n\n<h3>1. Admission & Registration Fees</h3>\n<ul>\n    <li>The initial Registration / Application Processing fee is <strong>strictly non-refundable</strong> under all circumstances as it covers administrative processing and entrance test overheads.</li>\n    <li>Admission fees for finalized selection lists are subject to the schedule detailed below.</li>\n</ul>\n\n<h3>2. Course Fee Refund Schedule</h3>\n<table>\n    <thead>\n        <tr>\n            <th>Time of Cancellation Request</th>\n            <th>Refund Eligibility</th>\n        </tr>\n    </thead>\n    <tbody>\n        <tr>\n            <td>Written request received 7 or more days prior to batch commencement</td>\n            <td>80% of Tuition Fee refunded (20% administrative fee retained)</td>\n        </tr>\n        <tr>\n            <td>Written request received within 7 days before batch commencement</td>\n            <td>50% of Tuition Fee refunded</td>\n        </tr>\n        <tr>\n            <td>Request received after batch commencement date</td>\n            <td>No refund admissible</td>\n        </tr>\n    </tbody>\n</table>\n\n<h3>3. Mode and Timeline of Refund</h3>\n<p>All approved refunds will be credited back to the original payment source (bank account / card / UPI) within <strong>10 to 14 working days</strong> from the formal approval of the cancellation request.</p>\n\n<h3>4. Processing Cancellation Requests</h3>\n<p>Candidates must submit their refund requests in writing to the Accounts Section via email at <code>accounts@sntcssc.in</code> along with the transaction reference, admission number, and valid identification proof.</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(11,4,'hi','रिफंड एवं रद्दीकरण नीति','रिफंड एवं रद्दीकरण नीति — सत्येंद्रनाथ टैगोर केंद्र','प्रवेश शुल्क रद्दीकरण एवं रिफंड सम्बन्धी दिशा-निर्देश।','रिफंड नीति, शुल्क वापसी, refund policy sntcssc','<h2>रिफंड एवं रद्दीकरण नीति (Refund & Cancellation)</h2>\n<p>प्रवेश रद्दीकरण एवं शुल्क वापसी के संबंध में संस्थान के नियम निम्नलिखित हैं:</p>\n\n<ul>\n    <li><strong>आवेदन शुल्क:</strong> प्रवेश परीक्षा एवं पंजीकरण शुल्क गैर-वापसी योग्य (Non-refundable) है।</li>\n    <li><strong>पाठ्यक्रम शुल्क:</strong> बैच प्रारंभ होने से ७ दिन पूर्व लिखित आवेदन देने पर प्रशासनिक कटौती के पश्चात आंशिक शुल्क वापस किया जा सकता है।</li>\n    <li><strong>बैच प्रारंभ के बाद:</strong> कक्षाएं प्रारंभ होने के पश्चात कोई रिफंड स्वीकार्य नहीं होगा।</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(12,4,'bn','রিফান্ড এবং বাতিলকরণ নীতি','রিফান্ড এবং বাতিলকরণ নীতি — SNTCSSC','ভর্তি ফি বাতিল ও রিফান্ড সংক্রান্ত নির্দেশিকা।','রিফান্ড পলিসি, ফি ফেরত, sntcssc refund policy','<h2>রিফান্ড এবং বাতিলকরণ নীতি (Refund Policy)</h2>\n<p>কোর্স বাতিল এবং ফি ফেরতের ক্ষেত্রে নিম্নোক্ত নিয়মসমূহ প্রযোজ্য:</p>\n\n<ul>\n    <li><strong>রেজিস্ট্রেশন ফি:</strong> আবেদন ও রেজিস্ট্রেশন ফি কোনো অবস্থাতেই অফেরতযোগ্য।</li>\n    <li><strong>কোর্স ফি ফেরত:</strong> ব্যাচ শুরু হওয়ার ৭ দিন পূর্বে লিখিত আবেদন জানালে প্রশাসনিক খরচ বাদে ফি ফেরত বিবেচনা করা হবে।</li>\n    <li><strong>ক্লাস শুরুর পর:</strong> ব্যাচের ক্লাস শুরু হওয়ার পর কোনো প্রকার ফি ফেরত দেওয়া হবে না।</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(13,5,'en','Legal Disclaimer','Legal Disclaimer — Satyendranath Tagore Civil Services Study Centre','Legal disclaimer regarding information accuracy, external links, third-party content, and educational coaching warranties.','legal disclaimer, sntcssc disclaimer, academic disclaimer','<h2>Legal Disclaimer</h2>\n\n<p>The information, study materials, mock question papers, and guidance provided on this portal and in our coaching programs are intended strictly for educational and training purposes for competitive examinations.</p>\n\n<h3>1. No Guarantee of Selection</h3>\n<p>While Satyendranath Tagore Civil Services Study Centre strives to provide the highest standard of academic mentoring, coaching, and evaluation, we make <strong>no representation or guarantee</strong> that enrollment in our programs will ensure selection or qualification in the UPSC Civil Services Examination or any competitive examination.</p>\n\n<h3>2. Accuracy of Content</h3>\n<p>Every reasonable effort is made to maintain accurate and up-to-date syllabus guides, examination notifications, and study resources. However, official guidelines, eligibility norms, and cut-off criteria published by the Union Public Service Commission (UPSC) or respective State Public Service Commissions shall always supersede any institutional material.</p>\n\n<h3>3. System Availability</h3>\n<p>SNTCSSC makes every effort to keep the MIS portal operational 24/7. However, we take no responsibility for temporary portal unavailability resulting from scheduled technical maintenance, telecommunication failures, or server disruptions beyond our reasonable control.</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(14,5,'hi','कानूनी अस्वीकरण','कानूनी अस्वीकरण — SNTCSSC','संस्थान की अध्ययन सामग्री एवं चयन सम्बन्धी कानूनी अस्वीकरण।','कानूनी अस्वीकरण, legal disclaimer sntcssc','<h2>कानूनी अस्वीकरण (Legal Disclaimer)</h2>\n<p>इस पोर्टल पर उपलब्ध अध्ययन सामग्री और मार्गदर्शन केवल प्रतियोगी परीक्षाओं की तैयारी एवं शैक्षणिक उद्देश्य हेतु है।</p>\n<ul>\n    <li>संस्थान में प्रवेश किसी भी सरकारी सेवा या यूपीएससी परीक्षा में अंतिम चयन की गारंटी नहीं देता है।</li>\n    <li>आधिकारिक सूचनाओं के लिए संघ लोक सेवा आयोग (UPSC) की आधिकारिक अधिसूचना ही सर्वमान्य होगी।</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(15,5,'bn','আইনি দাবিত্যাগ','আইনি দাবিত্যাগ — SNTCSSC','শিক্ষামূলক তথ্য ও পরীক্ষা সংক্রান্ত আইনি দাবিত্যাগ।','আইনি দাবিত্যাগ, disclaimer sntcssc','<h2>আইনি দাবিত্যাগ (Legal Disclaimer)</h2>\n<p>এই পোর্টালে প্রদত্ত সমস্ত তথ্য এবং স্টাডি মেটেরিয়াল কেবল শিক্ষার্থীদের শিক্ষামূলক প্রস্তুতির উদ্দেশ্যে প্রদান করা হয়েছে।</p>\n<ul>\n    <li>আমাদের কোনো কোর্সে ভর্তি ইউপিএসসি বা সরকারি চাকরিতে চূড়ান্ত নির্বাচনের কোনো নিশ্চয়তা দেয় না।</li>\n    <li>পরীক্ষার নিয়মাবলীর জন্য ইউপিএসসি-র অফিশিয়াল বিজ্ঞপ্তিই চূড়ান্ত বলে গণ্য হবে।</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(16,6,'en','Copyright Policy','Copyright Policy — SNT CSSC All Rights Reserved','Intellectual property rights and copyright policy governing SNT CSSC course modules, test papers, and digital assets.','copyright policy, intellectual property, sntcssc copyright','<h2>Copyright & Intellectual Property Policy</h2>\n\n<p>All contents published on this portal — including but not limited to text, curriculum designs, test series question banks, model answer booklets, evaluation rubrics, graphics, icons, logos, audio/video lectures, software code, and interface styling — are the exclusive intellectual property of <strong>Satyendranath Tagore Civil Services Study Centre</strong>, unless explicitly credited to external third-party sources.</p>\n\n<h3>Permitted Use</h3>\n<ul>\n    <li>Enrolled students are granted a limited, personal, non-exclusive, non-transferable license to access and view materials solely for their own individual preparation.</li>\n    <li>Single copies of designated PDF notes may be downloaded for personal offline revision.</li>\n</ul>\n\n<h3>Prohibited Acts</h3>\n<ul>\n    <li>Commercial reproduction, redistribution, syndication, or re-publishing of any question bank or study module is strictly prohibited.</li>\n    <li>Recording, screen-capturing, or uploading faculty lectures to external video sharing platforms (YouTube, Telegram, Torrent, etc.) constitutes an infringement punishable under the Indian Copyright Act, 1957.</li>\n</ul>\n\n<p>For licensing or permission queries, contact: <code>copyright@sntcssc.in</code>.</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(17,6,'hi','कॉपीराइट नीति','कॉपीराइट नीति — सत्येंद्रनाथ टैगोर केंद्र','बौद्धिक संपदा अधिकार एवं अध्ययन सामग्री की कॉपीराइट नीति।','कॉपीराइट नीति, copyright policy sntcssc','<h2>कॉपीराइट नीति (Copyright Policy)</h2>\n<p>इस पोर्टल पर उपलब्ध सभी अध्ययन सामग्री, टेस्ट सीरीज एवं वीडियो व्याख्यान संस्थान की बौद्धिक संपदा हैं।</p>\n<p>सामग्री का किसी भी प्रकार से वाणिज्यिक पुनरुत्पादन, टेलीग्राम या सोशल मीडिया पर अवैध वितरण कानूनी अपराध है।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(18,6,'bn','কপিরাইট নীতি','কপিরাইট নীতি — SNTCSSC','SNTCSSC-এর স্টাডি মেটেরিয়াল ও ডিজিটাল কনটেন্টের কপিরাইট নির্দেশিকা।','কপিরাইট নীতি, copyright policy sntcssc','<h2>কপিরাইট নীতি (Copyright Policy)</h2>\n<p>এই ওয়েবসাইটের সমস্ত কনটেন্ট, লেকচার নোটস এবং টেস্ট সিরিজ সত্যেন্দ্রনাথ ঠাকুর সিভিল সার্ভিসেস স্টাডি সেন্টারের নিজস্ব সম্পত্তি।</p>\n<p>অনুমতি ছাড়া এই সামগ্রী কোনো সোশ্যাল মিডিয়া বা অন্য কোথাও বাণিজ্যিক উদ্দেশ্যে শেয়ার করা আইনত দণ্ডনীয়।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(19,7,'en','Hyperlink Policy','Hyperlink Policy — Satyendranath Tagore Civil Services Study Centre','Guidelines on linking to the SNTCSSC portal and external websites linked within our services.','hyperlink policy, website linking policy, sntcssc links','<h2>Hyperlink Policy</h2>\n\n<h3>1. Links to External Websites</h3>\n<p>At many places in this portal, you will find links to other government and academic websites (e.g., UPSC Official Portal, Department of Personnel & Training, West Bengal Public Service Commission). These links are provided solely for convenience and research reference.</p>\n<p>SNTCSSC is not responsible for the contents or reliability of linked external websites and does not necessarily endorse the views expressed within them.</p>\n\n<h3>2. Linking to SNT CSSC MIS Portal</h3>\n<p>We welcome links to information hosted on this portal from reputable educational and government organizations, subject to the following conditions:</p>\n<ul>\n    <li>Direct linking to our homepage and public policy pages is permitted without prior explicit authorization.</li>\n    <li>Our web pages must load into an entire newly opened browser window rather than within frames on the referring site.</li>\n    <li>Linking must not imply any sponsorship, endorsement, or commercial affiliation without written consent.</li>\n</ul>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(20,7,'hi','हाइपरलिंक नीति','हाइपरलिंक नीति — SNTCSSC','पोर्टल लिंक करने एवं बाहरी वेबसाइटों के संदर्भ सम्बन्धी नीति।','हाइपरलिंक नीति, hyperlink policy sntcssc','<h2>हाइपरलिंक नीति (Hyperlink Policy)</h2>\n<p>इस पोर्टल पर सरकारी एवं शैक्षणिक संदर्भ हेतु बाहरी वेबसाइटों के लिंक प्रदान किए गए हैं। संस्थान बाहरी वेबसाइटों की सामग्री के लिए उत्तरदायी नहीं है।</p>\n<p>शैक्षणिक संस्थान हमारे पोर्टल के मुख्य पृष्ठ का लिंक अपनी वेबसाइट पर स्वतंत्र रूप से साझा कर सकते हैं।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(21,7,'bn','হাইপারলিংক নীতি','হাইপারলিংক নীতি — SNTCSSC','বহিরাগত লিঙ্ক ও পোর্টাল শেয়ারিং সংক্রান্ত হাইপারলিংক নীতি।','হাইপারলিংক নীতি, hyperlink policy sntcssc','<h2>হাইপারলিংক নীতি (Hyperlink Policy)</h2>\n<p>শিক্ষার্থীদের সুবিধার্থে এই পোর্টালে বিভিন্ন সরকারি ও শিক্ষামূলক ওয়েবসাইটের লিঙ্ক দেওয়া হয়েছে।</p>\n<p>যেকোনো শিক্ষাপ্রতিষ্ঠান আমাদের পোর্টালের লিংক স্বাধীনভাবে তাদের সাইটে শেয়ার করতে পারেন।</p>',NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL);
/*!40000 ALTER TABLE `page_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pages`
--

DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `view_count` bigint unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pages_slug_unique` (`slug`),
  KEY `pages_created_by_foreign` (`created_by`),
  KEY `pages_updated_by_foreign` (`updated_by`),
  KEY `pages_deleted_by_foreign` (`deleted_by`),
  KEY `pages_status_index` (`status`),
  KEY `pages_sort_order_index` (`sort_order`),
  CONSTRAINT `pages_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pages_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pages_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pages`
--

LOCK TABLES `pages` WRITE;
/*!40000 ALTER TABLE `pages` DISABLE KEYS */;
INSERT INTO `pages` VALUES (1,'about-us','published',1,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,'privacy-policy','published',2,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,'terms-and-conditions','published',3,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,'refund-and-cancellation-policy','published',4,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,'legal-disclaimer','published',5,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(6,'copyright-policy','published',6,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(7,'hyperlink-policy','published',7,1,0,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL);
/*!40000 ALTER TABLE `pages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_institute_batches`
--

DROP TABLE IF EXISTS `partner_institute_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_institute_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_institute_id` bigint unsigned NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pi_batches_unique` (`partner_institute_id`,`batch_id`),
  KEY `partner_institute_batches_batch_id_foreign` (`batch_id`),
  CONSTRAINT `partner_institute_batches_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partner_institute_batches_partner_institute_id_foreign` FOREIGN KEY (`partner_institute_id`) REFERENCES `partner_institutes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_institute_batches`
--

LOCK TABLES `partner_institute_batches` WRITE;
/*!40000 ALTER TABLE `partner_institute_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_institute_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_institute_courses`
--

DROP TABLE IF EXISTS `partner_institute_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_institute_courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_institute_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pi_courses_unique` (`partner_institute_id`,`course_id`),
  KEY `partner_institute_courses_course_id_foreign` (`course_id`),
  CONSTRAINT `partner_institute_courses_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partner_institute_courses_partner_institute_id_foreign` FOREIGN KEY (`partner_institute_id`) REFERENCES `partner_institutes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_institute_courses`
--

LOCK TABLES `partner_institute_courses` WRITE;
/*!40000 ALTER TABLE `partner_institute_courses` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_institute_courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_institute_sections`
--

DROP TABLE IF EXISTS `partner_institute_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_institute_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partner_institute_id` bigint unsigned NOT NULL,
  `section_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pi_sections_unique` (`partner_institute_id`,`section_id`),
  KEY `partner_institute_sections_section_id_foreign` (`section_id`),
  CONSTRAINT `partner_institute_sections_partner_institute_id_foreign` FOREIGN KEY (`partner_institute_id`) REFERENCES `partner_institutes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partner_institute_sections_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_institute_sections`
--

LOCK TABLES `partner_institute_sections` WRITE;
/*!40000 ALTER TABLE `partner_institute_sections` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_institute_sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `partner_institutes`
--

DROP TABLE IF EXISTS `partner_institutes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `partner_institutes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `official_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_designation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `official_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partner_institutes_code_unique` (`code`),
  KEY `partner_institutes_created_by_foreign` (`created_by`),
  KEY `partner_institutes_updated_by_foreign` (`updated_by`),
  KEY `partner_institutes_deleted_by_foreign` (`deleted_by`),
  KEY `partner_institutes_status_name_index` (`status`,`name`),
  CONSTRAINT `partner_institutes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `partner_institutes_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `partner_institutes_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `partner_institutes`
--

LOCK TABLES `partner_institutes` WRITE;
/*!40000 ALTER TABLE `partner_institutes` DISABLE KEYS */;
/*!40000 ALTER TABLE `partner_institutes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passkeys`
--

DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `passkeys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credential` json NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  KEY `passkeys_user_id_index` (`user_id`),
  CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passkeys`
--

LOCK TABLES `passkeys` WRITE;
/*!40000 ALTER TABLE `passkeys` DISABLE KEYS */;
/*!40000 ALTER TABLE `passkeys` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_transactions`
--

DROP TABLE IF EXISTS `payment_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_type_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `gateway` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `is_offline` tinyint(1) NOT NULL DEFAULT '0',
  `gateway_order_id` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_payment_id` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_signature` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway_response` json DEFAULT NULL,
  `offline_reference_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `offline_payment_date` date DEFAULT NULL,
  `offline_payment_time` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_remarks` text COLLATE utf8mb4_unicode_ci,
  `proof_document_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `admin_remarks` text COLLATE utf8mb4_unicode_ci,
  `receipt_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_generated_at` datetime DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_transactions_transaction_no_unique` (`transaction_no`),
  UNIQUE KEY `payment_transactions_receipt_no_unique` (`receipt_no`),
  KEY `payment_transactions_invoice_id_foreign` (`invoice_id`),
  KEY `payment_transactions_fee_type_id_foreign` (`fee_type_id`),
  KEY `payment_transactions_verified_by_foreign` (`verified_by`),
  KEY `payment_transactions_created_by_foreign` (`created_by`),
  KEY `payment_transactions_updated_by_foreign` (`updated_by`),
  KEY `payment_transactions_deleted_by_foreign` (`deleted_by`),
  KEY `payment_transactions_user_id_status_index` (`user_id`,`status`),
  KEY `payment_transactions_gateway_status_index` (`gateway`,`status`),
  KEY `payment_transactions_is_offline_status_index` (`is_offline`,`status`),
  KEY `payment_transactions_student_id_index` (`student_id`),
  KEY `payment_transactions_gateway_order_id_index` (`gateway_order_id`),
  KEY `payment_transactions_gateway_payment_id_index` (`gateway_payment_id`),
  KEY `payment_transactions_offline_reference_id_index` (`offline_reference_id`),
  CONSTRAINT `payment_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_fee_type_id_foreign` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `student_fee_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_transactions_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_transactions`
--

LOCK TABLES `payment_transactions` WRITE;
/*!40000 ALTER TABLE `payment_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`),
  KEY `permissions_module_index` (`module`)
) ENGINE=InnoDB AUTO_INCREMENT=242 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'global_search.access','Global Search','Access and execute global search across system modules and permissible records','web','2026-11-01 20:14:45','2026-11-01 20:14:45'),(2,'users.view','Users','View users listing and profile cards','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(3,'users.create','Users','Create new system users and send invitations','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(4,'users.edit','Users','Update user details, identity information, and designations','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(5,'users.delete','Users','Soft-delete and purge system user accounts','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(6,'users.restore','Users','Restore soft-deleted user accounts','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(7,'users.lock','Users','Lock and unlock user login accounts','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(8,'users.export','Users','Export users to Excel, CSV, and PDF','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(9,'users.import','Users','Import batch users from Excel and CSV','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(10,'users.impersonate','Users','Login into any student/user dashboard anonymously','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(11,'roles.view','Roles & Access','View roles and permission matrices','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(12,'roles.create','Roles & Access','Create new custom roles','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(13,'roles.edit','Roles & Access','Modify role definitions and assigned permissions','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(14,'roles.delete','Roles & Access','Delete custom non-system roles','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(15,'roles.assign','Roles & Access','Assign and remove roles from users','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(16,'permissions.manage','Roles & Access','Create and customize system permission keys','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(17,'students.view','Students','View student master list and profile dossiers','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(18,'students.create','Students','Register and enroll new students','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(19,'students.edit','Students','Update student academic and personal information','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(20,'students.delete','Students','Archive and delete student profiles','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(21,'students.export','Students','Export student data and reports','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(22,'students.import','Students','Bulk import students from Excel and CSV','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(23,'enrollments.view','Students','View student course enrollments and history','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(24,'enrollments.manage','Students','Create, update, and change student course enrollments','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(25,'enrollments.delete','Students','Cancel or delete course enrollments','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(26,'enrollments.export','Students','Export course enrollments to Excel, CSV, and PDF','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(27,'enrollments.import','Students','Bulk import course enrollments from Excel and CSV','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(28,'enrollments.discontinue','Students','Directly cancel or discontinue student course enrollments','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(29,'enrollments.reinstate','Students','Revert discontinuation or withdrawal and reinstate students to active enrollment','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(30,'withdrawals.view','Students','View student course withdrawal and discontinuation requests','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(31,'withdrawals.review','Students','Approve or reject student course withdrawal and discontinuation requests','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(32,'students.discipline','Students','Impose disciplinary sanctions, suspensions, or lifetime bans on students','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(33,'students.lift_ban','Students','Revoke or lift student suspensions and bans','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(34,'profile_requests.view','Students','View student profile update correction requests','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(35,'profile_requests.review','Students','Approve or reject student profile update correction requests','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(36,'admissions.view','Admissions','View candidate applications and registrations','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(37,'admissions.review','Admissions','Review, verify, and approve admission applications','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(38,'admissions.manage_tests','Admissions','Schedule entrance tests and publish selection lists','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(39,'admissions.export','Admissions','Export admission candidates and merit lists','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(40,'courses.view','Academics','View academic courses and curriculum','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(41,'courses.manage','Academics','Create and configure academic courses, fees, and rules','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(42,'courses.delete','Academics','Delete academic courses','web','2026-11-01 20:15:00','2026-11-01 20:15:00'),(43,'courses.export','Academics','Export academic courses to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(44,'courses.import','Academics','Bulk import academic courses from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(45,'batches.view','Academics','View academic batches and batch calendar','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(46,'batches.manage','Academics','Create, update, and manage academic batches','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(47,'batches.delete','Academics','Delete academic batches','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(48,'batches.export','Academics','Export academic batches to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(49,'batches.import','Academics','Bulk import academic batches from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(50,'sections.manage','Academics','Manage course sections and classroom allocation','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(51,'sections.import','Academics','Bulk import classroom sections from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(52,'tests.manage','Academics','Create academic assessments and record test scores','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(53,'academic_calendar.view','Academic Calendar','View institutional academic calendars, monthly planners, and version history','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(54,'academic_calendar.create','Academic Calendar','Create new academic year calendars and add monthly activities','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(55,'academic_calendar.edit','Academic Calendar','Modify academic calendar details and activities','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(56,'academic_calendar.revise','Academic Calendar','Upload revised calendar documents and manage version control','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(57,'academic_calendar.delete','Academic Calendar','Delete or archive academic calendars, versions, and activities','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(58,'academic_calendar.export','Academic Calendar','Export academic calendar reports and schedules to PDF, Excel, and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(59,'academic_calendar.import','Academic Calendar','Bulk import monthly activities from spreadsheets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(60,'academic_calendar.download','Academic Calendar','Download version-controlled academic calendar documents','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(61,'classes.view','Class Routine & Faculty','View classes, lecture timetable, and routine schedules','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(62,'classes.create','Class Routine & Faculty','Schedule regular and optional classes, batch recurrences','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(63,'classes.edit','Class Routine & Faculty','Modify class schedules, timings, faculty assignments, and rooms','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(64,'classes.delete','Class Routine & Faculty','Cancel and delete class schedules','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(65,'classes.recordings','Class Routine & Faculty','Upload class recordings and manage recording links','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(66,'classes.plans','Class Routine & Faculty','Create, publish, and revise comprehensive course plans','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(67,'classes.remind','Class Routine & Faculty','Send manual and automated reminder notification alerts to students','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(68,'classes.reports','Class Routine & Faculty','Generate and export faculty workload, syllabus coverage, and room reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(69,'classes.export','Class Routine & Faculty','Export class schedules and routine to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(70,'classes.import','Class Routine & Faculty','Bulk import class routines from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(71,'classrooms.view','Class Routine & Faculty','View classrooms master list, capacity, and facilities','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(72,'classrooms.manage','Class Routine & Faculty','Create, edit, import, and configure classrooms','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(73,'classrooms.delete','Class Routine & Faculty','Delete or archive classrooms','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(74,'faculty.view','Class Routine & Faculty','View faculty master list, profiles, and subject assignments','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(75,'faculty.manage','Class Routine & Faculty','Create, edit, and assign faculty and quick-create teachers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(76,'faculty.delete','Class Routine & Faculty','Delete faculty profiles and teaching history','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(77,'faculty.import','Class Routine & Faculty','Bulk import faculty profiles from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(78,'partner_institutes.view','Class Routine & Faculty','View partner coaching institutes list and collaboration details','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(79,'partner_institutes.manage','Class Routine & Faculty','Create, edit, and configure partner coaching institutes and MoUs','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(80,'partner_institutes.delete','Class Routine & Faculty','Delete partner coaching institutes','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(81,'partner_institutes.export','Class Routine & Faculty','Export partner coaching institutes directory to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(82,'partner_institutes.import','Class Routine & Faculty','Bulk import partner coaching institutes from spreadsheet','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(83,'optional_subjects.view','Optional Subjects','View optional subjects catalog, student allocations, and reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(84,'optional_subjects.manage','Optional Subjects','Create, edit, delete optional subjects and quick update student allocations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(85,'optional_subjects.requests','Optional Subjects','Review, approve, and reject optional subject change requests','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(86,'optional_subjects.export','Optional Subjects','Export optional subjects rosters, allocations, and reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(87,'optional_subjects.import','Optional Subjects','Import student optional subject assignments from spreadsheet','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(88,'mock_interviews.view','Mock Interviews','View mock interview sessions, board members, and candidate schedules','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(89,'mock_interviews.create','Mock Interviews','Create new mock interview sessions and drives','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(90,'mock_interviews.edit','Mock Interviews','Modify mock interview sessions, timings, venue, and instructions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(91,'mock_interviews.delete','Mock Interviews','Cancel or soft-delete mock interview sessions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(92,'mock_interviews.members','Mock Interviews','Manage board members pool, assign chairperson and panel members to sessions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(93,'mock_interviews.students','Mock Interviews','Assign students to sessions, reorder sequence, and configure interview slots','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(94,'mock_interviews.invite','Mock Interviews','Draft with live preview and dispatch invitations via Email, SMS, WhatsApp, and Telegram','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(95,'mock_interviews.recordings','Mock Interviews','Upload video recordings, attach recording URLs, and dispatch recording download links','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(96,'mock_interviews.export','Mock Interviews','Export session candidate rosters, scorecards, and schedules to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(97,'mock_interviews.import','Mock Interviews','Bulk import mock interview candidates from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(98,'mock_interviews.scores','Mock Interviews','Record panel evaluation scores, UPSC marks, and board feedback','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(99,'student_id_cards.view','Student ID Cards','View student ID cards listing and card preview','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(100,'student_id_cards.download','Student ID Cards','Download official printable Student ID Card PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(101,'student_id_cards.settings','Student ID Cards','Configure student ID card system preferences and download access','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(102,'tests.view','Tests & Examinations','View examinations listing, merit lists, and rankers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(103,'tests.create','Tests & Examinations','Schedule and create mock tests and examinations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(104,'tests.edit','Tests & Examinations','Update test schedules, parameters, and model answers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(105,'tests.delete','Tests & Examinations','Delete test records and question sets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(106,'tests.results.manage','Tests & Examinations','Enter, evaluate, and import student test scores','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(107,'tests.reports.view','Tests & Examinations','View test-wise and student-wise performance analytics','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(108,'tests.export','Tests & Examinations','Export test results, merit lists, and marksheets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(109,'tests.student.view','Tests & Examinations','Student portal access to view tests, marksheets, and leaderboard','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(110,'attendance.view','Attendance & Schedules','View attendance dashboards, daily logs, and register matrices','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(111,'attendance.manage','Attendance & Schedules','Record and manage single and bulk attendance for staff and students','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(112,'attendance.delete','Attendance & Schedules','Delete attendance entries','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(113,'attendance.export','Attendance & Schedules','Export attendance logs and registers to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(114,'attendance.import','Attendance & Schedules','Import bulk attendance logs from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(115,'attendance.settings','Attendance & Schedules','Configure attendance shifts, working calendars, and holiday schedules','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(116,'attendance.notify','Attendance & Schedules','Dispatch manual and automated presence/absence alerts and reminders','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(117,'attendance.biometric','Attendance & Schedules','Manage biometric devices, API tokens, and sync raw punch logs','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(118,'leaves.view','Leave Management','View leave applications and leave allocation balances','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(119,'leaves.apply','Leave Management','Submit leave applications','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(120,'leaves.manage','Leave Management','Approve, reject, or cancel leave applications','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(121,'leaves.categories.manage','Leave Management','Configure dynamic leave categories, annual quotas, and policies','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(122,'leaves.export','Leave Management','Export leave history and allocation registers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(123,'communications.view','Communications','View SMS and Email delivery audit logs','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(124,'communications.send','Communications','Compose and dispatch broadcast messages and campaigns','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(125,'communications.export','Communications','Export message delivery audit logs to Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(126,'templates.manage','Communications','Create and update SMS and Email message templates','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(127,'audit.view','Audit & Security','Inspect comprehensive system audit logs and diff records','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(128,'audit.export','Audit & Security','Export audit logs to CSV and compliance reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(129,'audit.prune','Audit & Security','Purge historical audit log entries beyond retention window','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(130,'reports.view','Reports & BI','View institutional analytics and performance reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(131,'reports.export','Reports & BI','Export high-resolution analytical reports to PDF/Excel','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(132,'pages.manage','CMS & Content','Create, edit, and publish dynamic CMS pages and policies','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(133,'contacts.manage','CMS & Content','Manage contact inquiries, departments, and support tickets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(134,'documents.view','Document Management','View institutional soft copy documents, files repository, and version history','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(135,'documents.create','Document Management','Upload new soft copy documents and files','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(136,'documents.edit','Document Management','Update document metadata, classification, and notes','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(137,'documents.revise','Document Management','Upload revised document versions with change notes','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(138,'documents.delete','Document Management','Soft-delete and archive soft copy documents and versions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(139,'documents.restore','Document Management','Restore soft-deleted documents and versions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(140,'documents.download','Document Management','Download document files across all versions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(141,'documents.export','Document Management','Export documents register to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(142,'documents.import','Document Management','Bulk import document records from spreadsheets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(143,'tickets.view','Support & Helpdesk','View support tickets list and conversations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(144,'tickets.create','Support & Helpdesk','Open new support tickets on behalf of users','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(145,'tickets.reply','Support & Helpdesk','Post public responses to support tickets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(146,'tickets.internal_notes','Support & Helpdesk','Post internal staff-only notes on tickets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(147,'tickets.assign','Support & Helpdesk','Assign and reassign ticket owners and agents','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(148,'tickets.manage','Support & Helpdesk','Update ticket statuses, priorities, SLAs, and delete tickets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(149,'tickets.categories','Support & Helpdesk','Manage support ticket categories and SLA rules','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(150,'tickets.canned_responses','Support & Helpdesk','Manage canned responses and response macros','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(151,'subscribers.view','Marketing & Subscribers','View newsletter & WhatsApp subscribers list and metrics','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(152,'subscribers.manage','Marketing & Subscribers','Add, edit, change status, and delete subscribers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(153,'subscribers.export','Marketing & Subscribers','Export subscribers list to CSV and Excel','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(154,'subscribers.import','Marketing & Subscribers','Import subscribers in bulk from CSV files','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(155,'chat.access','Live Chat & Calls','Access live chat portal and send messages','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(156,'chat.create_group','Live Chat & Calls','Create and administer group conversations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(157,'chat.create_channel','Live Chat & Calls','Create and publish broadcast channels','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(158,'chat.broadcast','Live Chat & Calls','Dispatch bulk personalized broadcast messages to users','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(159,'chat.voice_call','Live Chat & Calls','Initiate and receive WebRTC voice audio calls','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(160,'chat.video_call','Live Chat & Calls','Initiate and receive WebRTC video calls','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(161,'chat.pin_message','Live Chat & Calls','Pin and unpin messages in conversations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(162,'chat.delete_for_everyone','Live Chat & Calls','Delete messages for everyone in group or channel','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(163,'chat.manage_settings','Live Chat & Calls','Configure live chat and WebRTC calling parameters','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(164,'meetings.view','Online Meetings','View online meetings list and upcoming sessions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(165,'meetings.create','Online Meetings','Create instant and schedule online meetings','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(166,'meetings.manage','Online Meetings','Edit, update, and cancel scheduled online meetings','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(167,'meetings.co_host','Online Meetings','Act as co-host, admit waiting room attendees, and manage restrictions','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(168,'meetings.manage_settings','Online Meetings','Configure global online meeting duration and feature policies','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(169,'payments.view','Fees & Payments','View payment transactions, fee schedules, and student invoices','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(170,'payments.create','Fees & Payments','Record offline payments, collect fees, and initiate billing schedules','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(171,'payments.verify','Fees & Payments','Verify and approve offline payments (NEFT/IMPS/UPI/Cash)','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(172,'payments.refund','Fees & Payments','Process fee and security deposit refund requests','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(173,'payments.export','Fees & Payments','Export fee collections, ledger, and transaction reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(174,'payments.import','Fees & Payments','Import offline payment records in bulk','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(175,'payments.reminders','Fees & Payments','Send payment reminders via SMS, WhatsApp, and Email','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(176,'payments.gateways','Fees & Payments','Configure online and offline payment gateways','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(177,'payments.delete','Fees & Payments','Delete or revert fee schedules and student billing records','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(178,'library_deposit.manage','Fees & Payments','Manage library security deposits and withdrawal processing','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(179,'library.view','Library Management','Browse library catalog, search books, and view book details','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(180,'library.books.manage','Library Management','Create, update, and manage book catalog records and image galleries','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(181,'library.books.delete','Library Management','Delete catalog books and copies','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(182,'library.accession.manage','Library Management','Acquisition and accession registers, unique accession numbering, and barcode printing','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(183,'library.authors.manage','Library Management','Create, update, and manage book authors','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(184,'library.publishers.manage','Library Management','Create, update, and manage book publishers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(185,'library.categories.manage','Library Management','Create, update, and manage book categories and classification codes','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(186,'library.members.manage','Library Management','Register and manage library members, membership cards, and security deposit links','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(187,'library.issue.manage','Library Management','Issue, return, and renew books, with security deposit validation and bypass authorization','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(188,'library.reservations.manage','Library Management','Review, fulfill, and cancel book reservation requests','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(189,'library.fines.manage','Library Management','View, assess, collect, and waive overdue library fines','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(190,'library.reminders.send','Library Management','Dispatch automated and on-demand return reminders via In-App, Email, SMS, WhatsApp, Telegram','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(191,'library.reports.view','Library Management','Inspect library circulation, accession register, defaulters, and inventory reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(192,'library.export','Library Management','Export library catalog, accessioning, and circulation data to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(193,'library.import','Library Management','Import books and accession copies in bulk from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(194,'library.settings.manage','Library Management','Configure institutional library loan durations, fines, and borrowing policies','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(195,'library.reserve.create','Library Management','Request and reserve books from student and member portals','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(196,'alumni.view','Alumni Management','View alumni directory, profiles, and achievement dossiers','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(197,'alumni.create','Alumni Management','Create new alumni records manually or convert enrolled students','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(198,'alumni.edit','Alumni Management','Update alumni profile, employment, posting, and service details','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(199,'alumni.delete','Alumni Management','Soft-delete or remove alumni records','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(200,'alumni.restore','Alumni Management','Restore soft-deleted alumni records','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(201,'alumni.export','Alumni Management','Export alumni directory to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(202,'alumni.import','Alumni Management','Bulk import alumni records from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(203,'alumni.featured','Alumni Management','Feature, verify, and highlight alumni in directory showcase','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(204,'alumni.categories','Alumni Management','Manage dynamic alumni service categories, badges, and classifications','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(205,'study_materials.view','Study Materials','View course and batch study materials and resources','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(206,'study_materials.create','Study Materials','Upload and publish new study materials, notes, and lectures','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(207,'study_materials.edit','Study Materials','Edit study material details, files, and batch associations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(208,'study_materials.delete','Study Materials','Soft-delete or remove study materials','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(209,'study_materials.restore','Study Materials','Restore soft-deleted study materials','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(210,'study_materials.share','Study Materials','Share study materials with students via Email, SMS, WhatsApp, Telegram, or public link','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(211,'study_materials.export','Study Materials','Export study materials list to Excel, CSV, and PDF','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(212,'study_materials.import','Study Materials','Bulk import study materials from Excel and CSV','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(213,'study_materials.download','Study Materials','Download or access study material files and contents','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(214,'hostel.view','Hostel Management','View hostel dashboard, buildings, floors, rooms, and bed statuses','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(215,'hostel.buildings.manage','Hostel Management','Create, edit, and configure hostel buildings and floors','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(216,'hostel.rooms.manage','Hostel Management','Create, edit, and configure rooms, sharing beds, gender restrictions, and maintenance','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(217,'hostel.allocations.manage','Hostel Management','Allocate, check-in, extend, and vacate student and staff room accommodations','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(218,'hostel.allocations.view','Hostel Management','View hostel allocations, room histories, and resident details','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(219,'hostel.requests.manage','Hostel Management','Approve or reject resident accommodation extension requests with reason','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(220,'hostel.forms.manage','Hostel Management','Track and verify physical hard-copy and uploaded soft-copy hostel admission forms','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(221,'hostel.reports.view','Hostel Management','View and export hostel occupancy, gender analytics, and resident reports','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(222,'hostel.settings.manage','Hostel Management','Configure hostel settings including extension request enablement and softcopy requirements','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(223,'api_keys.view','API & Integrations','View API keys, usage statistics, and integration telemetry','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(224,'api_keys.create','API & Integrations','Generate new API keys for external applications and services','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(225,'api_keys.edit','API & Integrations','Modify API key scopes, IP whitelists, and rate limits','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(226,'api_keys.revoke','API & Integrations','Revoke or suspend active API keys immediately','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(227,'api_keys.delete','API & Integrations','Delete or purge API key records and telemetry logs','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(228,'api_keys.rotate','API & Integrations','Regenerate and roll API key secrets','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(229,'api_keys.logs','API & Integrations','Inspect incoming API request logs and error traces','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(230,'settings.general','System Settings','Configure institution identity, logos, and general settings','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(231,'settings.appearance','System Settings','Configure themes, logos, branding, and color palettes','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(232,'settings.localization','System Settings','Configure default language, timezones, and date formats','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(233,'settings.security','System Settings','Configure authentication rules and lockout security','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(234,'settings.email','System Settings','Configure SMTP, email delivery, and testing','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(235,'settings.sms','System Settings','Configure SMS gateways (2Factor, Fast2SMS, MSG91)','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(236,'settings.whatsapp','System Settings','Configure WhatsApp business gateway and notifications','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(237,'settings.telegram','System Settings','Configure Telegram bot integration and alerts','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(238,'settings.payment','System Settings','Configure payment gateways (Razorpay, PhonePe)','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(239,'settings.backup','System Settings','Manage, trigger on-demand backups, and download database/media archives','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(240,'settings.cron','System Settings','Manage automated scheduled system tasks and cron runners','web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(241,'settings.chat','System Settings','Configure live chat, WebRTC, and meeting server parameters','web','2026-11-01 20:15:01','2026-11-01 20:15:01');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profile_update_requests`
--

DROP TABLE IF EXISTS `profile_update_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profile_update_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `section` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'basic',
  `payload` json NOT NULL,
  `current_data` json DEFAULT NULL,
  `changes` json DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_proof` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile_update_requests_request_no_unique` (`request_no`),
  KEY `profile_update_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `profile_update_requests_user_id_status_index` (`user_id`,`status`),
  KEY `profile_update_requests_status_index` (`status`),
  CONSTRAINT `profile_update_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `profile_update_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profile_update_requests`
--

LOCK TABLES `profile_update_requests` WRITE;
/*!40000 ALTER TABLE `profile_update_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `profile_update_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
INSERT INTO `role_has_permissions` VALUES (1,1),(2,1),(3,1),(4,1),(5,1),(6,1),(7,1),(8,1),(9,1),(10,1),(11,1),(12,1),(13,1),(14,1),(15,1),(16,1),(17,1),(18,1),(19,1),(20,1),(21,1),(22,1),(23,1),(24,1),(25,1),(26,1),(27,1),(28,1),(29,1),(30,1),(31,1),(32,1),(33,1),(34,1),(35,1),(36,1),(37,1),(38,1),(39,1),(40,1),(41,1),(42,1),(43,1),(44,1),(45,1),(46,1),(47,1),(48,1),(49,1),(50,1),(51,1),(52,1),(53,1),(54,1),(55,1),(56,1),(57,1),(58,1),(59,1),(60,1),(61,1),(62,1),(63,1),(64,1),(65,1),(66,1),(67,1),(68,1),(69,1),(70,1),(71,1),(72,1),(73,1),(74,1),(75,1),(76,1),(77,1),(78,1),(79,1),(80,1),(81,1),(82,1),(83,1),(84,1),(85,1),(86,1),(87,1),(88,1),(89,1),(90,1),(91,1),(92,1),(93,1),(94,1),(95,1),(96,1),(97,1),(98,1),(99,1),(100,1),(101,1),(102,1),(103,1),(104,1),(105,1),(106,1),(107,1),(108,1),(109,1),(110,1),(111,1),(112,1),(113,1),(114,1),(115,1),(116,1),(117,1),(118,1),(119,1),(120,1),(121,1),(122,1),(123,1),(124,1),(125,1),(126,1),(127,1),(128,1),(129,1),(130,1),(131,1),(132,1),(133,1),(134,1),(135,1),(136,1),(137,1),(138,1),(139,1),(140,1),(141,1),(142,1),(143,1),(144,1),(145,1),(146,1),(147,1),(148,1),(149,1),(150,1),(151,1),(152,1),(153,1),(154,1),(155,1),(156,1),(157,1),(158,1),(159,1),(160,1),(161,1),(162,1),(163,1),(164,1),(165,1),(166,1),(167,1),(168,1),(169,1),(170,1),(171,1),(172,1),(173,1),(174,1),(175,1),(176,1),(177,1),(178,1),(179,1),(180,1),(181,1),(182,1),(183,1),(184,1),(185,1),(186,1),(187,1),(188,1),(189,1),(190,1),(191,1),(192,1),(193,1),(194,1),(195,1),(196,1),(197,1),(198,1),(199,1),(200,1),(201,1),(202,1),(203,1),(204,1),(205,1),(206,1),(207,1),(208,1),(209,1),(210,1),(211,1),(212,1),(213,1),(214,1),(215,1),(216,1),(217,1),(218,1),(219,1),(220,1),(221,1),(222,1),(223,1),(224,1),(225,1),(226,1),(227,1),(228,1),(229,1),(230,1),(231,1),(232,1),(233,1),(234,1),(235,1),(236,1),(237,1),(238,1),(239,1),(240,1),(241,1),(1,2),(2,2),(3,2),(4,2),(5,2),(6,2),(7,2),(8,2),(9,2),(10,2),(11,2),(12,2),(13,2),(14,2),(15,2),(16,2),(17,2),(18,2),(19,2),(20,2),(21,2),(22,2),(23,2),(24,2),(25,2),(26,2),(27,2),(28,2),(29,2),(30,2),(31,2),(32,2),(33,2),(34,2),(35,2),(36,2),(37,2),(38,2),(39,2),(40,2),(41,2),(42,2),(43,2),(44,2),(45,2),(46,2),(47,2),(48,2),(49,2),(50,2),(51,2),(52,2),(53,2),(54,2),(55,2),(56,2),(57,2),(58,2),(59,2),(60,2),(61,2),(62,2),(63,2),(64,2),(65,2),(66,2),(67,2),(68,2),(69,2),(70,2),(71,2),(72,2),(73,2),(74,2),(75,2),(76,2),(77,2),(78,2),(79,2),(80,2),(81,2),(82,2),(83,2),(84,2),(85,2),(86,2),(87,2),(88,2),(89,2),(90,2),(91,2),(92,2),(93,2),(94,2),(95,2),(96,2),(97,2),(98,2),(99,2),(100,2),(101,2),(102,2),(103,2),(104,2),(105,2),(106,2),(107,2),(108,2),(109,2),(110,2),(111,2),(112,2),(113,2),(114,2),(115,2),(116,2),(117,2),(118,2),(119,2),(120,2),(121,2),(122,2),(123,2),(124,2),(125,2),(126,2),(127,2),(128,2),(130,2),(131,2),(132,2),(133,2),(134,2),(135,2),(136,2),(137,2),(138,2),(139,2),(140,2),(141,2),(142,2),(143,2),(144,2),(145,2),(146,2),(147,2),(148,2),(149,2),(150,2),(151,2),(152,2),(153,2),(154,2),(155,2),(156,2),(157,2),(158,2),(159,2),(160,2),(161,2),(162,2),(163,2),(164,2),(165,2),(166,2),(167,2),(168,2),(169,2),(170,2),(171,2),(172,2),(173,2),(174,2),(175,2),(176,2),(177,2),(178,2),(179,2),(180,2),(181,2),(182,2),(183,2),(184,2),(185,2),(186,2),(187,2),(188,2),(189,2),(190,2),(191,2),(192,2),(193,2),(194,2),(195,2),(196,2),(197,2),(198,2),(199,2),(200,2),(201,2),(202,2),(203,2),(204,2),(205,2),(206,2),(207,2),(208,2),(209,2),(210,2),(211,2),(212,2),(213,2),(214,2),(215,2),(216,2),(217,2),(218,2),(219,2),(220,2),(221,2),(222,2),(223,2),(224,2),(225,2),(226,2),(227,2),(228,2),(229,2),(230,2),(231,2),(232,2),(233,2),(234,2),(235,2),(236,2),(237,2),(238,2),(239,2),(240,2),(241,2),(17,3),(18,3),(28,3),(29,3),(30,3),(31,3),(32,3),(36,3),(37,3),(38,3),(53,3),(58,3),(60,3),(61,3),(69,3),(71,3),(74,3),(83,3),(84,3),(85,3),(86,3),(87,3),(99,3),(100,3),(123,3),(134,3),(140,3),(155,3),(156,3),(157,3),(159,3),(160,3),(164,3),(165,3),(166,3),(167,3),(196,3),(197,3),(198,3),(201,3),(204,3),(17,4),(41,4),(46,4),(50,4),(52,4),(53,4),(58,4),(60,4),(61,4),(62,4),(63,4),(65,4),(66,4),(67,4),(68,4),(69,4),(71,4),(74,4),(78,4),(83,4),(86,4),(88,4),(95,4),(98,4),(102,4),(103,4),(104,4),(106,4),(107,4),(108,4),(110,4),(111,4),(116,4),(118,4),(119,4),(134,4),(135,4),(137,4),(140,4),(141,4),(155,4),(156,4),(157,4),(159,4),(160,4),(164,4),(165,4),(166,4),(167,4),(196,4),(205,4),(206,4),(207,4),(208,4),(210,4),(211,4),(213,4),(17,5),(53,5),(60,5),(130,5),(131,5),(134,5),(140,5),(155,5),(159,5),(160,5),(164,5),(169,5),(170,5),(171,5),(172,5),(173,5),(174,5),(175,5),(177,5),(178,5),(17,6),(36,6),(53,6),(58,6),(60,6),(61,6),(69,6),(74,6),(78,6),(83,6),(85,6),(88,6),(93,6),(94,6),(95,6),(99,6),(100,6),(110,6),(118,6),(119,6),(133,6),(134,6),(135,6),(137,6),(140,6),(143,6),(145,6),(146,6),(155,6),(156,6),(157,6),(159,6),(160,6),(164,6),(165,6),(196,6),(197,6),(205,6),(213,6),(214,6),(217,6),(218,6),(219,6),(220,6),(221,6),(17,7),(53,7),(60,7),(110,7),(118,7),(119,7),(130,7),(131,7),(134,7),(135,7),(137,7),(140,7),(155,7),(159,7),(160,7),(164,7),(178,7),(179,7),(180,7),(181,7),(182,7),(183,7),(184,7),(185,7),(186,7),(187,7),(188,7),(189,7),(190,7),(191,7),(192,7),(193,7),(194,7),(195,7),(53,8),(60,8),(109,8),(110,8),(118,8),(119,8),(134,8),(140,8),(155,8),(159,8),(160,8),(164,8),(179,8),(195,8),(205,8),(213,8);
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'emerald',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Super Administrator','Unrestricted root-level access across all system modules, configurations, and audit trails.','violet',1,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(2,'Administrator','Full administrative access to manage users, roles, academics, students, and settings.','emerald',1,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(3,'Admissions Officer','Manage student admission inquiries, applications, selection lists, and candidate verification.','sky',0,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(4,'Faculty','Manage batches, attendance, course study materials, assignments, and examination test scores.','blue',0,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(5,'Accountant','Manage fee collections, financial receipts, installment schedules, and refund transactions.','amber',0,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(6,'Staff','Front-desk operations, general student record search, and general communications.','zinc',0,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(7,'Librarian','Manage library catalog, book accession registers, issue/return/renewal circulation, reservations, fines, and member cards.','teal',1,'web','2026-11-01 20:15:01','2026-11-01 20:15:01'),(8,'Student','Student portal access for timetable, attendance, test scores, notifications, and profile details.','indigo',0,'web','2026-11-01 20:15:01','2026-11-01 20:15:01');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `batch_course_id` bigint unsigned DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity` int unsigned NOT NULL DEFAULT '45',
  `room_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timing` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sections_course_id_foreign` (`course_id`),
  KEY `sections_batch_course_id_foreign` (`batch_course_id`),
  KEY `sections_created_by_foreign` (`created_by`),
  KEY `sections_updated_by_foreign` (`updated_by`),
  KEY `sections_deleted_by_foreign` (`deleted_by`),
  KEY `sections_batch_id_course_id_index` (`batch_id`,`course_id`),
  CONSTRAINT `sections_batch_course_id_foreign` FOREIGN KEY (`batch_course_id`) REFERENCES `batch_courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sections_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sections_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sections_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sections_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sections_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sections`
--

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
INSERT INTO `sections` VALUES (1,1,1,NULL,'Section A','1',100,'Hall 1','Mon–Fri · 08:00–11:00','active',NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(2,1,1,NULL,'Section B','2',100,'Hall 2','Mon–Fri · 16:00–19:00','active',NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(3,2,1,NULL,'Section A','1',100,'Hall 1','Mon–Fri · 08:00–11:00','active',NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(4,2,1,NULL,'Section B','2',100,'Hall 2','Mon–Fri · 16:00–19:00','active',NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL);
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string' COMMENT 'string, text, boolean, number, select, image, file, secret, json',
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `options` json DEFAULT NULL COMMENT 'choices for select-type settings',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`),
  KEY `settings_created_by_foreign` (`created_by`),
  KEY `settings_updated_by_foreign` (`updated_by`),
  KEY `settings_deleted_by_foreign` (`deleted_by`),
  KEY `settings_group_status_index` (`group`,`status`),
  KEY `settings_group_index` (`group`),
  CONSTRAINT `settings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `settings_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'appearance.default_fullscreen','normal','appearance','select','Default Admin Viewport Mode','\"{\\\"normal\\\":\\\"Standard Window Mode\\\",\\\"fullscreen\\\":\\\"Full Screen Mode\\\"}\"',1,NULL,NULL,NULL,'2026-11-01 20:14:45','2026-11-01 20:14:45',NULL),(2,'general.site_name','SNT CSSC','general','string','Site name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(3,'general.site_tagline','Shaping future civil servants','general','string','Site tagline',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(4,'general.site_description','SNT CSSC — Student Management Information System for admissions, enrollments, batches and fee management.','general','text','Site description',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(5,'general.app_name','SNT CSSC MIS','general','string','Application name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(6,'general.title','SNT CSSC MIS','general','string','Page title suffix',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(7,'general.site_logo',NULL,'general','image','Site logo',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(8,'general.site_favicon',NULL,'general','image','Site favicon',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(9,'general.site_campus','Main Campus','general','string','Campus',NULL,1,NULL,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(10,'general.site_email','info@sntcssc.in','general','string','Contact email',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(11,'general.site_mobile','+91 90000 00000','general','string','Contact mobile',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(12,'general.site_phone','033 0000 0000','general','string','Contact phone',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(13,'general.site_address','SNT CSSC, Main Campus, Kolkata, West Bengal, India','general','text','Address',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(14,'general.site_timing','10:00 AM – 6:00 PM','general','string','Office timing',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(15,'general.site_open_days','Monday – Saturday','general','string','Open days',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(16,'general.copyright_text','© :year SNT CSSC. All rights reserved.','general','string','Copyright text',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(17,'appearance.logo',NULL,'appearance','image','Dashboard logo',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(18,'appearance.icon',NULL,'appearance','image','Dashboard icon (favicon / tile)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(19,'appearance.primary_color','#10b981','appearance','string','Primary accent color',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(20,'appearance.dark_mode','system','appearance','select','Default dark mode','{\"dark\": \"Dark\", \"light\": \"Light\", \"system\": \"System\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(21,'appearance.sidebar_theme','default','appearance','select','Sidebar theme','{\"dark\": \"Dark\", \"light\": \"Light\", \"default\": \"Default\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(22,'appearance.font_family','Inter','appearance','string','Font family',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(23,'appearance.custom_css',NULL,'appearance','text','Custom CSS',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(24,'seo.meta_title','SNT CSSC MIS — Student Management Information System','seo','string','Meta title',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(25,'seo.meta_description','Admissions, enrollments, batches, tests and fee management for SNT CSSC coaching programmes.','seo','text','Meta description',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(26,'seo.meta_keywords','sntcssc, mis, admissions, coaching, civil services, ssc','seo','text','Meta keywords (comma separated)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(27,'seo.canonical_url',NULL,'seo','string','Canonical URL',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(28,'seo.og_image',NULL,'seo','image','OG share image',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(29,'seo.twitter_handle','@sntcssc','seo','string','Twitter handle',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(30,'seo.robots_txt','User-agent: *\nAllow: /','seo','text','Robots.txt',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(31,'seo.google_analytics_id',NULL,'seo','string','Google Analytics ID',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(32,'localization.language','en','localization','select','Default language','{\"bn\": \"Bengali\", \"en\": \"English\", \"hi\": \"Hindi\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(33,'localization.fallback_language','en','localization','select','Fallback language','{\"bn\": \"Bengali\", \"en\": \"English\", \"hi\": \"Hindi\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(34,'localization.timezone','Asia/Kolkata','localization','string','Timezone',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(35,'localization.date_format','d M Y','localization','select','Date format','{\"Y-m-d\": \"2026-08-22\", \"d M Y\": \"22 Aug 2026\", \"d-m-Y\": \"22-08-2026\", \"d/m/Y\": \"22/08/2026\", \"jS F Y\": \"22nd August 2026\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(36,'localization.time_format','h:i A','localization','select','Time format','{\"H:i\": \"17:30\", \"h:i A\": \"05:30 PM\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(37,'localization.currency_symbol','₹','localization','string','Currency symbol',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(38,'localization.currency_code','INR','localization','string','Currency code',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(39,'localization.number_format','indian','localization','select','Number format','{\"indian\": \"Indian\", \"international\": \"International\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(40,'system.maintenance_mode','0','system','boolean','Maintenance mode',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(41,'system.debug_mode','0','system','boolean','Debug mode',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(42,'system.app_name','SNT CSSC MIS','system','string','System app name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(43,'system.app_version','1.0.0','system','string','App version',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(44,'system.developed_by','SNT CSSC IT Team','system','string','Developed by',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(45,'system.developer_contact','dev@sntcssc.in','system','string','Developer contact',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(46,'system.developer_github','https://github.com/sntcssc','system','string','Developer GitHub',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(47,'system.developer_website','https://sntcssc.in','system','string','Developer website',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(48,'system.max_upload_size','10','system','number','Max upload size (MB)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(49,'system.session_lifetime','120','system','number','Session lifetime (minutes)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(50,'system.cache_driver','file','system','select','Cache driver','{\"file\": \"File\", \"redis\": \"Redis\", \"database\": \"Database\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(51,'sms.enabled','0','sms','boolean','Enable SMS sending',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(52,'sms.driver','log','sms','select','SMS driver','{\"log\": \"Log (no real SMS)\", \"2factor\": \"2factor.in\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(53,'sms.two_factor_api_key',NULL,'sms','secret','2factor.in API key',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(54,'sms.two_factor_base_url','https://2factor.in','sms','string','2factor.in API base URL',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(55,'sms.two_factor_sender_id',NULL,'sms','string','2factor.in sender ID (DLT approved)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(56,'sms.two_factor_template_name',NULL,'sms','string','2factor.in DLT template name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(57,'sms.otp_length','6','sms','number','OTP length',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(58,'sms.otp_expiry_minutes','5','sms','number','OTP expiry (minutes)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(59,'sms.default_country_code','+91','sms','string','Default country code',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(60,'sms.http_timeout','10','sms','number','HTTP timeout (seconds)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(61,'sms.http_retry_attempts','2','sms','number','HTTP retry attempts',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(62,'payment.enabled','0','payment','boolean','Enable online payments',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(63,'payment.driver','razorpay','payment','select','Active payment gateway','{\"offline\": \"Offline Only\", \"phonepe\": \"PhonePe\", \"ccavenue\": \"CCAVenue\", \"razorpay\": \"Razorpay\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(64,'payment.currency','INR','payment','string','Currency code',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(65,'payment.razorpay_enabled','0','payment','boolean','Razorpay enabled',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(66,'payment.razorpay_key_id',NULL,'payment','string','Razorpay key ID',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(67,'payment.razorpay_key_secret',NULL,'payment','secret','Razorpay key secret',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(68,'payment.razorpay_webhook_secret',NULL,'payment','secret','Razorpay webhook secret',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(69,'payment.phonepe_enabled','0','payment','boolean','PhonePe enabled',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(70,'payment.phonepe_merchant_id',NULL,'payment','string','PhonePe merchant ID',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(71,'payment.phonepe_salt_key',NULL,'payment','secret','PhonePe salt key',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(72,'payment.phonepe_salt_index','1','payment','number','PhonePe salt index',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(73,'payment.phonepe_mode','UAT','payment','select','PhonePe mode','{\"UAT\": \"UAT (test)\", \"LIVE\": \"Live\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(74,'payment.ccavenue_enabled','0','payment','boolean','CCAVenue enabled',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(75,'payment.ccavenue_merchant_id',NULL,'payment','string','CCAVenue Merchant ID',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(76,'payment.ccavenue_access_code',NULL,'payment','string','CCAVenue Access Code',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(77,'payment.ccavenue_working_key',NULL,'payment','secret','CCAVenue Working Key',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(78,'payment.ccavenue_mode','test','payment','select','CCAVenue mode','{\"live\": \"Live (Production)\", \"test\": \"Test (Sandbox)\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(79,'payment.offline_enabled','1','payment','boolean','Enable offline payment fallback',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(80,'payment.offline_bank_transfer_enabled','1','payment','boolean','Bank Transfer/NEFT/IMPS enabled',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(81,'payment.offline_bank_name','State Bank of India','payment','string','Bank Name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(82,'payment.offline_account_name','Satyendranath Tagore Civil Services Study Centre','payment','string','Account Name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(83,'payment.offline_account_number','39824589201','payment','string','Account Number',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(84,'payment.offline_ifsc_code','SBIN0000123','payment','string','IFSC Code',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(85,'payment.offline_branch','Kolkata Main Branch','payment','string','Branch Name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(86,'payment.offline_upi_enabled','1','payment','boolean','Static UPI QR enabled',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(87,'payment.offline_upi_id','sntcssc@indianbk','payment','string','UPI VPA Address',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(88,'payment.offline_upi_payee_name','SNTCSSC Kolkata','payment','string','UPI Payee Name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(89,'payment.offline_cash_enabled','1','payment','boolean','In-Person Cash Payment enabled',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(90,'payment.offline_cash_instructions','Pay cash directly at Accounts Counter, Room 102, Administrative Block between 10:00 AM and 4:00 PM on working days and collect official signed receipt.','payment','text','Cash in-person instructions',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(91,'email.driver','log','email','select','Mail driver','{\"log\": \"Log\", \"smtp\": \"SMTP\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(92,'email.smtp_host',NULL,'email','string','SMTP host',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(93,'email.smtp_port','587','email','number','SMTP port',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(94,'email.smtp_username',NULL,'email','string','SMTP username',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(95,'email.smtp_password',NULL,'email','secret','SMTP password',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(96,'email.smtp_encryption','tls','email','select','SMTP encryption','{\"ssl\": \"SSL\", \"tls\": \"TLS\", \"none\": \"None\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(97,'email.from_address','noreply@sntcssc.in','email','string','From address',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(98,'email.from_name','SNT CSSC MIS','email','string','From name',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(99,'email.cc_to',NULL,'email','string','CC to',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(100,'email.send_to',NULL,'email','string','Send test email to',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(101,'chat.enabled','1','chat','boolean','Enable Live Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(102,'chat.direct_enabled','1','chat','boolean','Enable Direct 1-on-1 Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(103,'chat.group_enabled','1','chat','boolean','Enable Group Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(104,'chat.channel_enabled','1','chat','boolean','Enable Broadcast Channels',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(105,'chat.voice_call_enabled','1','chat','boolean','Enable WebRTC Voice Calls',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(106,'chat.video_call_enabled','1','chat','boolean','Enable WebRTC Video Calls',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(107,'chat.require_websocket_for_calls','1','chat','boolean','Require Active WebSocket for Calling',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(108,'chat.transport_driver','hybrid','chat','select','Chat Realtime Transport','{\"hybrid\": \"Hybrid (Reverb WebSocket + Fallback Poll)\", \"polling\": \"Livewire Polling Only (wire:poll)\", \"broadcasting\": \"Broadcasting Only (Reverb WebSockets)\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(109,'chat.poll_interval','3s','chat','select','Chat Polling Interval','{\"3s\": \"3 Seconds\", \"5s\": \"5 Seconds\", \"10s\": \"10 Seconds\", \"30s\": \"30 Seconds\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(110,'chat.webrtc_signaling_driver','reverb','chat','select','WebRTC Signaling Driver','{\"reverb\": \"Reverb / WebSockets Signaling\", \"internal_poll\": \"Internal Polling Signal Exchange\"}',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(111,'chat.webrtc_stun_server','stun:stun.l.google.com:19302','chat','string','WebRTC STUN Server',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(112,'chat.webrtc_turn_server',NULL,'chat','string','WebRTC TURN Server (Optional)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(113,'chat.webrtc_turn_username',NULL,'chat','string','TURN Username',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(114,'chat.webrtc_turn_credential',NULL,'chat','secret','TURN Credential',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(115,'chat.max_file_size_mb','25','chat','number','Max Attachment Size (MB)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(116,'chat.allowed_file_types','jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,mp3,mp4,wav','chat','string','Allowed File Extensions',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(117,'chat.edit_time_limit_minutes','15','chat','number','Message Edit Window (Minutes)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(118,'chat.notify_email','1','chat','boolean','Send Email Alerts on Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(119,'chat.notify_sms','0','chat','boolean','Send SMS Alerts on Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(120,'chat.notify_whatsapp','0','chat','boolean','Send WhatsApp Alerts on Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(121,'chat.notify_telegram','0','chat','boolean','Send Telegram Alerts on Chat',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(122,'chat.sound_enabled','1','chat','boolean','Play Chat Message Chimes',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(123,'library.fines_enabled','1','library','boolean','Enable Overdue Fine & Penalty System',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(124,'library.fine_per_day','5','library','number','Overdue Fine Rate Per Day (INR)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(125,'library.default_loan_days','15','library','number','Default Book Loan Duration (Days)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(126,'library.max_books_allowed','3','library','number','Maximum Books Allowed Per Member',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(127,'library.max_renewals','2','library','number','Maximum Allowed Book Renewals',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(128,'library.require_security_deposit','1','library','boolean','Require Library Security Deposit for Borrowing',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(129,'library.allow_deposit_bypass','1','library','boolean','Allow Librarian / Staff Security Deposit Bypass',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(130,'library.reservation_expiry_days','3','library','number','Book Reservation Pickup Hold Window (Days)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(131,'library.reminder_days_before','2','library','number','Days Before Due Date to Dispatch Return Reminder',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(132,'library.auto_reminders_enabled','1','library','boolean','Enable Automated Scheduled Return Reminders',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(133,'library.reminder_channels','[\"database\",\"email\",\"sms\"]','library','json','Active Reminder Channels',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(134,'student_id_config','{\"sequence_length\":4,\"default_fallback_identifier\":\"0\",\"course_identifiers\":{\"CC\":\"1\",\"PCC\":\"3\",\"MGP\":\"4\",\"ITP\":\"5\",\"WTS\":\"6\"},\"section_identifiers\":{\"Section A\":\"1\",\"Section B\":\"2\",\"Section C\":\"3\"}}','academic','json','Student ID Generation Config',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL),(135,'hostel.form_download_mode','generated','hostel','string','Hostel Form Download Mode (generated or uploaded)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(136,'hostel.allow_resident_extension_requests','1','hostel','boolean','Allow Residents to Submit Accommodation Extension Requests',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(137,'hostel.soft_copy_required','0','hostel','boolean','Require Soft-Copy Hostel Admission Form Upload (Mandatory)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(138,'hostel.default_duration_months','6','hostel','number','Default Room Accommodation Timeline Duration (Months)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(139,'hostel.expiry_notice_days','15','hostel','number','Hostel Accommodation Expiration Alert Window (Days)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(140,'hostel.rules_notice','Residents must adhere to hostel code of conduct. Quiet hours are 10:00 PM to 6:00 AM. Visitors permitted only in common reception area.','hostel','text','Hostel Resident Guidelines and Terms Notice',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(141,'attendance.notify_student_present','1','attendance','boolean','Notify Student On Marked Present',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(142,'attendance.notify_student_absent','1','attendance','boolean','Notify Student On Marked Absent',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(143,'attendance.notify_student_late','1','attendance','boolean','Notify Student On Marked Late',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(144,'attendance.notification_channels','[\"database\",\"email\",\"sms\",\"whatsapp\"]','attendance','json','Attendance Notification Channels',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(145,'attendance.mandatory_percentage','75','attendance','number','Minimum Required Attendance %',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(146,'attendance.daily_auto_notify_enabled','0','attendance','boolean','Automatic Daily Absentee Notification Job',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(147,'attendance.daily_auto_notify_time','18:00','attendance','string','Daily Attendance Notification Dispatch Time',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(148,'attendance.allow_staff_web_checkin','1','attendance','boolean','Allow Staff Portal Web Check-In / Check-Out',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(149,'attendance.biometric_sync_interval','5','attendance','number','Biometric Auto-Process Interval (Minutes)',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(150,'leave.auto_sync_to_attendance','1','leave','boolean','Automatically Sync Approved Leaves to Attendance Register',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(151,'leave.allow_half_day','1','leave','boolean','Allow Half-Day Leave Applications',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(152,'leave.default_annual_staff_quota','15','leave','number','Default Annual Leave Quota for Office Staff',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(153,'leave.default_annual_student_quota','10','leave','number','Default Annual Leave Quota for Students',NULL,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sms_templates`
--

DROP TABLE IF EXISTS `sms_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'notification',
  `sender_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dlt_template_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables` json DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sms_templates_code_unique` (`code`),
  KEY `sms_templates_created_by_foreign` (`created_by`),
  KEY `sms_templates_updated_by_foreign` (`updated_by`),
  KEY `sms_templates_deleted_by_foreign` (`deleted_by`),
  KEY `sms_templates_category_status_index` (`category`,`status`),
  CONSTRAINT `sms_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sms_templates_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sms_templates_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sms_templates`
--

LOCK TABLES `sms_templates` WRITE;
/*!40000 ALTER TABLE `sms_templates` DISABLE KEYS */;
INSERT INTO `sms_templates` VALUES (1,'otp_registration','Registration Mobile OTP','otp','SNTCSS','1007161234567890123','Dear {name}, your SNT CSSC verification OTP is {otp}. Valid for {expiry} minutes. Do not share this OTP with anyone.','[\"name\", \"otp\", \"expiry\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(2,'otp_login','Login Secure OTP','otp','SNTCSS','1007161234567890124','Dear {name}, your SNT CSSC login OTP is {otp}. Valid for {expiry} minutes. Use this code to sign in securely.','[\"name\", \"otp\", \"expiry\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(3,'otp_password_reset','Password Reset OTP','otp','SNTCSS','1007161234567890125','Dear {name}, your password reset OTP for SNT CSSC is {otp}. Valid for {expiry} minutes. If you did not request this, ignore this message.','[\"name\", \"otp\", \"expiry\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(4,'admission_confirmation','Admission Application Confirmation','notification','SNTCSS','1007161234567890126','Dear {name}, your admission application for {course} has been received (App No: {application_no}). Check portal for updates - SNT CSSC.','[\"name\", \"course\", \"application_no\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(5,'test_schedule_notice','Mock Test Schedule Notice','notice','SNTCSS','1007161234567890127','Notice: {test_name} is scheduled on {date} at {time}. Please be prepared. - SNT CSSC Academic Cell.','[\"name\", \"test_name\", \"date\", \"time\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(6,'promotional_broadcast','New Course Batch Announcement','promotional','SNTCSS','1007161234567890128','Admissions open for new batch of {course} at SNT CSSC. Visit {url} or contact office to enroll now.','[\"course\", \"url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(7,'subscriber_welcome_sms','Subscriber Welcome & Alerts SMS','communication','SNTCSS','1007161234567890140','Welcome to {app_name}! You are subscribed to instant updates. Unsubscribe anytime: {unsubscribe_url}','[\"name\", \"app_name\", \"unsubscribe_url\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:00','2026-11-01 20:15:00',NULL),(8,'ticket_created_sms','Ticket Created SMS','support','SNTCSS','1007161234567890130','Dear {user_name}, your support ticket #{ticket_number} has been logged. Track: {ticket_url} - {app_name}','[\"user_name\", \"ticket_number\", \"ticket_url\", \"app_name\"]',1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `sms_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_addresses`
--

DROP TABLE IF EXISTS `student_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `address_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Permanent',
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subdistrict` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line1` text COLLATE utf8mb4_unicode_ci,
  `address_line2` text COLLATE utf8mb4_unicode_ci,
  `post_office` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `police_station` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pin_code` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_addresses_user_id_address_type_index` (`user_id`,`address_type`),
  CONSTRAINT `student_addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_addresses`
--

LOCK TABLES `student_addresses` WRITE;
/*!40000 ALTER TABLE `student_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_disciplinary_actions`
--

DROP TABLE IF EXISTS `student_disciplinary_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_disciplinary_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `action_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `action_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suspension',
  `order_no` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_date` date NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_lifetime` tinyint(1) NOT NULL DEFAULT '0',
  `reason_category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_details` text COLLATE utf8mb4_unicode_ci,
  `supporting_document` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `revocation_reason` text COLLATE utf8mb4_unicode_ci,
  `revoked_by` bigint unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_disciplinary_actions_action_no_unique` (`action_no`),
  KEY `student_disciplinary_actions_course_id_foreign` (`course_id`),
  KEY `student_disciplinary_actions_revoked_by_foreign` (`revoked_by`),
  KEY `student_disciplinary_actions_created_by_foreign` (`created_by`),
  KEY `student_disciplinary_actions_updated_by_foreign` (`updated_by`),
  KEY `student_disciplinary_actions_deleted_by_foreign` (`deleted_by`),
  KEY `student_disciplinary_actions_user_id_status_index` (`user_id`,`status`),
  KEY `student_disciplinary_actions_status_is_lifetime_index` (`status`,`is_lifetime`),
  KEY `student_disciplinary_actions_status_index` (`status`),
  CONSTRAINT `student_disciplinary_actions_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_disciplinary_actions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_disciplinary_actions_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_disciplinary_actions_revoked_by_foreign` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_disciplinary_actions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_disciplinary_actions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_disciplinary_actions`
--

LOCK TABLES `student_disciplinary_actions` WRITE;
/*!40000 ALTER TABLE `student_disciplinary_actions` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_disciplinary_actions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_documents`
--

DROP TABLE IF EXISTS `student_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_hash` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_documents_verified_by_foreign` (`verified_by`),
  KEY `student_documents_user_id_type_index` (`user_id`,`type`),
  CONSTRAINT `student_documents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_documents`
--

LOCK TABLES `student_documents` WRITE;
/*!40000 ALTER TABLE `student_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_educations`
--

DROP TABLE IF EXISTS `student_educations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_educations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `level` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `board_university` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `institute` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stream_subject` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passing_year` year DEFAULT NULL,
  `total_marks` int unsigned DEFAULT NULL,
  `marks_obtained` int unsigned DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `cgpa` decimal(4,2) DEFAULT NULL,
  `division` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completion_status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_highest` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_educations_user_id_index` (`user_id`),
  CONSTRAINT `student_educations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_educations`
--

LOCK TABLES `student_educations` WRITE;
/*!40000 ALTER TABLE `student_educations` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_educations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_employments`
--

DROP TABLE IF EXISTS `student_employments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_employments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `employer_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nature_of_employment` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_employments_user_id_index` (`user_id`),
  CONSTRAINT `student_employments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_employments`
--

LOCK TABLES `student_employments` WRITE;
/*!40000 ALTER TABLE `student_employments` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_employments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_fee_invoices`
--

DROP TABLE IF EXISTS `student_fee_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_fee_invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enrollment_id` bigint unsigned DEFAULT NULL,
  `fee_schedule_id` bigint unsigned DEFAULT NULL,
  `fee_type_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `period_month` tinyint unsigned DEFAULT NULL,
  `period_year` smallint unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `late_fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `due_date` date NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `reminder_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_fee_invoices_invoice_no_unique` (`invoice_no`),
  KEY `student_fee_invoices_enrollment_id_foreign` (`enrollment_id`),
  KEY `student_fee_invoices_fee_schedule_id_foreign` (`fee_schedule_id`),
  KEY `student_fee_invoices_fee_type_id_foreign` (`fee_type_id`),
  KEY `student_fee_invoices_created_by_foreign` (`created_by`),
  KEY `student_fee_invoices_updated_by_foreign` (`updated_by`),
  KEY `student_fee_invoices_deleted_by_foreign` (`deleted_by`),
  KEY `student_fee_invoices_user_id_status_index` (`user_id`,`status`),
  KEY `student_fee_invoices_due_date_status_index` (`due_date`,`status`),
  KEY `student_fee_invoices_student_id_index` (`student_id`),
  CONSTRAINT `student_fee_invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_fee_invoices_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_fee_invoices_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_fee_invoices_fee_schedule_id_foreign` FOREIGN KEY (`fee_schedule_id`) REFERENCES `fee_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_fee_invoices_fee_type_id_foreign` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_fee_invoices_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_fee_invoices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_fee_invoices`
--

LOCK TABLES `student_fee_invoices` WRITE;
/*!40000 ALTER TABLE `student_fee_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_fee_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_ledgers`
--

DROP TABLE IF EXISTS `student_ledgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_ledgers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ledger_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `student_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `balance_after` decimal(10,2) NOT NULL DEFAULT '0.00',
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_date` date NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_ledgers_ledger_no_unique` (`ledger_no`),
  KEY `student_ledgers_created_by_foreign` (`created_by`),
  KEY `student_ledgers_updated_by_foreign` (`updated_by`),
  KEY `student_ledgers_deleted_by_foreign` (`deleted_by`),
  KEY `student_ledgers_user_id_entry_type_index` (`user_id`,`entry_type`),
  KEY `student_ledgers_transaction_date_index` (`transaction_date`),
  KEY `student_ledgers_student_id_index` (`student_id`),
  CONSTRAINT `student_ledgers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_ledgers_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_ledgers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_ledgers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_ledgers`
--

LOCK TABLES `student_ledgers` WRITE;
/*!40000 ALTER TABLE `student_ledgers` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_ledgers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_other_details`
--

DROP TABLE IF EXISTS `student_other_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_other_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `attempts_already_given` int unsigned NOT NULL DEFAULT '0',
  `attempts_left` int unsigned DEFAULT NULL,
  `optional_subject` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_of_appearing` year DEFAULT NULL,
  `activities` text COLLATE utf8mb4_unicode_ci,
  `hobbies` text COLLATE utf8mb4_unicode_ci,
  `dynamic_fields` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_other_details_user_id_unique` (`user_id`),
  CONSTRAINT `student_other_details_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_other_details`
--

LOCK TABLES `student_other_details` WRITE;
/*!40000 ALTER TABLE `student_other_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_other_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_profiles`
--

DROP TABLE IF EXISTS `student_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `father_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_category` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `category_remark` text COLLATE utf8mb4_unicode_ci,
  `subcaste` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creamy_layer` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cert_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `issued_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pwbd` tinyint(1) NOT NULL DEFAULT '0',
  `pwbd_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pwd_percentage` tinyint unsigned NOT NULL DEFAULT '0',
  `pwbd_cert_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pwbd_issued_date` date DEFAULT NULL,
  `pwbd_issued_by` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occupation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_working_professional` tinyint(1) NOT NULL DEFAULT '0',
  `father_occupation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mother_occupation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `family_income` decimal(12,2) DEFAULT NULL,
  `distance` decimal(8,2) DEFAULT NULL COMMENT 'Distance from home to institute in KM',
  `highest_edu` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `school_language` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `upsc_community` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activities` text COLLATE utf8mb4_unicode_ci,
  `hobbies` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_profiles_user_id_unique` (`user_id`),
  KEY `student_profiles_verified_by_foreign` (`verified_by`),
  CONSTRAINT `student_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_profiles_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_profiles`
--

LOCK TABLES `student_profiles` WRITE;
/*!40000 ALTER TABLE `student_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_test_results`
--

DROP TABLE IF EXISTS `student_test_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_test_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_test_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `enrollment_id` bigint unsigned DEFAULT NULL,
  `section_id` bigint unsigned DEFAULT NULL,
  `roll_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attendance_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `total_questions` int unsigned NOT NULL DEFAULT '0',
  `attempted` int unsigned NOT NULL DEFAULT '0',
  `right_answers` int unsigned NOT NULL DEFAULT '0',
  `wrong_answers` int unsigned NOT NULL DEFAULT '0',
  `blank_answers` int unsigned NOT NULL DEFAULT '0',
  `positive_marks` decimal(8,2) NOT NULL DEFAULT '0.00',
  `negative_marks` decimal(8,2) NOT NULL DEFAULT '0.00',
  `marks_obtained` decimal(8,2) NOT NULL DEFAULT '0.00',
  `total_marks` decimal(8,2) NOT NULL DEFAULT '0.00',
  `percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `percentile` decimal(5,2) DEFAULT NULL,
  `rank` int unsigned DEFAULT NULL,
  `is_top_ten` tinyint(1) NOT NULL DEFAULT '0',
  `is_passed` tinyint(1) NOT NULL DEFAULT '1',
  `subject_scores` json DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `feedback` text COLLATE utf8mb4_unicode_ci,
  `evaluated_by` bigint unsigned DEFAULT NULL,
  `evaluated_at` datetime DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_test_results_student_test_id_user_id_unique` (`student_test_id`,`user_id`),
  UNIQUE KEY `student_test_results_uuid_unique` (`uuid`),
  KEY `student_test_results_user_id_foreign` (`user_id`),
  KEY `student_test_results_enrollment_id_foreign` (`enrollment_id`),
  KEY `student_test_results_section_id_foreign` (`section_id`),
  KEY `student_test_results_evaluated_by_foreign` (`evaluated_by`),
  KEY `student_test_results_created_by_foreign` (`created_by`),
  KEY `student_test_results_updated_by_foreign` (`updated_by`),
  KEY `student_test_results_deleted_by_foreign` (`deleted_by`),
  KEY `str_test_att_rank_idx` (`student_test_id`,`attendance_status`,`rank`),
  KEY `student_test_results_roll_number_index` (`roll_number`),
  KEY `student_test_results_marks_obtained_index` (`marks_obtained`),
  KEY `student_test_results_rank_index` (`rank`),
  KEY `student_test_results_is_top_ten_index` (`is_top_ten`),
  CONSTRAINT `student_test_results_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_test_results_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_test_results_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_test_results_evaluated_by_foreign` FOREIGN KEY (`evaluated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_test_results_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_test_results_student_test_id_foreign` FOREIGN KEY (`student_test_id`) REFERENCES `student_tests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_test_results_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_test_results_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_test_results`
--

LOCK TABLES `student_test_results` WRITE;
/*!40000 ALTER TABLE `student_test_results` DISABLE KEYS */;
INSERT INTO `student_test_results` VALUES (1,'228f8905-6ddd-4d05-8585-9b52266c5954',1,3,1,1,'202600001','present',100,0,82,12,6,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Outstanding performance in CSAT and Polity; maintain consistency.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'c8a689dd-019f-43c9-800e-5756eac4037f',1,4,2,1,'202600002','present',100,0,78,14,8,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Very good accuracy across GS Paper 1. Focus on modern history.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'67ebd1a2-df64-4d8d-b23f-bad38f4cb46d',1,5,3,1,'202600003','present',100,0,75,15,10,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Consistent performance. Well placed in top percentile.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'1c63e010-7a03-4d60-9495-3eb2ee60adbb',1,6,4,1,'202600004','present',100,0,72,18,10,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Good analytical reasoning, work on negative reduction.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'66bb2283-bd12-4f04-97b1-aacd68ecf3f8',1,7,5,2,'202600005','present',100,0,68,20,12,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Solid attempt rate, review economic survey questions.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(6,'d16db1cd-671e-45d9-b46e-f4d092ee7699',1,8,6,2,'202600006','present',100,0,65,22,13,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Satisfactory. Revision of geography fundamentals required.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(7,'acef6a1c-e12e-4b3f-956b-1cdf1ab6057c',1,9,7,1,'202600007','present',100,0,62,25,13,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Above qualifying cutoff. Improve CSAT comprehension speed.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(8,'575de49e-d13d-42b9-be6c-dbea0795a22e',1,10,8,2,'202600008','present',100,0,58,28,14,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Decent effort. Need to avoid wild guesses.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(9,'2c6176d4-4d19-465c-8c49-1acbf182ef72',1,11,9,1,'202600009','present',100,0,55,30,15,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Qualifying baseline met. Work on time management.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(10,'804e5fab-f91f-4aec-90e1-c56452b30918',1,12,10,2,'202600010','present',100,0,52,32,16,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Borderline performance. Regular mock revision advised.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(11,'496f6561-ea86-4246-9455-6739661a6dad',1,13,11,1,'202600011','present',100,0,48,35,17,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Below expected accuracy. Comprehensive revision needed.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(12,'82723d87-0bd7-47f4-b837-1843fd824c9f',1,14,12,2,'202600012','present',100,0,44,38,18,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'High negative penalty. Focus on high-confidence questions.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(13,'5ab6ff9b-7fb4-4254-a945-0e838c45d163',1,15,13,1,'202600013','present',100,0,40,42,18,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Needs significant improvement. Schedule mentor session.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(14,'66b00fd6-8a62-46d0-a784-cd8b6cc4767e',1,16,14,2,'202600014','present',100,0,82,12,6,0.00,0.00,0.00,200.00,0.00,0.00,1,1,0,NULL,'Outstanding performance in CSAT and Polity; maintain consistency.','Strengthen revision on Indian Polity Articles and CSAT Data Interpretation.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(15,'68206606-9774-4423-bfb2-88d6fca0ec86',1,17,15,2,'202600015','absent',100,0,0,0,100,0.00,0.00,0.00,200.00,0.00,NULL,NULL,0,0,NULL,'Absent with prior leave notice.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(16,'3204089d-c1ac-40d4-a4fa-28085ad4a5a7',2,3,1,1,'202600001','present',50,0,28,8,14,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(17,'d805901b-3e50-4f35-9a8c-5ca66d6224b9',2,4,2,1,'202600002','present',50,0,29,6,15,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(18,'e406ce94-1932-4311-a7cb-18911fbf29f1',2,5,3,1,'202600003','present',50,0,26,10,14,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(19,'e33c823f-af48-4d31-87b6-90e2ff83fb6c',2,6,4,1,'202600004','present',50,0,33,7,10,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(20,'519d9b32-c11e-4d68-8c88-274dd27e31a0',2,7,5,2,'202600005','present',50,0,31,5,14,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(21,'d9137df4-86d3-4e90-9b64-afa99254ccc3',2,8,6,2,'202600006','present',50,0,38,10,2,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(22,'fdb162c8-3ce8-4a45-a1c2-bec8cd101bac',2,9,7,1,'202600007','present',50,0,34,8,8,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(23,'d05db796-ca65-4da7-82c2-5688260201b1',2,10,8,2,'202600008','present',50,0,28,8,14,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(24,'9a834390-e33c-4636-85a8-f28220bdc062',2,11,9,1,'202600009','present',50,0,38,10,2,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(25,'43c61b3c-629a-48a3-a750-5441cf505102',2,12,10,2,'202600010','present',50,0,26,10,14,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(26,'96ddd7cc-d100-4fa1-adfb-16bc360c19d4',2,13,11,1,'202600011','present',50,0,28,4,18,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(27,'12d4e995-fcea-40ae-aaea-583f2d7f6741',2,14,12,2,'202600012','present',50,0,26,8,16,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(28,'b0de1a07-c263-4873-9202-73a732e42641',2,15,13,1,'202600013','present',50,0,28,6,16,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(29,'df12bd0b-d9c5-4f56-a89a-979637e64e74',2,16,14,2,'202600014','present',50,0,33,7,10,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(30,'e45569be-6558-4382-a74c-6b84774822e8',2,17,15,2,'202600015','present',50,0,32,4,14,0.00,0.00,0.00,100.00,0.00,0.00,1,1,0,NULL,'Good historical chronological recall.',NULL,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(31,'2c5a4c92-c340-47ec-8d42-5ca2d32aa204',3,3,1,1,'202600001','present',20,0,0,0,0,0.00,0.00,129.00,250.00,51.60,62.50,3,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(32,'889a8fd3-fa02-47df-a3fa-47f69cd93e96',3,4,2,1,'202600002','present',20,0,0,0,0,0.00,0.00,131.00,250.00,52.40,75.00,2,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(33,'be0a9413-b67c-4468-9647-070a85651ddf',3,5,3,1,'202600003','present',20,0,0,0,0,0.00,0.00,132.00,250.00,52.80,87.50,1,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(34,'52bf17e6-cb8d-4e7a-955a-6a0a58633759',3,6,4,1,'202600004','present',20,0,0,0,0,0.00,0.00,128.00,250.00,51.20,50.00,4,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(35,'2ebb1157-5621-4af8-ae88-055897379868',3,9,7,1,'202600007','present',20,0,0,0,0,0.00,0.00,111.00,250.00,44.40,0.00,8,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(36,'283b6521-357e-4cb3-a691-6c7f24d91d55',3,11,9,1,'202600009','present',20,0,0,0,0,0.00,0.00,115.00,250.00,46.00,25.00,6,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(37,'f835b0af-12d8-41e0-9171-ab7f72f3e8ec',3,13,11,1,'202600011','present',20,0,0,0,0,0.00,0.00,113.00,250.00,45.20,12.50,7,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(38,'7111ca2a-0124-4fc4-950c-a6b5e1c9809e',3,15,13,1,'202600013','present',20,0,0,0,0,0.00,0.00,121.00,250.00,48.40,37.50,5,1,1,NULL,'Good answer structuring with constitutional provisions and Supreme Court judgements.','Work on introduction brevity and conclude with forward-looking way forward.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(39,'f3cfe9f6-58ed-4317-bd1b-c5d177aa3718',5,3,1,1,'202600001','present',80,70,62,8,10,155.00,6.64,148.36,200.00,74.18,90.00,1,1,1,NULL,'Excellent comprehension and reasoning speed; comfortably qualified.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(40,'6b9ed5ce-e76a-4e95-aa6f-7eeca41e48df',5,4,2,1,'202600002','present',80,68,58,10,12,145.00,8.30,136.70,200.00,68.35,80.00,2,1,1,NULL,'Strong quantitative aptitude. Well clear of qualifying mark.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(41,'017a2451-524f-47d3-ba73-bc7295bb9cc1',5,5,3,1,'202600003','present',80,66,54,12,14,135.00,9.96,125.04,200.00,62.52,70.00,3,1,1,NULL,'Good reading comprehension accuracy; safe qualifying margin.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(42,'1b5b3784-0ebd-4e74-81e9-38a61ce4ea0f',5,6,4,1,'202600004','present',80,65,50,15,15,125.00,12.45,112.55,200.00,56.28,60.00,4,1,1,NULL,'Solid performance in logic and data interpretation.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(43,'97d51e96-8935-4e59-bd48-966e8c74e8c9',5,7,5,2,'202600005','present',80,62,46,16,18,115.00,13.28,101.72,200.00,50.86,50.00,5,1,1,NULL,'Above qualifying cutoff. Maintain focus on reading speed.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(44,'43a29f8c-75bb-4144-a0f2-e7bf5d6ab719',5,8,6,2,'202600006','present',80,60,42,18,20,105.00,14.94,90.06,200.00,45.03,40.00,6,1,1,NULL,'Safely qualified (≥33%). Good accuracy balance.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(45,'7c7236f3-e414-4e3b-aaa4-1ab1990b72ca',5,9,7,1,'202600007','present',80,58,38,20,22,95.00,16.60,78.40,200.00,39.20,30.00,7,1,1,NULL,'Qualified. Focus on eliminating borderline guesses.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(46,'52b87e25-ebb1-445d-87ff-4b84710fea8c',5,10,8,2,'202600008','present',80,56,34,22,24,85.00,18.26,66.74,200.00,33.37,20.00,8,1,1,NULL,'Barely qualified above 66.67 mark cutoff. Remedial practice recommended.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(47,'88ad79a2-8b73-4276-8c36-1dffaa15080a',5,11,9,1,'202600009','present',80,54,30,24,26,75.00,19.92,55.08,200.00,27.54,10.00,9,1,0,NULL,'Below 33% qualifying benchmark. Intensive CSAT practice required.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(48,'a1b54f28-e2e0-4f69-a86e-3992f964095b',5,12,10,2,'202600010','present',80,54,26,28,26,65.00,23.24,41.76,200.00,20.88,0.00,10,1,0,NULL,'Failed to meet 33% qualifying cutoff. High negative penalty.','UPSC CSAT requires minimum 33% (66.67 Marks). Practice previous years questions regularly.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `student_test_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_test_subjects`
--

DROP TABLE IF EXISTS `student_test_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_test_subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_test_id` bigint unsigned NOT NULL,
  `subject_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `questions_count` int unsigned NOT NULL DEFAULT '0',
  `max_marks` decimal(8,2) NOT NULL DEFAULT '0.00',
  `marks_per_question` decimal(5,2) NOT NULL DEFAULT '2.00',
  `negative_marking` decimal(4,2) NOT NULL DEFAULT '0.66',
  `display_order` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sts_test_order_idx` (`student_test_id`,`display_order`),
  CONSTRAINT `student_test_subjects_student_test_id_foreign` FOREIGN KEY (`student_test_id`) REFERENCES `student_tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_test_subjects`
--

LOCK TABLES `student_test_subjects` WRITE;
/*!40000 ALTER TABLE `student_test_subjects` DISABLE KEYS */;
INSERT INTO `student_test_subjects` VALUES (1,1,'General Studies (Paper 1)','GS-1',50,100.00,2.00,0.66,1,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(2,1,'CSAT Aptitude (Paper 2)','CSAT',50,100.00,2.00,0.66,2,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(3,2,'Modern Indian History','HIST',50,100.00,2.00,0.66,1,'2026-11-01 20:15:06','2026-11-01 20:15:06'),(4,5,'CSAT / General Studies Paper II (Qualifying)','CSAT-2',80,200.00,2.50,0.83,1,'2026-11-01 20:15:06','2026-11-01 20:15:06');
/*!40000 ALTER TABLE `student_test_subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_tests`
--

DROP TABLE IF EXISTS `student_tests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_tests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `section_id` bigint unsigned DEFAULT NULL,
  `test_stage` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'prelims',
  `test_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mcq',
  `test_for` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mock_test',
  `test_number` int unsigned NOT NULL DEFAULT '1',
  `total_marks` decimal(8,2) NOT NULL DEFAULT '100.00',
  `pass_marks` decimal(8,2) DEFAULT NULL,
  `total_questions` int unsigned NOT NULL DEFAULT '0',
  `mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `test_date` date NOT NULL,
  `test_day` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `end_time` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_minutes` int unsigned NOT NULL DEFAULT '120',
  `venue_room` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `question_paper_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `question_paper_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_answer_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_answer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `solutions_published_at` datetime DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `negative_marking_ratio` decimal(4,2) NOT NULL DEFAULT '0.33',
  `marks_per_question` decimal(5,2) NOT NULL DEFAULT '2.00',
  `notify_students` tinyint(1) NOT NULL DEFAULT '0',
  `notification_channels` json DEFAULT NULL,
  `notification_sent_at` datetime DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_tests_uuid_unique` (`uuid`),
  UNIQUE KEY `student_tests_code_unique` (`code`),
  KEY `student_tests_course_id_foreign` (`course_id`),
  KEY `student_tests_section_id_foreign` (`section_id`),
  KEY `student_tests_created_by_foreign` (`created_by`),
  KEY `student_tests_updated_by_foreign` (`updated_by`),
  KEY `student_tests_deleted_by_foreign` (`deleted_by`),
  KEY `st_batch_course_sec_idx` (`batch_id`,`course_id`,`section_id`),
  KEY `st_date_status_idx` (`test_date`,`status`),
  KEY `st_stage_type_for_idx` (`test_stage`,`test_type`,`test_for`),
  CONSTRAINT `student_tests_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_tests_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_tests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_tests_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_tests_section_id_foreign` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `student_tests_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_tests`
--

LOCK TABLES `student_tests` WRITE;
/*!40000 ALTER TABLE `student_tests` DISABLE KEYS */;
INSERT INTO `student_tests` VALUES (1,'c5187745-a884-487c-83b5-1ae205fa53df','TEST-2026-GS-01','UPSC Prelims Mock Test #1 - GS Paper 1 & CSAT',1,1,NULL,'prelims','mcq','full_length_test',1,200.00,66.00,100,'offline','2026-10-18','Sunday','10:00 AM','12:00 PM',120,'Salt Lake Main Examination Hall A & B',NULL,NULL,NULL,NULL,'2026-10-18 13:00:00','completed','Negative marking of 1/3rd (0.66 marks) per incorrect response. Use black ball point pen only.',0.33,2.00,0,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(2,'6fceada1-8b4d-40fc-a365-f92028ec8e72','TEST-2026-HIST-02','UPSC Sectional Test #2 - Modern Indian History & Freedom Struggle',1,1,NULL,'prelims','mcq','sectional_test',2,100.00,40.00,50,'offline','2026-10-25','Sunday','10:00 AM','11:00 AM',60,'Hall 2 (Salt Lake)',NULL,NULL,NULL,NULL,'2026-10-25 12:00:00','completed','Focus on 1857 revolt, Congress sessions, Gandhian era, and constitutional developments.',0.33,2.00,0,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(3,'f81c2810-5d04-40bb-aa2d-1648d69eee12','TEST-2026-MAINS-01','UPSC Mains Answer Writing #1 - GS Paper 2 (Governance & Constitution)',1,1,1,'mains','answer_writing','class_test',1,250.00,100.00,20,'offline','2026-10-27','Tuesday','02:00 PM','05:00 PM',180,'Classroom 301, Section A Wing',NULL,NULL,NULL,NULL,'2026-10-27 18:00:00','completed','Answer all 20 questions in prescribed word limits (150 words for 10M, 250 words for 15M).',0.33,2.00,0,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(4,'7e51161a-de00-4ac9-98a9-795d21d5793c','TEST-2026-GS-03','UPSC Prelims Full Mock #3 - Indian Economy, Budget & Environment',1,1,NULL,'prelims','mcq','full_length_test',3,200.00,66.00,100,'offline','2026-11-01','Sunday','10:00 AM','12:00 PM',120,'Main Campus Halls 1, 2 & 3',NULL,NULL,NULL,NULL,NULL,'scheduled','Bring admit card and black ballpoint pen. Reporting time 09:30 AM.',0.33,2.00,1,'[\"in_app\", \"email\", \"whatsapp\"]',NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL),(5,'9eda762b-78c6-49b6-89a3-d0f1ce0a4ac9','TEST-2026-CSAT-01','UPSC Prelims CSAT Paper II Full Mock Test',1,1,NULL,'prelims','mcq','full_length_test',4,200.00,66.67,80,'offline','2026-10-26','Monday','02:30 PM','04:30 PM',120,'Salt Lake Main Examination Hall A',NULL,NULL,NULL,NULL,'2026-10-26 17:00:00','completed','1. CSAT / General Studies Paper II is Qualifying Only (Requires ≥ 33% / 66.67 Marks).\n2. Total Questions: 80 | Maximum Marks: 200.\n3. Each correct answer carries +2.50 Marks.\n4. Penalty for wrong answer is 1/3rd (-0.83 Marks).\n5. Unattempted questions carry zero penalty.',0.33,2.50,0,NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL);
/*!40000 ALTER TABLE `student_tests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_material_access_logs`
--

DROP TABLE IF EXISTS `study_material_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_material_access_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `study_material_id` bigint unsigned NOT NULL,
  `study_material_file_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `subscriber_id` bigint unsigned DEFAULT NULL,
  `action` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'view',
  `guest_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guest_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guest_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `study_material_access_logs_study_material_id_foreign` (`study_material_id`),
  KEY `study_material_access_logs_study_material_file_id_foreign` (`study_material_file_id`),
  KEY `study_material_access_logs_user_id_foreign` (`user_id`),
  KEY `study_material_access_logs_subscriber_id_foreign` (`subscriber_id`),
  KEY `study_material_access_logs_is_verified_index` (`is_verified`),
  CONSTRAINT `study_material_access_logs_study_material_file_id_foreign` FOREIGN KEY (`study_material_file_id`) REFERENCES `study_material_files` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_material_access_logs_study_material_id_foreign` FOREIGN KEY (`study_material_id`) REFERENCES `study_materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `study_material_access_logs_subscriber_id_foreign` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_material_access_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_material_access_logs`
--

LOCK TABLES `study_material_access_logs` WRITE;
/*!40000 ALTER TABLE `study_material_access_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `study_material_access_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_material_batches`
--

DROP TABLE IF EXISTS `study_material_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_material_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `study_material_id` bigint unsigned NOT NULL,
  `batch_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sm_batch_unique` (`study_material_id`,`batch_id`),
  KEY `study_material_batches_batch_id_foreign` (`batch_id`),
  CONSTRAINT `study_material_batches_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `study_material_batches_study_material_id_foreign` FOREIGN KEY (`study_material_id`) REFERENCES `study_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_material_batches`
--

LOCK TABLES `study_material_batches` WRITE;
/*!40000 ALTER TABLE `study_material_batches` DISABLE KEYS */;
INSERT INTO `study_material_batches` VALUES (1,1,1,'2026-11-01 20:15:07','2026-11-01 20:15:07');
/*!40000 ALTER TABLE `study_material_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_material_files`
--

DROP TABLE IF EXISTS `study_material_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_material_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `study_material_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `file_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `download_count` int unsigned NOT NULL DEFAULT '0',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `study_material_files_study_material_id_foreign` (`study_material_id`),
  CONSTRAINT `study_material_files_study_material_id_foreign` FOREIGN KEY (`study_material_id`) REFERENCES `study_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_material_files`
--

LOCK TABLES `study_material_files` WRITE;
/*!40000 ALTER TABLE `study_material_files` DISABLE KEYS */;
INSERT INTO `study_material_files` VALUES (1,1,'UPSC_CSAT_Analytical_Reasoning_Handout_01.pdf','study_materials/sample_csat_reasoning_handout.pdf',1428500,'pdf','application/pdf','local',45,1,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(2,2,'PIB_Sandhan_October_2026_Digest.pdf','study_materials/pib_sandhan_october_2026.pdf',2854000,'pdf','application/pdf','local',120,1,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `study_material_files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_material_shares`
--

DROP TABLE IF EXISTS `study_material_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_material_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `study_material_id` bigint unsigned NOT NULL,
  `channel` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'batch',
  `recipient_id` bigint unsigned DEFAULT NULL,
  `recipient_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `shared_by` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `study_material_shares_study_material_id_foreign` (`study_material_id`),
  KEY `study_material_shares_shared_by_foreign` (`shared_by`),
  KEY `study_material_shares_channel_index` (`channel`),
  CONSTRAINT `study_material_shares_shared_by_foreign` FOREIGN KEY (`shared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_material_shares_study_material_id_foreign` FOREIGN KEY (`study_material_id`) REFERENCES `study_materials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_material_shares`
--

LOCK TABLES `study_material_shares` WRITE;
/*!40000 ALTER TABLE `study_material_shares` DISABLE KEYS */;
/*!40000 ALTER TABLE `study_material_shares` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_material_students`
--

DROP TABLE IF EXISTS `study_material_students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_material_students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `study_material_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sm_student_unique` (`study_material_id`,`user_id`),
  KEY `study_material_students_user_id_foreign` (`user_id`),
  CONSTRAINT `study_material_students_study_material_id_foreign` FOREIGN KEY (`study_material_id`) REFERENCES `study_materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `study_material_students_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_material_students`
--

LOCK TABLES `study_material_students` WRITE;
/*!40000 ALTER TABLE `study_material_students` DISABLE KEYS */;
/*!40000 ALTER TABLE `study_material_students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_material_types`
--

DROP TABLE IF EXISTS `study_material_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_material_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'file-text',
  `badge_color` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'primary',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `study_material_types_code_unique` (`code`),
  KEY `study_material_types_created_by_foreign` (`created_by`),
  KEY `study_material_types_updated_by_foreign` (`updated_by`),
  KEY `study_material_types_deleted_by_foreign` (`deleted_by`),
  KEY `study_material_types_is_public_index` (`is_public`),
  KEY `study_material_types_is_active_index` (`is_active`),
  KEY `study_material_types_display_order_index` (`display_order`),
  CONSTRAINT `study_material_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_material_types_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_material_types_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_material_types`
--

LOCK TABLES `study_material_types` WRITE;
/*!40000 ALTER TABLE `study_material_types` DISABLE KEYS */;
INSERT INTO `study_material_types` VALUES (1,'Current Affairs Capsules','current_affairs','Monthly compilations of national and international developments','globe','emerald',1,1,1,2,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:15:07',NULL),(2,'PIB Sandhan','pib_sandhan','Press Information Bureau government releases, schemes, and policy digests','newspaper','amber',1,1,1,3,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:15:07',NULL),(3,'Lecture Notes / Handouts','lecture_notes','Classroom faculty notes, synopsis, and handout modules.','file-text','blue',0,1,1,3,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(4,'E-Book / Reference PDF','e_book_pdf','Curated reference books, standard texts, and government publications.','book-open','amber',1,1,1,4,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(5,'Presentation / Slide Deck','slide_deck','Faculty slide presentations and visual teaching aids.','presentation','purple',0,1,1,5,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(6,'Video Lecture / Recording','video_recording','High-definition classroom lecture recordings and recorded masterclasses.','video','rose',0,1,1,6,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(7,'Audio Lecture / Podcast','audio_lecture','Audio discussions, expert roundtables, and recorded lectures.','headphones','cyan',0,1,1,7,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(8,'Question Bank / Mock Test','question_bank','Previous years question analyses, model answer blueprints, and mock test papers.','help-circle','indigo',1,1,1,8,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(9,'Syllabus & Blueprint','syllabus','Detailed curriculum roadmaps, micro-syllabi, and subject blueprints.','compass','teal',1,1,1,9,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(10,'Assignment / Worksheet','assignment_practice','Mains answer writing exercises, case study evaluations, and worksheets.','clipboard-list','slate',0,1,1,10,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(11,'Reference Compendium','handout_reference','Annual reports, economic surveys, budget summaries, and reference compendia.','bookmark','zinc',1,1,1,11,NULL,NULL,NULL,'2026-11-01 20:14:36','2026-11-01 20:14:36',NULL),(12,'Lecture Handouts','handout','Comprehensive classroom lecture summaries and analytical handouts','file-text','blue',1,0,1,1,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(13,'E-Books & Compendiums','ebook','Official standard reference textbooks and subject monographs','book-open','purple',0,0,1,4,NULL,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `study_material_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `study_materials`
--

DROP TABLE IF EXISTS `study_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `study_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topic` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `module_code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lecture_notes',
  `description` text COLLATE utf8mb4_unicode_ci,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `external_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_url` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `class_date` date DEFAULT NULL,
  `access_level` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enrolled_only',
  `share_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `share_expires_at` timestamp NULL DEFAULT NULL,
  `share_password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `download_count` int unsigned NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `study_materials_uuid_unique` (`uuid`),
  UNIQUE KEY `study_materials_share_token_unique` (`share_token`),
  KEY `study_materials_course_id_foreign` (`course_id`),
  KEY `study_materials_created_by_foreign` (`created_by`),
  KEY `study_materials_updated_by_foreign` (`updated_by`),
  KEY `study_materials_deleted_by_foreign` (`deleted_by`),
  KEY `study_materials_subject_index` (`subject`),
  KEY `study_materials_topic_index` (`topic`),
  KEY `study_materials_material_type_index` (`material_type`),
  KEY `study_materials_access_level_index` (`access_level`),
  KEY `study_materials_is_pinned_index` (`is_pinned`),
  KEY `study_materials_status_index` (`status`),
  KEY `study_materials_class_date_index` (`class_date`),
  KEY `study_materials_display_order_index` (`display_order`),
  CONSTRAINT `study_materials_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `study_materials_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_materials_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `study_materials_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `study_materials`
--

LOCK TABLES `study_materials` WRITE;
/*!40000 ALTER TABLE `study_materials` DISABLE KEYS */;
INSERT INTO `study_materials` VALUES (1,'826859ea-fc95-4b8c-b798-993e66081ea6',1,'UPSC CSAT General Studies Paper II — Analytical Reasoning & Data Interpretation','upsc-csat-gs-paper-2-analytical-reasoning','General Studies (CSAT)','Analytical Reasoning and Problem Solving','CSAT-MOD-01','handout','Complete lecture handout containing conceptual clarity, shortcut techniques, and 50 practice problems for UPSC Civil Services preliminary aspirants.','Comprehensive analytical problem sets, previous year questions (PYQs) 2018-2025, and step-by-step model solutions.',NULL,NULL,NULL,'2026-10-31','enrolled_only','6RDm5GyKXIU1jolRzr7tS4g4OptFgvThuQevNq3o',NULL,NULL,1,1,'published',142,89,1,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(2,'04a10003-8cd2-448b-9938-f7aba5143394',NULL,'PIB Sandhan — October 2026 Monthly Digest of Government Schemes & Initiatives','pib-sandhan-october-2026-monthly-digest','Current Affairs & Governance','Union Cabinet Decisions & National Schemes','PIB-2026-10','pib_sandhan','Curated monthly compendium of PIB announcements, official gazette digests, and socio-economic welfare policies for Civil Services preparation.','High-yield coverage of flagship schemes, infrastructure corridors, and green energy missions.',NULL,NULL,NULL,'2026-11-01','public_link','tD3rsOaqOKjGKisLUEdrAfyHxsvvloqSC5AqUegi',NULL,NULL,1,2,'published',520,312,1,NULL,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `study_materials` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscribers`
--

DROP TABLE IF EXISTS `subscribers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscribers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '+91',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'welcome_page',
  `tags` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `subscribed_at` timestamp NULL DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `unsubscribe_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unsubscribe_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscribers_uuid_unique` (`uuid`),
  UNIQUE KEY `subscribers_unsubscribe_token_unique` (`unsubscribe_token`),
  KEY `subscribers_created_by_foreign` (`created_by`),
  KEY `subscribers_updated_by_foreign` (`updated_by`),
  KEY `subscribers_deleted_by_foreign` (`deleted_by`),
  KEY `subscribers_email_index` (`email`),
  KEY `subscribers_phone_index` (`phone`),
  CONSTRAINT `subscribers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscribers_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subscribers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscribers`
--

LOCK TABLES `subscribers` WRITE;
/*!40000 ALTER TABLE `subscribers` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscribers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_invitations`
--

DROP TABLE IF EXISTS `team_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `team_id` bigint unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invited_by` bigint unsigned NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_invitations_code_unique` (`code`),
  KEY `team_invitations_team_id_foreign` (`team_id`),
  KEY `team_invitations_invited_by_foreign` (`invited_by`),
  CONSTRAINT `team_invitations_invited_by_foreign` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_invitations_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_invitations`
--

LOCK TABLES `team_invitations` WRITE;
/*!40000 ALTER TABLE `team_invitations` DISABLE KEYS */;
/*!40000 ALTER TABLE `team_invitations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_members`
--

DROP TABLE IF EXISTS `team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_members_team_id_user_id_unique` (`team_id`,`user_id`),
  KEY `team_members_user_id_foreign` (`user_id`),
  CONSTRAINT `team_members_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_members`
--

LOCK TABLES `team_members` WRITE;
/*!40000 ALTER TABLE `team_members` DISABLE KEYS */;
INSERT INTO `team_members` VALUES (1,1,1,'owner','2026-11-01 20:14:59','2026-11-01 20:14:59'),(2,2,2,'owner','2026-11-01 20:15:01','2026-11-01 20:15:01');
/*!40000 ALTER TABLE `team_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_personal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teams_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teams`
--

LOCK TABLES `teams` WRITE;
/*!40000 ALTER TABLE `teams` DISABLE KEYS */;
INSERT INTO `teams` VALUES (1,'Test User\'s Team','reynolds-walker',1,'2026-11-01 20:14:59','2026-11-01 20:14:59',NULL),(2,'SNT CSSC','sntcssc',0,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL);
/*!40000 ALTER TABLE `teams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_attachments`
--

DROP TABLE IF EXISTS `ticket_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `ticket_message_id` bigint unsigned DEFAULT NULL,
  `uploaded_by_user_id` bigint unsigned DEFAULT NULL,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_attachments_ticket_message_id_foreign` (`ticket_message_id`),
  KEY `ticket_attachments_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `ticket_attachments_ticket_id_index` (`ticket_id`),
  CONSTRAINT `ticket_attachments_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_attachments_ticket_message_id_foreign` FOREIGN KEY (`ticket_message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_attachments_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_attachments`
--

LOCK TABLES `ticket_attachments` WRITE;
/*!40000 ALTER TABLE `ticket_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_canned_responses`
--

DROP TABLE IF EXISTS `ticket_canned_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_canned_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shortcut` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` bigint unsigned DEFAULT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_canned_responses_category_id_foreign` (`category_id`),
  KEY `ticket_canned_responses_created_by_foreign` (`created_by`),
  KEY `ticket_canned_responses_shortcut_index` (`shortcut`),
  CONSTRAINT `ticket_canned_responses_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `ticket_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ticket_canned_responses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_canned_responses`
--

LOCK TABLES `ticket_canned_responses` WRITE;
/*!40000 ALTER TABLE `ticket_canned_responses` DISABLE KEYS */;
INSERT INTO `ticket_canned_responses` VALUES (1,'Greeting & Acknowledgment','/ack',NULL,'Hello {user_name},\n\nThank you for contacting {app_name} Support regarding \'{ticket_subject}\' (Ticket #{ticket_number}). Our support team has logged your inquiry and is currently reviewing it.\n\nWe will provide an update shortly.\n\nWarm regards,\n{agent_name}',1,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(2,'Payment Screenshot Request','/fee-info',NULL,'Dear {user_name},\n\nTo help us verify your transaction for Ticket #{ticket_number}, could you kindly reply with your payment reference / UTR number or an attached transaction screenshot?\n\nThank you,\n{agent_name} - Accounts Desk',1,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(3,'Issue Resolved Notice','/resolved',NULL,'Dear {user_name},\n\nWe have marked your ticket #{ticket_number} as resolved. If you require further assistance or if anything remains unclear, please feel free to reply directly to reopen this ticket.\n\nThank you for choosing {app_name}!\n\nBest regards,\n{agent_name}',1,NULL,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `ticket_canned_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_categories`
--

DROP TABLE IF EXISTS `ticket_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tag',
  `color_badge` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'blue',
  `default_priority` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `sla_response_hours` int unsigned NOT NULL DEFAULT '24',
  `sla_resolution_hours` int unsigned NOT NULL DEFAULT '72',
  `default_assigned_user_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_categories_slug_unique` (`slug`),
  KEY `ticket_categories_default_assigned_user_id_foreign` (`default_assigned_user_id`),
  CONSTRAINT `ticket_categories_default_assigned_user_id_foreign` FOREIGN KEY (`default_assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_categories`
--

LOCK TABLES `ticket_categories` WRITE;
/*!40000 ALTER TABLE `ticket_categories` DISABLE KEYS */;
INSERT INTO `ticket_categories` VALUES (1,'Technical Support','technical-support','Website, portal logins, password issues, video player or mock test technical errors.','cpu','indigo','medium',12,48,NULL,1,1,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(2,'Admissions & Enrollments','admissions-enrollments','Candidate entrance exams, admission forms, selection lists, and registration status.','user-plus','emerald','high',6,24,NULL,1,2,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(3,'Courses & Academic Queries','courses-academic-queries','Syllabus inquiries, batch timings, study materials, faculty guidance, and lecture schedules.','book-open','blue','medium',24,72,NULL,1,3,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(4,'Fee & Payment Verification','fee-payment-verification','Online payment confirmations, receipts, refund requests, and fee installment queries.','credit-card','amber','high',6,24,NULL,1,4,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL),(5,'General Inquiries','general-inquiries','General institutional queries, office timings, campus facilities, and feedback.','help-circle','zinc','low',24,72,NULL,1,5,'2026-11-01 20:15:07','2026-11-01 20:15:07',NULL);
/*!40000 ALTER TABLE `ticket_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_messages`
--

DROP TABLE IF EXISTS `ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `sender_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public_reply',
  `message` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_messages_user_id_foreign` (`user_id`),
  KEY `ticket_messages_ticket_id_type_index` (`ticket_id`,`type`),
  KEY `ticket_messages_ticket_id_created_at_index` (`ticket_id`,`created_at`),
  CONSTRAINT `ticket_messages_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_messages`
--

LOCK TABLES `ticket_messages` WRITE;
/*!40000 ALTER TABLE `ticket_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_number` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `guest_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guest_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guest_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` bigint unsigned DEFAULT NULL,
  `assigned_to_user_id` bigint unsigned DEFAULT NULL,
  `priority` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'portal',
  `last_reply_at` timestamp NULL DEFAULT NULL,
  `last_reply_by_user_id` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `first_response_due_at` timestamp NULL DEFAULT NULL,
  `resolution_due_at` timestamp NULL DEFAULT NULL,
  `first_responded_at` timestamp NULL DEFAULT NULL,
  `is_sla_response_breached` tinyint(1) NOT NULL DEFAULT '0',
  `is_sla_resolution_breached` tinyint(1) NOT NULL DEFAULT '0',
  `satisfaction_rating` tinyint unsigned DEFAULT NULL,
  `satisfaction_feedback` text COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tickets_ticket_number_unique` (`ticket_number`),
  UNIQUE KEY `tickets_uuid_unique` (`uuid`),
  KEY `tickets_last_reply_by_user_id_foreign` (`last_reply_by_user_id`),
  KEY `tickets_deleted_by_foreign` (`deleted_by`),
  KEY `tickets_status_priority_index` (`status`,`priority`),
  KEY `tickets_category_id_status_index` (`category_id`,`status`),
  KEY `tickets_user_id_status_index` (`user_id`,`status`),
  KEY `tickets_assigned_to_user_id_status_index` (`assigned_to_user_id`,`status`),
  KEY `tickets_last_reply_at_index` (`last_reply_at`),
  CONSTRAINT `tickets_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `ticket_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_last_reply_by_user_id_foreign` FOREIGN KEY (`last_reply_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `upsc_attempts`
--

DROP TABLE IF EXISTS `upsc_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `upsc_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `exam_year` year NOT NULL,
  `roll_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prelims_cleared` tinyint(1) NOT NULL DEFAULT '0',
  `mains_cleared` tinyint(1) NOT NULL DEFAULT '0',
  `interview_cleared` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `upsc_attempts_user_id_exam_year_index` (`user_id`,`exam_year`),
  CONSTRAINT `upsc_attempts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `upsc_attempts`
--

LOCK TABLES `upsc_attempts` WRITE;
/*!40000 ALTER TABLE `upsc_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `upsc_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `urn` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `student_id` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp_no` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tenth_roll` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `preferences` json DEFAULT NULL,
  `biometric_pin` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rfid_card_no` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employee_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `staff_department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `failed_login_attempts` int unsigned NOT NULL DEFAULT '0',
  `locked_untill` timestamp NULL DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_team_id` bigint unsigned DEFAULT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_uuid_unique` (`uuid`),
  UNIQUE KEY `users_urn_unique` (`urn`),
  UNIQUE KEY `users_student_id_unique` (`student_id`),
  UNIQUE KEY `users_biometric_pin_unique` (`biometric_pin`),
  UNIQUE KEY `users_rfid_card_no_unique` (`rfid_card_no`),
  UNIQUE KEY `users_employee_id_unique` (`employee_id`),
  KEY `users_current_team_id_foreign` (`current_team_id`),
  KEY `users_deleted_by_foreign` (`deleted_by`),
  KEY `users_updated_by_foreign` (`updated_by`),
  KEY `users_created_by_foreign` (`created_by`),
  KEY `users_status_index` (`status`),
  CONSTRAINT `users_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_current_team_id_foreign` FOREIGN KEY (`current_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'6afdf6ed-73e2-4079-be4a-8db5538c9ec4','20261102099911',NULL,'Test User','Adella','Koch','test@example.com','+919834343789','+919813803284','1999-01-27','other','WB-10-830437','pan','5245-7761-6869','Admissions Officer','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:14:59','2026-11-01 20:14:59','$2y$12$BEbuqmo3JwsPXhzsD50rM.2l43aqWo8J2b8.wtD1ePLqHzM./sOdm',1,NULL,NULL,NULL,'Nz4cJ4YGS9','2026-11-01 20:14:59','2026-11-01 20:14:59',NULL,NULL,NULL,NULL),(2,'6a3ef087-824f-460e-8ce6-c91248c7b636','20261102101800',NULL,'Super Administrator','Super','Administrator','admin@sntcssc.in','+919876543210','+919876543210','1990-01-01','male','WB-10-000001','aadhaar','1111-2222-3333','System Administrator','active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01','$2y$12$WiW1calvML00J0VnPCknv.AG2owKcUbdNNzN4HuncRk6d/FQCKPuq',2,NULL,NULL,NULL,NULL,'2026-11-01 20:15:01','2026-11-01 20:15:01',NULL,NULL,NULL,NULL),(3,'e4275cbb-548e-4c09-8ba6-e3fdd05f838f','20261102102713','202600001','Subhankar Basak','Subhankar','Basak','subhankarbasakmail@gmail.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:02',NULL,'$2y$12$38wsuqncBMt2rY.SAZqKC.uF/Mwl.BmtMWi/t5y4c1CmUIHbVhOty',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL,NULL,NULL,NULL),(4,'cd995adb-ad50-4c7f-9424-637e023a1ccb','20261102102950','202600002','Ananya Mukherjee','Ananya','Mukherjee','ananya.m@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:02',NULL,'$2y$12$tY4iICDqXCcZb/nZT/86O.L38/jqIV7PNl0f1o2DR7i.8zENVc2LW',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL,NULL,NULL,NULL),(5,'fc4cefc7-55d9-41fd-82d3-e0ab54a0e3d1','20261102103190','202600003','Debjit Chakraborty','Debjit','Chakraborty','debjit.c@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:03',NULL,'$2y$12$NPNnyfrA9GBZYIqWTIDSVOx2/A/.rIbKJvrtxJuhvv.InlY.FjnQm',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL,NULL,NULL,NULL),(6,'3aa6673c-33d8-46b0-8904-7f3ca7ca4c2f','20261102103428','202600004','Priyanka Banerjee','Priyanka','Banerjee','priyanka.b@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:03',NULL,'$2y$12$n//6iHPobJbmE.hwscHQW.oBQk6DO77.VT1eIDgr829yeyXoM8Wci',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL,NULL,NULL,NULL),(7,'4b460253-3069-4ffe-ae17-d63ce5ab4cdf','20261102103678','202600005','Rohit Sen','Rohit','Sen','rohit.sen@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:03',NULL,'$2y$12$JjAxelXkT5zzwHdu7gGbu.LBnUlewo0drTvGuXe2fglhiPoLDlhUa',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL,NULL,NULL,NULL),(8,'377e5e9d-87c1-46b5-949f-4923c341e79c','20261102103957','202600006','Sourav Ghosh','Sourav','Ghosh','sourav.g@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:03',NULL,'$2y$12$HEAUrRfiie5Ccx0k4SwVT.SUeaYSQeQ/rN0.Cp.DkXhPXOpIy0X7S',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:03','2026-11-01 20:15:03',NULL,NULL,NULL,NULL),(9,'3b3f57fd-2ca4-4b11-81a5-b444a3c129a2','20261102104210','202600007','Rupa Roy','Rupa','Roy','rupa.roy@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:04',NULL,'$2y$12$PIwWRmmPBSHe7RO48Mq6L.h6QJp0TvV.4WDM8XnPvfC4qd2ITCZTq',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL,NULL,NULL,NULL),(10,'2a1dbc3c-b4c4-4a8b-891c-01ab0e4c27a7','20261102104448','202600008','Arnab Bhattacharya','Arnab','Bhattacharya','arnab.b@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:04',NULL,'$2y$12$iZZT7rej2UNIu8zs7wYwOuA2ouBA1SrNHyQwcghz9N/VnZ0LfgX.e',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL,NULL,NULL,NULL),(11,'46b5c011-787d-46a7-9ada-351777868c84','20261102104695','202600009','Moumita Das','Moumita','Das','moumita.d@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:04',NULL,'$2y$12$CsPMX2krxzdds7uXKq0pYOJWJ62j/V1EgVVxavxa4BxR6PPgEHC1W',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL,NULL,NULL,NULL),(12,'42d548e2-610d-4f91-a9aa-dbf12b66fa1f','20261102104930','202600010','Siddhartha Dutta','Siddhartha','Dutta','siddhartha.d@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:04',NULL,'$2y$12$ZsqbM3fZ3YpVdSeOeqpisOwEJz6pS/Gg9jynCPATx5QBR5yhpImTa',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:04','2026-11-01 20:15:04',NULL,NULL,NULL,NULL),(13,'5c25fbb1-1d5c-4725-aea4-d42167b78563','20261102105173','202600011','Pooja Shaw','Pooja','Shaw','pooja.shaw@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:05',NULL,'$2y$12$iI8QQ5y0nzNEzmDp7qaJvuaRRWfkzCHII9XHJhuairMzn/vrnO.wy',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL,NULL,NULL,NULL),(14,'3938cd59-1a6a-4e9a-9dc1-12701571199d','20261102105407','202600012','Koushik Ganguly','Koushik','Ganguly','koushik.g@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:05',NULL,'$2y$12$k5P.LExgFoDi04XHqMgqgO3SFMRQDjae44q2MJpeetaabCfQisy5S',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL,NULL,NULL,NULL),(15,'c8110d49-1398-4891-8d04-a203dd08cdcc','20261102105644','202600013','Tanushree Pal','Tanushree','Pal','tanushree.p@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:05',NULL,'$2y$12$1AAvxDn51Ks0h66QEUg1ceB9.Gt3Obd7hAlIqcBccq6SVhs9UTMJK',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL,NULL,NULL,NULL),(16,'447a3784-fde6-4af9-b611-f61e1a7736dd','20261102105885','202600014','Abhijit Kundu','Abhijit','Kundu','abhijit.k@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:05',NULL,'$2y$12$C2maoCb.Rl1UTHbOI2YFMueHlJyix4EIU1SVd5dFj5ZMBaBnHE3w.',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:05','2026-11-01 20:15:05',NULL,NULL,NULL,NULL),(17,'52ca4fb2-3a79-418c-8287-9443e8cfb23f','20261102106130','202600015','Sneha Karmakar','Sneha','Karmakar','sneha.k@example.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-01 20:15:06',NULL,'$2y$12$4fN83jMPfks/GyrepHRHoewhMJplmLl1DmCGWLAmTLkQjT8iiTLjW',NULL,NULL,NULL,NULL,NULL,'2026-11-01 20:15:06','2026-11-01 20:15:06',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `working_calendars`
--

DROP TABLE IF EXISTS `working_calendars`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `working_calendars` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `course_id` bigint unsigned DEFAULT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `monday_active` tinyint(1) NOT NULL DEFAULT '1',
  `tuesday_active` tinyint(1) NOT NULL DEFAULT '1',
  `wednesday_active` tinyint(1) NOT NULL DEFAULT '1',
  `thursday_active` tinyint(1) NOT NULL DEFAULT '1',
  `friday_active` tinyint(1) NOT NULL DEFAULT '1',
  `saturday_active` tinyint(1) NOT NULL DEFAULT '0',
  `sunday_active` tinyint(1) NOT NULL DEFAULT '0',
  `saturday_rules` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all_off',
  `expected_start_time` time NOT NULL DEFAULT '09:30:00',
  `expected_end_time` time NOT NULL DEFAULT '17:30:00',
  `late_grace_minutes` smallint unsigned NOT NULL DEFAULT '15',
  `half_day_hours` decimal(4,1) NOT NULL DEFAULT '4.0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `working_calendars_course_id_foreign` (`course_id`),
  KEY `working_calendars_batch_id_foreign` (`batch_id`),
  KEY `working_calendars_created_by_foreign` (`created_by`),
  KEY `working_calendars_updated_by_foreign` (`updated_by`),
  KEY `working_calendars_deleted_by_foreign` (`deleted_by`),
  KEY `working_calendars_target_type_index` (`target_type`),
  KEY `working_calendars_is_default_index` (`is_default`),
  CONSTRAINT `working_calendars_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `working_calendars_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `working_calendars_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `working_calendars_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `working_calendars_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `working_calendars`
--

LOCK TABLES `working_calendars` WRITE;
/*!40000 ALTER TABLE `working_calendars` DISABLE KEYS */;
INSERT INTO `working_calendars` VALUES (1,'Office Staff General Schedule','staff',NULL,NULL,NULL,NULL,NULL,1,1,1,1,1,1,0,'2nd_4th_off','09:30:00','17:30:00',15,4.0,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL),(2,'Regular Student Academic Week','student',NULL,NULL,NULL,NULL,NULL,1,1,1,1,1,1,0,'all_working','10:00:00','16:30:00',15,3.5,1,NULL,NULL,NULL,'2026-11-01 20:15:02','2026-11-01 20:15:02',NULL);
/*!40000 ALTER TABLE `working_calendars` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-02  1:45:29
