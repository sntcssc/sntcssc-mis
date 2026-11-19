-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 02, 2026 at 09:18 AM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u239865899_fees`
--

-- --------------------------------------------------------

--
-- Table structure for table `admission_configs`
--

CREATE TABLE `admission_configs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `is_payment_required` tinyint(1) NOT NULL DEFAULT 1,
  `store_roll_no` tinyint(1) NOT NULL DEFAULT 0,
  `fee_reserved` decimal(10,2) NOT NULL DEFAULT 500.00,
  `fee_general` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `instructions` longtext DEFAULT NULL,
  `terms_and_conditions` longtext DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admission_forms`
--

CREATE TABLE `admission_forms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `roll_no` varchar(255) DEFAULT NULL,
  `admission_selection_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Pending','Under Review','Paid','Exempted','Failed') NOT NULL DEFAULT 'Pending',
  `payment_mode` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `terms_accepted_at` timestamp NULL DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `admission_form_number` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admission_selections`
--

CREATE TABLE `admission_selections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `selected_by` bigint(20) UNSIGNED NOT NULL,
  `selected_at` timestamp NOT NULL,
  `selection_list` varchar(2) NOT NULL DEFAULT 'A',
  `selection_mode` varchar(20) NOT NULL DEFAULT 'admission_test',
  `reference_name` varchar(255) DEFAULT NULL,
  `withdrawn_at` timestamp NULL DEFAULT NULL,
  `withdrawal_reason` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'selected',
  `admin_remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admit_card_configs`
--

CREATE TABLE `admit_card_configs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `academic_year` varchar(20) NOT NULL,
  `institute_name` varchar(255) NOT NULL,
  `institute_sub` varchar(255) DEFAULT NULL,
  `institute_address` text DEFAULT NULL,
  `institute_phone` varchar(20) DEFAULT NULL,
  `institute_email` varchar(255) DEFAULT NULL,
  `institute_website` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `stamp_path` varchar(255) DEFAULT NULL,
  `admit_card_instructions` text DEFAULT NULL,
  `general_instructions` text DEFAULT NULL,
  `show_barcode` tinyint(1) NOT NULL DEFAULT 0,
  `show_qr_code` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admit_download_logs`
--

CREATE TABLE `admit_download_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `student_centre_mapping_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `type` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_marquee` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `application_number` varchar(255) NOT NULL,
  `status` enum('Draft','Submitted','Under Review','Verified','Approved','Rejected','Expired','Incomplete','PaymentWindowExpired') NOT NULL DEFAULT 'Draft',
  `last_step` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `terms_accepted_at` timestamp NULL DEFAULT NULL,
  `payment_status` enum('Unpaid','Paid','Exempted','Under Review','Rejected','Refunded','Failed','Expired') NOT NULL DEFAULT 'Unpaid',
  `applied_at` timestamp NULL DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `expiry_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_addresses`
--

CREATE TABLE `application_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `address_type` enum('Permanent','Correspondence') NOT NULL,
  `state` varchar(255) NOT NULL,
  `district` varchar(255) NOT NULL,
  `subdistrict` varchar(255) DEFAULT NULL,
  `address_line1` text NOT NULL,
  `address_line2` text DEFAULT NULL,
  `post_office` varchar(255) DEFAULT NULL,
  `police_station` varchar(255) DEFAULT NULL,
  `pin_code` varchar(10) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `application_profiles`
--

CREATE TABLE `application_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `dob` date NOT NULL,
  `gender` enum('male','female','others') NOT NULL,
  `category` enum('UR','SC','ST','OBCA','OBCB','EWS') DEFAULT NULL,
  `verified_category` varchar(10) DEFAULT NULL,
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `category_remark` text DEFAULT NULL,
  `subcategory` varchar(255) DEFAULT NULL,
  `subcaste` varchar(255) DEFAULT NULL,
  `cert_no` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `issued_by` varchar(255) DEFAULT NULL,
  `is_pwbd` tinyint(1) NOT NULL DEFAULT 0,
  `pwbd_type` varchar(255) DEFAULT NULL,
  `pwd_percentage` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `occupation` varchar(255) DEFAULT NULL,
  `father_occupation` varchar(255) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `family_income` decimal(10,2) DEFAULT NULL,
  `highest_edu` varchar(255) DEFAULT NULL,
  `school_language` varchar(255) DEFAULT NULL,
  `upsc_community` varchar(255) DEFAULT NULL,
  `activities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `hobbies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appl_current_enrollments`
--

CREATE TABLE `appl_current_enrollments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `course_name` varchar(255) DEFAULT NULL,
  `institute` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appl_documents`
--

CREATE TABLE `appl_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_hash` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appl_edu_qualifications`
--

CREATE TABLE `appl_edu_qualifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `level` varchar(255) NOT NULL,
  `board_university` varchar(255) NOT NULL,
  `institute` varchar(255) NOT NULL,
  `stream_subject` text DEFAULT NULL,
  `passing_year` year(4) DEFAULT NULL,
  `total_marks` int(11) DEFAULT NULL,
  `marks_obtained` int(11) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `cgpa` decimal(4,2) DEFAULT NULL,
  `division` varchar(255) DEFAULT NULL,
  `completion_status` varchar(255) DEFAULT NULL,
  `is_highest` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appl_empl_histories`
--

CREATE TABLE `appl_empl_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `employer_name` varchar(255) DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `nature_of_employment` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appl_other_details`
--

CREATE TABLE `appl_other_details` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `attempts_already_given` int(11) NOT NULL DEFAULT 0,
  `attempts_left` int(11) DEFAULT NULL,
  `optional_subject` varchar(255) DEFAULT NULL,
  `year_of_appearing` year(4) DEFAULT NULL,
  `activities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `hobbies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dynamic_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appl_status_histories`
--

CREATE TABLE `appl_status_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `old_status` varchar(255) NOT NULL,
  `new_status` varchar(255) NOT NULL,
  `changed_by` bigint(20) UNSIGNED NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendances`
--

CREATE TABLE `attendances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `student_centre_mapping_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('present','absent') NOT NULL DEFAULT 'present',
  `marked_at` timestamp NULL DEFAULT NULL,
  `marked_by` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `auditable_type` varchar(255) NOT NULL,
  `auditable_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `field_name` varchar(255) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversations`
--

CREATE TABLE `chat_conversations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_to` bigint(20) UNSIGNED DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'support',
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `user_unread_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `admin_unread_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `participant_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_conversation_participants`
--

CREATE TABLE `chat_conversation_participants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chat_conversation_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chat_conversation_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `reply_to_id` bigint(20) UNSIGNED DEFAULT NULL,
  `message` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_type` varchar(255) DEFAULT NULL,
  `attachment_size` bigint(20) UNSIGNED DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `reactions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_message_deletions`
--

CREATE TABLE `chat_message_deletions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chat_message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_read_status`
--

CREATE TABLE `chat_read_status` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chat_conversation_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `chat_message_id` bigint(20) UNSIGNED NOT NULL,
  `read_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_settings`
--

CREATE TABLE `chat_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `setting_key` varchar(255) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_typing_indicators`
--

CREATE TABLE `chat_typing_indicators` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `chat_conversation_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `is_typing` tinyint(1) NOT NULL DEFAULT 0,
  `last_typed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `database_backups`
--

CREATE TABLE `database_backups` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `size` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('pending','completed','failed','restoring','restored','deleted') NOT NULL DEFAULT 'pending',
  `type` enum('manual','scheduled') NOT NULL DEFAULT 'manual',
  `error_message` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `restored_by` bigint(20) UNSIGNED DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `examination_centers`
--

CREATE TABLE `examination_centers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(255) DEFAULT NULL,
  `district` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(15) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `examination_centre_classrooms`
--

CREATE TABLE `examination_centre_classrooms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `examination_center_id` bigint(20) UNSIGNED NOT NULL,
  `room_number` varchar(255) NOT NULL,
  `floor` varchar(255) DEFAULT NULL,
  `building_name` varchar(255) DEFAULT NULL,
  `capacity` int(10) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_code` varchar(50) NOT NULL,
  `exam_name` varchar(200) NOT NULL,
  `academic_year` year(4) DEFAULT NULL,
  `conducting_body` varchar(255) DEFAULT NULL,
  `memo_no` varchar(255) DEFAULT NULL,
  `notification_date` date DEFAULT NULL,
  `apply_start` date NOT NULL,
  `apply_end` date NOT NULL,
  `payment_end_date` date DEFAULT NULL,
  `correction_start` date DEFAULT NULL,
  `correction_end` date DEFAULT NULL,
  `fee_general` decimal(10,2) NOT NULL DEFAULT 100.00,
  `fee_reserved` decimal(10,2) NOT NULL DEFAULT 50.00,
  `fee_ews` decimal(10,2) DEFAULT NULL,
  `pwbd_exempt` tinyint(1) NOT NULL DEFAULT 1,
  `age_min` tinyint(3) UNSIGNED DEFAULT NULL,
  `age_max` tinyint(3) UNSIGNED DEFAULT NULL,
  `total_vacancies` int(11) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `application_terms` longtext DEFAULT NULL,
  `official_url` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Draft',
  `admit_card_config_id` bigint(20) UNSIGNED DEFAULT NULL,
  `exam_date` date DEFAULT NULL,
  `admit_card_start_date` date DEFAULT NULL,
  `admit_card_end_date` date DEFAULT NULL,
  `exam_time` varchar(255) DEFAULT NULL,
  `reporting_time` varchar(255) DEFAULT NULL,
  `duration` varchar(255) DEFAULT NULL,
  `admit_card_instructions` text DEFAULT NULL,
  `show_barcode` tinyint(1) NOT NULL DEFAULT 0,
  `show_qr_code` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_application_grants`
--

CREATE TABLE `exam_application_grants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `assigned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_attempts`
--

CREATE TABLE `exam_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `exam_year` year(4) NOT NULL,
  `roll_number` varchar(255) DEFAULT NULL,
  `prelims_cleared` tinyint(1) NOT NULL DEFAULT 0,
  `mains_cleared` tinyint(1) NOT NULL DEFAULT 0,
  `interview_cleared` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_centre_classroom_configs`
--

CREATE TABLE `exam_centre_classroom_configs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `examination_center_id` bigint(20) UNSIGNED NOT NULL,
  `examination_centre_classroom_id` bigint(20) UNSIGNED NOT NULL,
  `allocated_seats` int(10) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exam_document_types`
--

CREATE TABLE `exam_document_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

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
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `landing_sections`
--

CREATE TABLE `landing_sections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_subscriptions`
--

CREATE TABLE `maintenance_subscriptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) UNSIGNED NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_channels_config`
--

CREATE TABLE `notification_channels_config` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_type` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `template` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otps`
--

CREATE TABLE `otps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `code` varchar(6) NOT NULL,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otr_addresses`
--

CREATE TABLE `otr_addresses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `address_type` enum('Permanent','Correspondence') NOT NULL,
  `state` varchar(255) NOT NULL,
  `district` varchar(255) NOT NULL,
  `subdistrict` varchar(255) DEFAULT NULL,
  `address_line1` text NOT NULL,
  `address_line2` text DEFAULT NULL,
  `post_office` varchar(255) DEFAULT NULL,
  `police_station` varchar(255) DEFAULT NULL,
  `pin_code` varchar(10) NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otr_documents`
--

CREATE TABLE `otr_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_hash` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otr_edu_qualifications`
--

CREATE TABLE `otr_edu_qualifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `level` varchar(255) NOT NULL,
  `board_university` varchar(255) NOT NULL,
  `institute` varchar(255) NOT NULL,
  `stream_subject` text DEFAULT NULL,
  `passing_year` year(4) DEFAULT NULL,
  `total_marks` int(11) DEFAULT NULL,
  `marks_obtained` int(11) DEFAULT NULL,
  `percentage` decimal(5,2) DEFAULT NULL,
  `cgpa` decimal(4,2) DEFAULT NULL,
  `division` varchar(255) DEFAULT NULL,
  `completion_status` varchar(255) DEFAULT NULL,
  `is_highest` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otr_empl_histories`
--

CREATE TABLE `otr_empl_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `employer_name` varchar(255) DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `nature_of_employment` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otr_profiles`
--

CREATE TABLE `otr_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `dob` date NOT NULL,
  `gender` enum('male','female','others') NOT NULL,
  `category` enum('UR','SC','ST','OBCA','OBCB','EWS') DEFAULT NULL,
  `subcategory` varchar(255) DEFAULT NULL,
  `subcaste` varchar(255) DEFAULT NULL,
  `is_pwbd` tinyint(1) NOT NULL DEFAULT 0,
  `pwbd_type` varchar(255) DEFAULT NULL,
  `pwd_percentage` tinyint(3) UNSIGNED DEFAULT NULL,
  `cert_no` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `issued_by` varchar(255) DEFAULT NULL,
  `occupation` varchar(255) DEFAULT NULL,
  `father_occupation` varchar(255) DEFAULT NULL,
  `mother_occupation` varchar(255) DEFAULT NULL,
  `family_income` decimal(10,2) DEFAULT NULL,
  `highest_edu` varchar(255) DEFAULT NULL,
  `school_language` varchar(255) DEFAULT NULL,
  `upsc_community` varchar(255) DEFAULT NULL,
  `activities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `hobbies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `distance` decimal(5,2) DEFAULT NULL,
  `status` enum('draft','submitted','locked') NOT NULL DEFAULT 'draft',
  `last_step` int(11) NOT NULL DEFAULT 1,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pages`
--

CREATE TABLE `pages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` longtext DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `passkeys`
--

CREATE TABLE `passkeys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `credential` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ref_id` varchar(255) NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_gateway` enum('OFFLINE','CCAVENUE','RAZORPAY','PAYU','IBCOLLECT') NOT NULL,
  `payment_mode` enum('UPI','NEFT','IMPS','BANK_TRANSFER') NOT NULL,
  `transaction_id` varchar(255) NOT NULL,
  `transaction_date` date NOT NULL,
  `status` enum('Pending','Success','Failed','Refunded','Under Review') NOT NULL DEFAULT 'Pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `app_name` varchar(255) NOT NULL DEFAULT 'My Application',
  `title` varchar(255) NOT NULL DEFAULT 'My Application',
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `language` varchar(255) NOT NULL DEFAULT 'en',
  `date_format` varchar(255) NOT NULL DEFAULT 'Y-m-d',
  `time_format` varchar(255) NOT NULL DEFAULT 'H:i:s',
  `currency_symbol` varchar(255) NOT NULL DEFAULT '$',
  `copyright_text` varchar(255) DEFAULT NULL,
  `maintenance_mode` tinyint(1) NOT NULL DEFAULT 0,
  `otr_cleanup_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `otr_cleanup_days` int(11) NOT NULL DEFAULT 30,
  `theme_primary` varchar(9) NOT NULL DEFAULT '#4F46E5',
  `theme_primary_contrast` varchar(9) NOT NULL DEFAULT '#FFFFFF',
  `theme_secondary` varchar(9) NOT NULL DEFAULT '#0EA5E9',
  `theme_secondary_contrast` varchar(9) NOT NULL DEFAULT '#FFFFFF',
  `theme_accent` varchar(9) NOT NULL DEFAULT '#F59E0B',
  `theme_accent_contrast` varchar(9) NOT NULL DEFAULT '#0F172A',
  `theme_mode_default` varchar(10) NOT NULL DEFAULT 'system',
  `theme_radius` varchar(6) NOT NULL DEFAULT 'lg',
  `theme_portal_name` varchar(120) NOT NULL DEFAULT 'Candidate Portal',
  `theme_dark_primary` varchar(9) NOT NULL DEFAULT '#818CF8',
  `theme_dark_primary_contrast` varchar(9) NOT NULL DEFAULT '#0F172A',
  `theme_dark_secondary` varchar(9) NOT NULL DEFAULT '#38BDF8',
  `theme_dark_secondary_contrast` varchar(9) NOT NULL DEFAULT '#0F172A',
  `theme_dark_accent` varchar(9) NOT NULL DEFAULT '#FBBF24',
  `theme_dark_accent_contrast` varchar(9) NOT NULL DEFAULT '#0F172A',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_centre_mappings`
--

CREATE TABLE `student_centre_mappings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `exam_centre_classroom_config_id` bigint(20) UNSIGNED NOT NULL,
  `exam_roll_no` varchar(20) NOT NULL,
  `seat_number` int(10) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `assignment_type` varchar(255) NOT NULL DEFAULT 'auto',
  `booklet_no` varchar(255) DEFAULT NULL,
  `omr_sheet_no` varchar(255) DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `support_tickets`
--

CREATE TABLE `support_tickets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_number` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `ticket_category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ticket_priority_id` bigint(20) UNSIGNED DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'open',
  `assigned_to` bigint(20) UNSIGNED DEFAULT NULL,
  `response_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `message_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_replied_at` timestamp NULL DEFAULT NULL,
  `last_replied_by_at` timestamp NULL DEFAULT NULL,
  `is_flagged` tinyint(1) NOT NULL DEFAULT 0,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_assignments`
--

CREATE TABLE `ticket_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `support_ticket_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_to` bigint(20) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_attachments`
--

CREATE TABLE `ticket_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ticket_message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `support_ticket_id` bigint(20) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(255) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_categories`
--

CREATE TABLE `ticket_categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_messages`
--

CREATE TABLE `ticket_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `support_ticket_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_priorities`
--

CREATE TABLE `ticket_priorities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `color` varchar(9) NOT NULL DEFAULT '#6B7280',
  `level` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_status_histories`
--

CREATE TABLE `ticket_status_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `support_ticket_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `old_status` varchar(255) NOT NULL,
  `new_status` varchar(255) NOT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `urn` varchar(20) NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Others') DEFAULT NULL,
  `tenth_roll` varchar(255) NOT NULL,
  `id_type` enum('AADHAAR','VOTER_ID','PAN','PASSPORT') DEFAULT NULL,
  `id_number` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `mobile` varchar(15) NOT NULL,
  `mobile_verified_at` timestamp NULL DEFAULT NULL,
  `whatsapp` varchar(15) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_text` longtext DEFAULT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `failed_login_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notification_preferences`
--

CREATE TABLE `user_notification_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_online_status`
--

CREATE TABLE `user_online_status` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'offline',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `typing_in_conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `typing_in_ticket_id` bigint(20) UNSIGNED DEFAULT NULL,
  `typing_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admission_configs`
--
ALTER TABLE `admission_configs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admission_configs_exam_id_foreign` (`exam_id`);

--
-- Indexes for table `admission_forms`
--
ALTER TABLE `admission_forms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `admission_forms_application_id_unique` (`application_id`),
  ADD UNIQUE KEY `admission_forms_admission_selection_id_unique` (`admission_selection_id`),
  ADD UNIQUE KEY `admission_forms_admission_form_number_unique` (`admission_form_number`),
  ADD KEY `admission_forms_payment_id_foreign` (`payment_id`),
  ADD KEY `admission_forms_verified_by_foreign` (`verified_by`);

--
-- Indexes for table `admission_selections`
--
ALTER TABLE `admission_selections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `admission_selections_application_id_unique` (`application_id`),
  ADD KEY `admission_selections_exam_id_foreign` (`exam_id`),
  ADD KEY `admission_selections_selected_by_foreign` (`selected_by`);

--
-- Indexes for table `admit_card_configs`
--
ALTER TABLE `admit_card_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `admit_card_configs_academic_year_unique` (`academic_year`);

--
-- Indexes for table `admit_download_logs`
--
ALTER TABLE `admit_download_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admit_download_logs_student_centre_mapping_id_foreign` (`student_centre_mapping_id`),
  ADD KEY `admit_download_logs_application_id_foreign` (`application_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `announcements_created_by_foreign` (`created_by`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `applications_user_id_exam_id_unique` (`user_id`,`exam_id`),
  ADD UNIQUE KEY `applications_application_number_unique` (`application_number`),
  ADD KEY `applications_exam_id_foreign` (`exam_id`);

--
-- Indexes for table `application_addresses`
--
ALTER TABLE `application_addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_addresses_application_id_foreign` (`application_id`);

--
-- Indexes for table `application_profiles`
--
ALTER TABLE `application_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_profiles_application_id_unique` (`application_id`),
  ADD KEY `application_profiles_verified_by_foreign` (`verified_by`);

--
-- Indexes for table `appl_current_enrollments`
--
ALTER TABLE `appl_current_enrollments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appl_current_enrollments_application_id_foreign` (`application_id`);

--
-- Indexes for table `appl_documents`
--
ALTER TABLE `appl_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appl_documents_application_id_foreign` (`application_id`),
  ADD KEY `appl_documents_verified_by_foreign` (`verified_by`);

--
-- Indexes for table `appl_edu_qualifications`
--
ALTER TABLE `appl_edu_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appl_edu_qualifications_application_id_foreign` (`application_id`);

--
-- Indexes for table `appl_empl_histories`
--
ALTER TABLE `appl_empl_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appl_empl_histories_application_id_foreign` (`application_id`);

--
-- Indexes for table `appl_other_details`
--
ALTER TABLE `appl_other_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appl_other_details_application_id_foreign` (`application_id`);

--
-- Indexes for table `appl_status_histories`
--
ALTER TABLE `appl_status_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appl_status_histories_application_id_foreign` (`application_id`),
  ADD KEY `appl_status_histories_changed_by_foreign` (`changed_by`);

--
-- Indexes for table `attendances`
--
ALTER TABLE `attendances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `attendances_exam_id_student_centre_mapping_id_unique` (`exam_id`,`student_centre_mapping_id`),
  ADD KEY `attendances_student_centre_mapping_id_foreign` (`student_centre_mapping_id`),
  ADD KEY `attendances_application_id_foreign` (`application_id`),
  ADD KEY `attendances_marked_by_foreign` (`marked_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_logs_user_id_foreign` (`user_id`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chat_conversations_assigned_to_foreign` (`assigned_to`),
  ADD KEY `chat_conversations_status_assigned_to_index` (`status`,`assigned_to`),
  ADD KEY `chat_conversations_user_id_is_active_index` (`user_id`,`is_active`),
  ADD KEY `chat_conversations_participant_id_foreign` (`participant_id`);

--
-- Indexes for table `chat_conversation_participants`
--
ALTER TABLE `chat_conversation_participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cc_participants_unique` (`chat_conversation_id`,`user_id`),
  ADD KEY `cc_participants_user_id_foreign` (`user_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `chat_messages_user_id_foreign` (`user_id`),
  ADD KEY `chat_messages_chat_conversation_id_created_at_index` (`chat_conversation_id`,`created_at`);

--
-- Indexes for table `chat_message_deletions`
--
ALTER TABLE `chat_message_deletions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chat_message_deletions_chat_message_id_user_id_unique` (`chat_message_id`,`user_id`),
  ADD KEY `chat_message_deletions_user_id_foreign` (`user_id`);

--
-- Indexes for table `chat_read_status`
--
ALTER TABLE `chat_read_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chat_read_unique` (`chat_conversation_id`,`user_id`,`chat_message_id`),
  ADD KEY `chat_read_status_user_id_foreign` (`user_id`),
  ADD KEY `chat_read_status_chat_message_id_foreign` (`chat_message_id`);

--
-- Indexes for table `chat_settings`
--
ALTER TABLE `chat_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chat_settings_setting_key_unique` (`setting_key`);

--
-- Indexes for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chat_typing_indicators_chat_conversation_id_user_id_unique` (`chat_conversation_id`,`user_id`),
  ADD KEY `chat_typing_indicators_user_id_foreign` (`user_id`);

--
-- Indexes for table `database_backups`
--
ALTER TABLE `database_backups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `database_backups_filename_unique` (`filename`),
  ADD KEY `database_backups_created_by_foreign` (`created_by`),
  ADD KEY `database_backups_restored_by_foreign` (`restored_by`),
  ADD KEY `database_backups_status_created_at_index` (`status`,`created_at`),
  ADD KEY `database_backups_type_index` (`type`);

--
-- Indexes for table `examination_centers`
--
ALTER TABLE `examination_centers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `examination_centers_code_unique` (`code`);

--
-- Indexes for table `examination_centre_classrooms`
--
ALTER TABLE `examination_centre_classrooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `exam_center_room_unique` (`examination_center_id`,`room_number`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `exams_exam_code_unique` (`exam_code`),
  ADD KEY `exams_admit_card_config_id_foreign` (`admit_card_config_id`);

--
-- Indexes for table `exam_application_grants`
--
ALTER TABLE `exam_application_grants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `exam_application_grants_exam_id_user_id_unique` (`exam_id`,`user_id`),
  ADD KEY `exam_application_grants_user_id_foreign` (`user_id`),
  ADD KEY `exam_application_grants_assigned_by_foreign` (`assigned_by`);

--
-- Indexes for table `exam_attempts`
--
ALTER TABLE `exam_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_attempts_user_id_foreign` (`user_id`),
  ADD KEY `exam_attempts_application_id_foreign` (`application_id`);

--
-- Indexes for table `exam_centre_classroom_configs`
--
ALTER TABLE `exam_centre_classroom_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ecc_exam_class_unique` (`exam_id`,`examination_centre_classroom_id`),
  ADD KEY `ecc_center_fk` (`examination_center_id`),
  ADD KEY `ecc_classroom_fk` (`examination_centre_classroom_id`);

--
-- Indexes for table `exam_document_types`
--
ALTER TABLE `exam_document_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `exam_document_types_exam_id_type_unique` (`exam_id`,`type`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `landing_sections`
--
ALTER TABLE `landing_sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `landing_sections_type_index` (`type`);

--
-- Indexes for table `maintenance_subscriptions`
--
ALTER TABLE `maintenance_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `maintenance_subscriptions_email_unique` (`email`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`);

--
-- Indexes for table `notification_channels_config`
--
ALTER TABLE `notification_channels_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `notification_channels_config_event_type_channel_unique` (`event_type`,`channel`);

--
-- Indexes for table `otps`
--
ALTER TABLE `otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `otps_identifier_type_index` (`identifier`,`type`);

--
-- Indexes for table `otr_addresses`
--
ALTER TABLE `otr_addresses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `otr_addresses_user_id_address_type_unique` (`user_id`,`address_type`);

--
-- Indexes for table `otr_documents`
--
ALTER TABLE `otr_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `otr_documents_user_id_foreign` (`user_id`);

--
-- Indexes for table `otr_edu_qualifications`
--
ALTER TABLE `otr_edu_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `otr_edu_qualifications_user_id_foreign` (`user_id`);

--
-- Indexes for table `otr_empl_histories`
--
ALTER TABLE `otr_empl_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `otr_empl_histories_user_id_foreign` (`user_id`);

--
-- Indexes for table `otr_profiles`
--
ALTER TABLE `otr_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `otr_profiles_user_id_unique` (`user_id`);

--
-- Indexes for table `pages`
--
ALTER TABLE `pages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pages_slug_unique` (`slug`);

--
-- Indexes for table `passkeys`
--
ALTER TABLE `passkeys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `passkeys_credential_id_unique` (`credential_id`),
  ADD KEY `passkeys_user_id_index` (`user_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payments_ref_id_unique` (`ref_id`),
  ADD UNIQUE KEY `payments_transaction_id_unique` (`transaction_id`),
  ADD KEY `payments_application_id_foreign` (`application_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_centre_mappings`
--
ALTER TABLE `student_centre_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_centre_mappings_exam_id_exam_roll_no_unique` (`exam_id`,`exam_roll_no`),
  ADD UNIQUE KEY `student_centre_mappings_exam_id_application_id_unique` (`exam_id`,`application_id`),
  ADD UNIQUE KEY `uq_config_seat` (`exam_centre_classroom_config_id`,`seat_number`),
  ADD KEY `student_centre_mappings_application_id_foreign` (`application_id`),
  ADD KEY `student_centre_mappings_assigned_by_foreign` (`assigned_by`);

--
-- Indexes for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `support_tickets_ticket_number_unique` (`ticket_number`),
  ADD KEY `support_tickets_ticket_category_id_foreign` (`ticket_category_id`),
  ADD KEY `support_tickets_ticket_priority_id_foreign` (`ticket_priority_id`),
  ADD KEY `support_tickets_user_id_status_index` (`user_id`,`status`),
  ADD KEY `support_tickets_assigned_to_status_index` (`assigned_to`,`status`),
  ADD KEY `support_tickets_status_created_at_index` (`status`,`created_at`);

--
-- Indexes for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_assignments_assigned_by_foreign` (`assigned_by`),
  ADD KEY `ticket_assignments_support_ticket_id_index` (`support_ticket_id`),
  ADD KEY `ticket_assignments_assigned_to_support_ticket_id_index` (`assigned_to`,`support_ticket_id`);

--
-- Indexes for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_attachments_ticket_message_id_foreign` (`ticket_message_id`),
  ADD KEY `ticket_attachments_support_ticket_id_index` (`support_ticket_id`);

--
-- Indexes for table `ticket_categories`
--
ALTER TABLE `ticket_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_categories_slug_unique` (`slug`);

--
-- Indexes for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_messages_user_id_foreign` (`user_id`),
  ADD KEY `ticket_messages_support_ticket_id_created_at_index` (`support_ticket_id`,`created_at`);

--
-- Indexes for table `ticket_priorities`
--
ALTER TABLE `ticket_priorities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_priorities_slug_unique` (`slug`);

--
-- Indexes for table `ticket_status_histories`
--
ALTER TABLE `ticket_status_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_status_histories_user_id_foreign` (`user_id`),
  ADD KEY `ticket_status_histories_support_ticket_id_created_at_index` (`support_ticket_id`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `users_urn_unique` (`urn`),
  ADD UNIQUE KEY `users_mobile_unique` (`mobile`);

--
-- Indexes for table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_notification_preferences_user_id_channel_unique` (`user_id`,`channel`);

--
-- Indexes for table `user_online_status`
--
ALTER TABLE `user_online_status`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_online_status_user_id_foreign` (`user_id`),
  ADD KEY `user_online_status_typing_in_conversation_id_foreign` (`typing_in_conversation_id`),
  ADD KEY `user_online_status_typing_in_ticket_id_foreign` (`typing_in_ticket_id`),
  ADD KEY `user_online_status_status_last_seen_at_index` (`status`,`last_seen_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admission_configs`
--
ALTER TABLE `admission_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admission_forms`
--
ALTER TABLE `admission_forms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admission_selections`
--
ALTER TABLE `admission_selections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admit_card_configs`
--
ALTER TABLE `admit_card_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admit_download_logs`
--
ALTER TABLE `admit_download_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_addresses`
--
ALTER TABLE `application_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `application_profiles`
--
ALTER TABLE `application_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appl_current_enrollments`
--
ALTER TABLE `appl_current_enrollments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appl_documents`
--
ALTER TABLE `appl_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appl_edu_qualifications`
--
ALTER TABLE `appl_edu_qualifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appl_empl_histories`
--
ALTER TABLE `appl_empl_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appl_other_details`
--
ALTER TABLE `appl_other_details`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `appl_status_histories`
--
ALTER TABLE `appl_status_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendances`
--
ALTER TABLE `attendances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_conversation_participants`
--
ALTER TABLE `chat_conversation_participants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_message_deletions`
--
ALTER TABLE `chat_message_deletions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_read_status`
--
ALTER TABLE `chat_read_status`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_settings`
--
ALTER TABLE `chat_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `database_backups`
--
ALTER TABLE `database_backups`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `examination_centers`
--
ALTER TABLE `examination_centers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `examination_centre_classrooms`
--
ALTER TABLE `examination_centre_classrooms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_application_grants`
--
ALTER TABLE `exam_application_grants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_attempts`
--
ALTER TABLE `exam_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_centre_classroom_configs`
--
ALTER TABLE `exam_centre_classroom_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_document_types`
--
ALTER TABLE `exam_document_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `landing_sections`
--
ALTER TABLE `landing_sections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_subscriptions`
--
ALTER TABLE `maintenance_subscriptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_channels_config`
--
ALTER TABLE `notification_channels_config`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otps`
--
ALTER TABLE `otps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otr_addresses`
--
ALTER TABLE `otr_addresses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otr_documents`
--
ALTER TABLE `otr_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otr_edu_qualifications`
--
ALTER TABLE `otr_edu_qualifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otr_empl_histories`
--
ALTER TABLE `otr_empl_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otr_profiles`
--
ALTER TABLE `otr_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pages`
--
ALTER TABLE `pages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `passkeys`
--
ALTER TABLE `passkeys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_centre_mappings`
--
ALTER TABLE `student_centre_mappings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_categories`
--
ALTER TABLE `ticket_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_priorities`
--
ALTER TABLE `ticket_priorities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_status_histories`
--
ALTER TABLE `ticket_status_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_online_status`
--
ALTER TABLE `user_online_status`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admission_configs`
--
ALTER TABLE `admission_configs`
  ADD CONSTRAINT `admission_configs_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admission_forms`
--
ALTER TABLE `admission_forms`
  ADD CONSTRAINT `admission_forms_admission_selection_id_foreign` FOREIGN KEY (`admission_selection_id`) REFERENCES `admission_selections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admission_forms_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admission_forms_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `admission_forms_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `admission_selections`
--
ALTER TABLE `admission_selections`
  ADD CONSTRAINT `admission_selections_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admission_selections_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admission_selections_selected_by_foreign` FOREIGN KEY (`selected_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admit_download_logs`
--
ALTER TABLE `admit_download_logs`
  ADD CONSTRAINT `admit_download_logs_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `admit_download_logs_student_centre_mapping_id_foreign` FOREIGN KEY (`student_centre_mapping_id`) REFERENCES `student_centre_mappings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `applications_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`),
  ADD CONSTRAINT `applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_addresses`
--
ALTER TABLE `application_addresses`
  ADD CONSTRAINT `application_addresses_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_profiles`
--
ALTER TABLE `application_profiles`
  ADD CONSTRAINT `application_profiles_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `application_profiles_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `appl_current_enrollments`
--
ALTER TABLE `appl_current_enrollments`
  ADD CONSTRAINT `appl_current_enrollments_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `appl_documents`
--
ALTER TABLE `appl_documents`
  ADD CONSTRAINT `appl_documents_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appl_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `appl_edu_qualifications`
--
ALTER TABLE `appl_edu_qualifications`
  ADD CONSTRAINT `appl_edu_qualifications_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `appl_empl_histories`
--
ALTER TABLE `appl_empl_histories`
  ADD CONSTRAINT `appl_empl_histories_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `appl_other_details`
--
ALTER TABLE `appl_other_details`
  ADD CONSTRAINT `appl_other_details_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `appl_status_histories`
--
ALTER TABLE `appl_status_histories`
  ADD CONSTRAINT `appl_status_histories_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appl_status_histories_changed_by_foreign` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `attendances`
--
ALTER TABLE `attendances`
  ADD CONSTRAINT `attendances_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendances_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendances_marked_by_foreign` FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `attendances_student_centre_mapping_id_foreign` FOREIGN KEY (`student_centre_mapping_id`) REFERENCES `student_centre_mappings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `chat_conversations`
--
ALTER TABLE `chat_conversations`
  ADD CONSTRAINT `chat_conversations_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_conversations_participant_id_foreign` FOREIGN KEY (`participant_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chat_conversations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_conversation_participants`
--
ALTER TABLE `chat_conversation_participants`
  ADD CONSTRAINT `cc_participants_convo_id_foreign` FOREIGN KEY (`chat_conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cc_participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_chat_conversation_id_foreign` FOREIGN KEY (`chat_conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_message_deletions`
--
ALTER TABLE `chat_message_deletions`
  ADD CONSTRAINT `chat_message_deletions_chat_message_id_foreign` FOREIGN KEY (`chat_message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_message_deletions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_read_status`
--
ALTER TABLE `chat_read_status`
  ADD CONSTRAINT `chat_read_status_chat_conversation_id_foreign` FOREIGN KEY (`chat_conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_read_status_chat_message_id_foreign` FOREIGN KEY (`chat_message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_read_status_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  ADD CONSTRAINT `chat_typing_indicators_chat_conversation_id_foreign` FOREIGN KEY (`chat_conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chat_typing_indicators_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `database_backups`
--
ALTER TABLE `database_backups`
  ADD CONSTRAINT `database_backups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `database_backups_restored_by_foreign` FOREIGN KEY (`restored_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `examination_centre_classrooms`
--
ALTER TABLE `examination_centre_classrooms`
  ADD CONSTRAINT `examination_centre_classrooms_examination_center_id_foreign` FOREIGN KEY (`examination_center_id`) REFERENCES `examination_centers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exams`
--
ALTER TABLE `exams`
  ADD CONSTRAINT `exams_admit_card_config_id_foreign` FOREIGN KEY (`admit_card_config_id`) REFERENCES `admit_card_configs` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `exam_application_grants`
--
ALTER TABLE `exam_application_grants`
  ADD CONSTRAINT `exam_application_grants_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_application_grants_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_application_grants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_attempts`
--
ALTER TABLE `exam_attempts`
  ADD CONSTRAINT `exam_attempts_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exam_attempts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_centre_classroom_configs`
--
ALTER TABLE `exam_centre_classroom_configs`
  ADD CONSTRAINT `ecc_center_fk` FOREIGN KEY (`examination_center_id`) REFERENCES `examination_centers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ecc_classroom_fk` FOREIGN KEY (`examination_centre_classroom_id`) REFERENCES `examination_centre_classrooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ecc_exam_fk` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exam_document_types`
--
ALTER TABLE `exam_document_types`
  ADD CONSTRAINT `exam_document_types_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otr_addresses`
--
ALTER TABLE `otr_addresses`
  ADD CONSTRAINT `otr_addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otr_documents`
--
ALTER TABLE `otr_documents`
  ADD CONSTRAINT `otr_documents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otr_edu_qualifications`
--
ALTER TABLE `otr_edu_qualifications`
  ADD CONSTRAINT `otr_edu_qualifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otr_empl_histories`
--
ALTER TABLE `otr_empl_histories`
  ADD CONSTRAINT `otr_empl_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `otr_profiles`
--
ALTER TABLE `otr_profiles`
  ADD CONSTRAINT `otr_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `passkeys`
--
ALTER TABLE `passkeys`
  ADD CONSTRAINT `passkeys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_centre_mappings`
--
ALTER TABLE `student_centre_mappings`
  ADD CONSTRAINT `student_centre_mappings_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_centre_mappings_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `student_centre_mappings_exam_centre_classroom_config_id_foreign` FOREIGN KEY (`exam_centre_classroom_config_id`) REFERENCES `exam_centre_classroom_configs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_centre_mappings_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `support_tickets_ticket_category_id_foreign` FOREIGN KEY (`ticket_category_id`) REFERENCES `ticket_categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `support_tickets_ticket_priority_id_foreign` FOREIGN KEY (`ticket_priority_id`) REFERENCES `ticket_priorities` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `support_tickets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_assignments`
--
ALTER TABLE `ticket_assignments`
  ADD CONSTRAINT `ticket_assignments_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_assignments_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_assignments_support_ticket_id_foreign` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_attachments`
--
ALTER TABLE `ticket_attachments`
  ADD CONSTRAINT `ticket_attachments_support_ticket_id_foreign` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_attachments_ticket_message_id_foreign` FOREIGN KEY (`ticket_message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_messages`
--
ALTER TABLE `ticket_messages`
  ADD CONSTRAINT `ticket_messages_support_ticket_id_foreign` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ticket_status_histories`
--
ALTER TABLE `ticket_status_histories`
  ADD CONSTRAINT `ticket_status_histories_support_ticket_id_foreign` FOREIGN KEY (`support_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ticket_status_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_notification_preferences`
--
ALTER TABLE `user_notification_preferences`
  ADD CONSTRAINT `user_notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_online_status`
--
ALTER TABLE `user_online_status`
  ADD CONSTRAINT `user_online_status_typing_in_conversation_id_foreign` FOREIGN KEY (`typing_in_conversation_id`) REFERENCES `chat_conversations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `user_online_status_typing_in_ticket_id_foreign` FOREIGN KEY (`typing_in_ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `user_online_status_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
