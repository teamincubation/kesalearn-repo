# KESA Learn - Database Schema Analysis

This document outlines all tables and column structures found in the database dump.

## Table: `account_deletions`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **kept_user_id** | `int(10) UNSIGNED NOT NULL` |
| **deleted_user_id** | `int(10) UNSIGNED NOT NULL` |
| **deleted_email** | `varchar(255) NOT NULL` |
| **deleted_name** | `varchar(100) DEFAULT NULL` |
| **moved_summary** | `text DEFAULT NULL COMMENT 'JSON of rows transferred to the kept account'` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `account_merge_requests`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **primary_user_id** | `int(10) UNSIGNED NOT NULL` |
| **duplicate_user_id** | `int(10) UNSIGNED NOT NULL` |
| **status** | `enum('pending','merged','declined') NOT NULL DEFAULT 'pending'` |
| **details** | `text DEFAULT NULL COMMENT 'JSON of moved row counts'` |
| **decided_at** | `datetime DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `activity_cleanup_log`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **activity_count** | `int(10) UNSIGNED NOT NULL COMMENT 'Number of activities deleted'` |
| **deletion_reason** | `varchar(100) NOT NULL COMMENT 'auto_cleanup, admin_manual'` |
| **deleted_by_admin_id** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin user ID if manual deletion'` |
| **deleted_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **deleted_from_date** | `date DEFAULT NULL COMMENT 'Activities older than this date were deleted'` |

## Table: `activity_logs`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **action** | `varchar(100) NOT NULL` |
| **details** | `text DEFAULT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `activity_retention_policy`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **retention_days** | `int(10) UNSIGNED NOT NULL DEFAULT 90 COMMENT 'Delete activities older than this many days'` |
| **enabled** | `tinyint(1) DEFAULT 1` |
| **last_cleanup_at** | `datetime DEFAULT NULL` |
| **next_cleanup_at** | `datetime DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `admin_filter_locks`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **page** | `varchar(50) NOT NULL DEFAULT 'registrations'` |
| **search_query** | `varchar(255) DEFAULT NULL` |
| **status_filter** | `varchar(50) DEFAULT NULL` |
| **event_filter** | `int(10) UNSIGNED DEFAULT NULL` |
| **locked_by** | `int(10) UNSIGNED NOT NULL` |
| **locked_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `admin_permissions`

| Column | Definition |
|---|---|
| **id** | `int(11) NOT NULL` |
| **user_id** | `int(11) NOT NULL` |
| **section** | `varchar(50) NOT NULL` |
| **can_access** | `tinyint(1) DEFAULT 1` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `announcements`

| Column | Definition |
|---|---|
| **id** | `int(11) NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **content** | `text NOT NULL` |
| **label** | `enum('new','important','download','register','free','update','info') DEFAULT 'info'` |
| **link_type** | `enum('none','internal','external','download') DEFAULT 'none'` |
| **link_url** | `varchar(500) DEFAULT NULL` |
| **file_path** | `varchar(500) DEFAULT NULL` |
| **start_date** | `datetime NOT NULL` |
| **end_date** | `datetime NOT NULL` |
| **is_active** | `tinyint(1) DEFAULT 1` |
| **sort_order** | `int(11) DEFAULT 0` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `assignments`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **description** | `text DEFAULT NULL` |
| **submission_type** | `enum('file','text','photo') NOT NULL DEFAULT 'file'` |
| **max_file_size_mb** | `int(11) DEFAULT 10` |
| **allowed_extensions** | `varchar(255) DEFAULT 'pdf,doc,docx,ppt,pptx,xls,xlsx,mp3,mp4,wav,jpg,jpeg,png'` |
| **deadline** | `datetime DEFAULT NULL` |
| **max_score** | `int(11) DEFAULT 100` |
| **is_active** | `tinyint(1) DEFAULT 1` |
| **assigned_instructor_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `assignment_materials`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **assignment_id** | `int(10) UNSIGNED NOT NULL` |
| **file_name** | `varchar(255) NOT NULL` |
| **file_path** | `varchar(500) NOT NULL` |
| **file_size** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |

## Table: `assignment_submissions`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **assignment_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **submission_type** | `enum('file','text','photo') NOT NULL` |
| **file_path** | `varchar(500) DEFAULT NULL` |
| **file_name** | `varchar(255) DEFAULT NULL` |
| **file_size** | `int(11) DEFAULT NULL` |
| **text_content** | `text DEFAULT NULL` |
| **status** | `enum('pending','approved','rejected') DEFAULT 'pending'` |
| **score** | `int(11) DEFAULT NULL` |
| **feedback** | `text DEFAULT NULL` |
| **reviewed_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **reviewed_at** | `datetime DEFAULT NULL` |
| **submitted_at** | `timestamp NULL DEFAULT current_timestamp()` |

## Table: `banners`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) DEFAULT NULL` |
| **image** | `varchar(255) NOT NULL` |
| **link** | `varchar(500) DEFAULT NULL` |
| **sort_order** | `int(11) NOT NULL DEFAULT 0` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `banner_settings`

| Column | Definition |
|---|---|
| **id** | `int(11) NOT NULL` |
| **show_shadow** | `tinyint(1) NOT NULL DEFAULT 1` |
| **carousel_speed** | `int(11) NOT NULL DEFAULT 5000` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `certificates`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **certificate_code** | `varchar(50) NOT NULL` |
| **template_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **generated_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **download_count** | `int(11) NOT NULL DEFAULT 0` |
| **certificate_number** | `varchar(100) DEFAULT NULL` |
| **user_email** | `varchar(255) DEFAULT NULL` |
| **recipient_name** | `varchar(255) DEFAULT NULL` |
| **certificate_file** | `varchar(500) DEFAULT NULL` |
| **issue_date** | `date DEFAULT NULL` |
| **description** | `text DEFAULT NULL` |
| **uploaded_by** | `int(10) UNSIGNED DEFAULT NULL` |

## Table: `certificate_downloads`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **certificate_code** | `varchar(100) NOT NULL` |
| **event_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **file_name** | `varchar(255) NOT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **device_info** | `varchar(255) DEFAULT NULL` |
| **downloaded_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `certificate_templates`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **name** | `varchar(255) NOT NULL` |
| **template_image** | `varchar(255) NOT NULL` |
| **name_x** | `int(11) NOT NULL DEFAULT 400` |
| **name_y** | `int(11) NOT NULL DEFAULT 350` |
| **name_font_size** | `int(11) NOT NULL DEFAULT 36` |
| **event_x** | `int(11) NOT NULL DEFAULT 400` |
| **event_y** | `int(11) NOT NULL DEFAULT 420` |
| **date_x** | `int(11) NOT NULL DEFAULT 400` |
| **date_y** | `int(11) NOT NULL DEFAULT 470` |
| **certid_x** | `int(11) NOT NULL DEFAULT 400` |
| **certid_y** | `int(11) NOT NULL DEFAULT 520` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `coupons`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **code** | `varchar(50) NOT NULL` |
| **name** | `varchar(150) NOT NULL COMMENT 'Human-friendly label e.g. Diwali Offer'` |
| **active_from** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **expire_on** | `datetime DEFAULT NULL COMMENT 'NULL = never expires'` |
| **max_uses_total** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited'` |
| **max_uses_per_user** | `tinyint(3) UNSIGNED NOT NULL DEFAULT 1` |
| **uses_count** | `int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Cached total redemptions'` |
| **applicable_types** | `varchar(100) DEFAULT NULL COMMENT 'NULL means all types'` |
| **scope** | `enum('all','specific') NOT NULL DEFAULT 'all'` |
| **discount_type** | `enum('percent','fixed') NOT NULL DEFAULT 'percent'` |
| **discount_value** | `decimal(10,2) NOT NULL DEFAULT 0.00` |
| **min_purchase_amount** | `decimal(10,2) NOT NULL DEFAULT 0.00` |
| **visibility** | `enum('public','private') NOT NULL DEFAULT 'public'` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `coupon_events`

| Column | Definition |
|---|---|
| **coupon_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |

## Table: `coupon_usages`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **coupon_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **registration_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **original_amount** | `decimal(10,2) NOT NULL` |
| **discount_amount** | `decimal(10,2) NOT NULL` |
| **final_amount** | `decimal(10,2) NOT NULL` |
| **used_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `email_otps`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **email** | `varchar(255) NOT NULL` |
| **otp_hash** | `varchar(255) NOT NULL` |
| **purpose** | `varchar(20) NOT NULL DEFAULT 'login'` |
| **attempts** | `tinyint(3) UNSIGNED NOT NULL DEFAULT 0` |
| **expires_at** | `datetime NOT NULL` |
| **used_at** | `datetime DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **request_ip** | `varchar(45) DEFAULT NULL` |

## Table: `email_verifications`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **token** | `varchar(255) NOT NULL` |
| **expires_at** | `datetime NOT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `encrypted_credentials`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **entity_type** | `varchar(50) NOT NULL` |
| **entity_id** | `int(10) UNSIGNED NOT NULL` |
| **credential_type** | `varchar(50) NOT NULL` |
| **encrypted_value** | `longtext NOT NULL` |
| **encryption_method** | `varchar(50) DEFAULT 'AES-256-CBC'` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `events`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **slug** | `varchar(255) DEFAULT NULL` |
| **whatsapp_group_link** | `varchar(500) DEFAULT NULL` |
| **whatsapp_group_enabled** | `tinyint(1) DEFAULT 0` |
| **description** | `text NOT NULL` |
| **short_description** | `varchar(500) DEFAULT NULL` |
| **banner_image** | `varchar(255) DEFAULT NULL` |
| **start_date** | `datetime NOT NULL` |
| **end_date** | `datetime NOT NULL` |
| **timezone** | `varchar(50) NOT NULL DEFAULT 'Asia/Kolkata'` |
| **venue** | `varchar(255) DEFAULT NULL` |
| **support_phone** | `varchar(20) DEFAULT NULL` |
| **support_whatsapp** | `varchar(20) DEFAULT NULL` |
| **payment_methods** | `varchar(50) DEFAULT 'both'` |
| **is_online** | `tinyint(1) NOT NULL DEFAULT 1` |
| **meeting_link** | `varchar(500) DEFAULT NULL` |
| **communication_languages** | `varchar(255) DEFAULT NULL` |
| **max_seats** | `int(10) UNSIGNED DEFAULT NULL` |
| **seats_taken** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |
| **price** | `decimal(10,2) NOT NULL DEFAULT 0.00` |
| **early_bird_price** | `decimal(10,2) DEFAULT NULL` |
| **early_bird_start** | `datetime DEFAULT NULL` |
| **early_bird_end** | `datetime DEFAULT NULL` |
| **currency** | `varchar(3) NOT NULL DEFAULT 'INR'` |
| **is_free** | `tinyint(1) NOT NULL DEFAULT 1` |
| **status** | `enum('draft','published','completed','cancelled') NOT NULL DEFAULT 'draft'` |
| **is_new** | `tinyint(1) NOT NULL DEFAULT 0` |
| **registration_deadline** | `datetime DEFAULT NULL` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **instructor_id** | `int(11) DEFAULT NULL COMMENT 'Foreign key to instructors table'` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **type** | `enum('webinar','workshop','course','offline','special') NOT NULL DEFAULT 'webinar'` |

## Table: `event_agenda`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **time** | `varchar(50) NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **description** | `text DEFAULT NULL` |
| **speaker_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **sort_order** | `int(11) NOT NULL DEFAULT 0` |

## Table: `event_form_fields`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **field_name** | `varchar(100) NOT NULL` |
| **field_label** | `varchar(255) NOT NULL` |
| **field_description** | `varchar(500) DEFAULT NULL` |
| **field_type** | `varchar(50) NOT NULL DEFAULT 'text'` |
| **is_required** | `tinyint(1) NOT NULL DEFAULT 1` |
| **sort_order** | `int(11) NOT NULL DEFAULT 0` |
| **options** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(` |

## Table: `event_instructors`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **instructor_id** | `int(10) UNSIGNED NOT NULL` |
| **sort_order** | `int(11) NOT NULL DEFAULT 0` |

## Table: `event_materials`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **description** | `text DEFAULT NULL` |
| **file_path** | `varchar(512) NOT NULL` |
| **file_type** | `enum('pdf','doc','docx','ppt','pptx','video','image','other') NOT NULL DEFAULT 'pdf'` |
| **file_size** | `bigint(20) UNSIGNED DEFAULT NULL COMMENT 'bytes'` |
| **thumbnail_path** | `varchar(512) DEFAULT NULL` |
| **available_from** | `datetime DEFAULT NULL COMMENT 'NULL means immediately available'` |
| **sort_order** | `smallint(5) UNSIGNED NOT NULL DEFAULT 0` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **uploaded_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `timestamp NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL` |

## Table: `event_messages`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **message** | `text NOT NULL` |
| **link_type** | `enum('none','internal','external','meeting') DEFAULT 'none'` |
| **link_url** | `varchar(500) DEFAULT NULL` |
| **link_label** | `varchar(100) DEFAULT NULL` |
| **file_path** | `varchar(500) DEFAULT NULL` |
| **file_name** | `varchar(255) DEFAULT NULL` |
| **is_active** | `tinyint(1) DEFAULT 1` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `event_page_views`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **device_info** | `varchar(255) DEFAULT NULL` |
| **referrer_url** | `varchar(500) DEFAULT NULL` |
| **viewed_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `event_ratings`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **rating** | `tinyint(3) UNSIGNED NOT NULL DEFAULT 5` |
| **review** | `text DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `event_speakers`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **name** | `varchar(100) NOT NULL` |
| **title** | `varchar(255) DEFAULT NULL` |
| **bio** | `text DEFAULT NULL` |
| **image** | `varchar(255) DEFAULT NULL` |
| **sort_order** | `int(11) NOT NULL DEFAULT 0` |

## Table: `feedbacks`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **name** | `varchar(100) NOT NULL` |
| **role_title** | `varchar(255) DEFAULT NULL` |
| **feedback_text** | `text NOT NULL` |
| **rating** | `tinyint(3) UNSIGNED NOT NULL DEFAULT 5` |
| **is_approved** | `tinyint(1) NOT NULL DEFAULT 0` |
| **added_by_admin** | `tinyint(1) NOT NULL DEFAULT 0` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **event_id** | `int(11) DEFAULT NULL` |
| **photo_url** | `varchar(500) DEFAULT NULL` |
| **is_read** | `tinyint(1) DEFAULT 0` |

## Table: `feedback_requests`

| Column | Definition |
|---|---|
| **id** | `int(11) NOT NULL` |
| **event_id** | `int(11) NOT NULL` |
| **user_id** | `int(11) NOT NULL` |
| **status** | `enum('pending','submitted','declined') DEFAULT 'pending'` |
| **email_sent_at** | `datetime DEFAULT NULL` |
| **responded_at** | `datetime DEFAULT NULL` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |

## Table: `incomplete_payments`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **registration_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **amount** | `decimal(10,2) NOT NULL` |
| **currency** | `varchar(3) NOT NULL DEFAULT 'INR'` |
| **payment_method** | `varchar(50) DEFAULT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **device_info** | `varchar(255) DEFAULT NULL` |
| **payment_gateway_reference** | `varchar(255) DEFAULT NULL` |
| **initiated_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **completed_at** | `datetime DEFAULT NULL` |
| **abandoned_at** | `datetime DEFAULT NULL` |
| **status** | `enum('initiated','pending','failed','completed','abandoned') NOT NULL DEFAULT 'initiated'` |
| **failure_reason** | `varchar(255) DEFAULT NULL` |
| **last_activity** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `instructors`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **name** | `varchar(100) NOT NULL` |
| **mobile** | `varchar(20) DEFAULT NULL` |
| **email** | `varchar(255) DEFAULT NULL` |
| **phone** | `varchar(20) DEFAULT NULL` |
| **photo** | `varchar(255) DEFAULT NULL` |
| **qualification** | `varchar(255) DEFAULT NULL` |
| **designation** | `varchar(255) DEFAULT NULL` |
| **experience** | `varchar(100) DEFAULT NULL` |
| **whatsapp** | `varchar(20) DEFAULT NULL` |
| **languages** | `varchar(255) DEFAULT NULL` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **bio** | `text DEFAULT NULL` |
| **profile_image** | `varchar(500) DEFAULT NULL` |
| **linkedin** | `varchar(255) DEFAULT NULL` |
| **specializations** | `text DEFAULT NULL` |

## Table: `instructor_certificates`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **instructor_id** | `int(10) UNSIGNED NOT NULL` |
| **certificate_code** | `varchar(50) NOT NULL COMMENT 'Public code e.g. KESA-INST-AB12CD'` |
| **title** | `varchar(255) NOT NULL` |
| **description** | `text DEFAULT NULL` |
| **event_id** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'Optional related event'` |
| **certificate_file** | `varchar(500) DEFAULT NULL COMMENT 'Path under uploads/instructors/certificates/'` |
| **issue_date** | `date DEFAULT NULL` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **uploaded_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **download_count** | `int(11) NOT NULL DEFAULT 0` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **certificate_number** | `varchar(100) DEFAULT NULL COMMENT 'Number printed on the certificate'` |

## Table: `languages`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **name** | `varchar(50) NOT NULL` |
| **code** | `varchar(10) NOT NULL` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |

## Table: `live_sessions`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **instructor_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **description** | `text DEFAULT NULL` |
| **start_datetime** | `datetime NOT NULL` |
| **end_datetime** | `datetime NOT NULL` |
| **reminder_send_minutes** | `tinyint(4) DEFAULT 5` |
| **reminder_sent_at** | `timestamp NULL DEFAULT NULL` |
| **is_manually_started** | `tinyint(1) DEFAULT 0` |
| **auto_notification_enabled** | `tinyint(1) DEFAULT 1` |
| **platform** | `enum('google_meet','zoom','youtube_live','other') NOT NULL DEFAULT 'google_meet'` |
| **meeting_link** | `varchar(500) DEFAULT NULL` |
| **recording_url** | `varchar(500) DEFAULT NULL` |
| **recording_expiry_date** | `date DEFAULT NULL` |
| **status** | `enum('scheduled','live','completed','cancelled') NOT NULL DEFAULT 'scheduled'` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `maintenance_mode`

| Column | Definition |
|---|---|
| **id** | `int(11) NOT NULL DEFAULT 1` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 0` |
| **message** | `text DEFAULT NULL` |
| **scheduled_start** | `datetime DEFAULT NULL` |
| **scheduled_end** | `datetime DEFAULT NULL` |
| **allow_admin_access** | `tinyint(1) NOT NULL DEFAULT 1` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `material_reads`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **material_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **first_read_at** | `timestamp NOT NULL DEFAULT current_timestamp()` |

## Table: `name_change_requests`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **current_name** | `varchar(255) NOT NULL` |
| **requested_name** | `varchar(255) NOT NULL` |
| **id_document_path** | `varchar(512) NOT NULL` |
| **status** | `enum('pending','approved','rejected') NOT NULL DEFAULT 'pending'` |
| **admin_note** | `text DEFAULT NULL` |
| **reviewed_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **reviewed_at** | `timestamp NULL DEFAULT NULL` |
| **created_at** | `timestamp NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `password_resets`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **token** | `varchar(255) NOT NULL` |
| **expires_at** | `datetime NOT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `payment_config_audit_log`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **payment_setting_id** | `int(10) UNSIGNED NOT NULL` |
| **action** | `enum('create','update','delete','activate','deactivate') NOT NULL` |
| **changed_fields** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Fields that were changed' CHECK (json_valid(` |
| **old_values** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Previous values' CHECK (json_valid(` |
| **new_values** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'New values' CHECK (json_valid(` |
| **admin_id** | `int(10) UNSIGNED NOT NULL` |
| **admin_ip** | `varchar(45) DEFAULT NULL` |
| **admin_user_agent** | `text DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `payment_settings`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **setting_type** | `enum('upi','gateway') NOT NULL COMMENT 'Type: upi or gateway'` |
| **gateway_name** | `varchar(100) DEFAULT NULL COMMENT 'Name of payment gateway (Razorpay, Phonepe, Eazebuz, etc)'` |
| **upi_id** | `varchar(255) DEFAULT NULL COMMENT 'UPI ID/Number for UPI payments'` |
| **upi_beneficiary_name** | `varchar(255) DEFAULT NULL COMMENT 'Name of UPI beneficiary'` |
| **upi_beneficiary_description** | `text DEFAULT NULL COMMENT 'Description of beneficiary for trust'` |
| **upi_payment_link** | `varchar(1000) DEFAULT NULL COMMENT 'UPI deep link for Click to Pay (e.g. upi://pay?pa=id@bank&pn=Name)'` |
| **upi_qr_code** | `varchar(500) DEFAULT NULL COMMENT 'File path to the UPI QR code image (relative to uploads directory)'` |
| **upi_is_verified** | `tinyint(1) DEFAULT 0 COMMENT 'Whether UPI is verified'` |
| **gateway_key_id** | `varchar(500) DEFAULT NULL COMMENT 'Gateway API Key ID (encrypted)'` |
| **gateway_key_secret** | `varchar(500) DEFAULT NULL COMMENT 'Gateway API Key Secret (encrypted)'` |
| **gateway_additional_config** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional gateway configuration as JSON' CHECK (json_valid(` |
| **is_active** | `tinyint(1) DEFAULT 1 COMMENT 'Whether this payment method is active'` |
| **is_primary** | `tinyint(1) DEFAULT 0 COMMENT 'Whether this is the primary payment method'` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **created_by** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin user ID who created this'` |
| **updated_by** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'Admin user ID who updated this'` |
| **gst_percent** | `decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'GST percentage applied on top of discounted amount (both UPI and Card)'` |
| **gateway_fee_percent** | `decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Gateway/processing fee % added only for Card/Razorpay payments'` |
| **discount_percent** | `decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Discount % deducted from base amount before tax (both UPI and Card)'` |
| **account_label** | `varchar(80) DEFAULT NULL COMMENT 'Admin-defined label for this payment account (e.g. KESA UPI Primary, KESA Razorpay)'` |
| **label_color** | `varchar(7) NOT NULL DEFAULT '#6366f1' COMMENT 'Hex colour for the label badge, e.g. #10b981'` |

## Table: `phone_otps`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **mobile** | `varchar(20) NOT NULL COMMENT 'E.164 or local format mobile number'` |
| **otp_hash** | `varchar(255) NOT NULL COMMENT 'bcrypt hash of the 6-digit OTP'` |
| **purpose** | `varchar(20) NOT NULL DEFAULT 'login'` |
| **attempts** | `tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Wrong-code attempts for this OTP'` |
| **expires_at** | `datetime NOT NULL` |
| **used_at** | `datetime DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **request_ip** | `varchar(45) DEFAULT NULL` |

## Table: `profile_completion`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **is_verified** | `tinyint(1) NOT NULL DEFAULT 0` |
| **completed_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **verified_at** | `datetime DEFAULT NULL` |
| **verified_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **notes** | `text DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `quizzes`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **description** | `text DEFAULT NULL` |
| **duration_minutes** | `int(10) UNSIGNED NOT NULL DEFAULT 30` |
| **max_attempts** | `int(10) UNSIGNED NOT NULL DEFAULT 1` |
| **passing_score** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'Optional passing percentage'` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **shuffle_questions** | `tinyint(1) NOT NULL DEFAULT 0` |
| **show_results** | `tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Show score to learner after submission'` |
| **assigned_instructor_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `quiz_attempts`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **quiz_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **started_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **completed_at** | `timestamp NULL DEFAULT NULL` |
| **time_spent_seconds** | `int(10) UNSIGNED DEFAULT 0` |
| **total_score** | `int(10) UNSIGNED DEFAULT 0` |
| **max_score** | `int(10) UNSIGNED DEFAULT 0` |
| **percentage** | `decimal(5,2) DEFAULT 0.00` |
| **status** | `enum('in_progress','completed','timed_out','auto_submitted') DEFAULT 'in_progress'` |
| **tab_switches** | `int(10) UNSIGNED DEFAULT 0` |

## Table: `quiz_options`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **question_id** | `int(10) UNSIGNED NOT NULL` |
| **option_text** | `varchar(1000) NOT NULL` |
| **is_correct** | `tinyint(1) NOT NULL DEFAULT 0` |
| **sort_order** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |

## Table: `quiz_questions`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **quiz_id** | `int(10) UNSIGNED NOT NULL` |
| **question_text** | `text NOT NULL` |
| **marks** | `int(10) UNSIGNED NOT NULL DEFAULT 1` |
| **sort_order** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |

## Table: `quiz_responses`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **attempt_id** | `int(10) UNSIGNED NOT NULL` |
| **question_id** | `int(10) UNSIGNED NOT NULL` |
| **selected_option_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **is_correct** | `tinyint(1) DEFAULT 0` |
| **marks_awarded** | `int(10) UNSIGNED DEFAULT 0` |
| **answered_at** | `timestamp NULL DEFAULT current_timestamp()` |

## Table: `razorpay_payments`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **registration_id** | `int(10) UNSIGNED NOT NULL` |
| **razorpay_order_id** | `varchar(255) NOT NULL` |
| **razorpay_payment_id** | `varchar(255) DEFAULT NULL` |
| **razorpay_signature** | `varchar(255) DEFAULT NULL` |
| **amount** | `decimal(10,2) NOT NULL` |
| **status** | `enum('created','paid','failed') NOT NULL DEFAULT 'created'` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `register_clicks`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **device_info** | `varchar(255) DEFAULT NULL` |
| **clicked_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `registrations`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **form_data** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(` |
| **payment_method** | `enum('razorpay','upi','free') NOT NULL DEFAULT 'free'` |
| **payment_status** | `enum('pending','paid','verified','rejected') NOT NULL DEFAULT 'pending'` |
| **participation_status** | `enum('pending','attended','not_attended') NOT NULL DEFAULT 'pending'` |
| **payment_id** | `varchar(255) DEFAULT NULL` |
| **payment_proof** | `varchar(255) DEFAULT NULL` |
| **amount** | `decimal(10,2) NOT NULL DEFAULT 0.00` |
| **final_amount** | `decimal(10,2) DEFAULT NULL COMMENT 'Actual amount charged to user after all discounts and taxes'` |
| **coupon_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **coupon_code** | `varchar(50) DEFAULT NULL` |
| **discount_amount** | `decimal(10,2) NOT NULL DEFAULT 0.00` |
| **original_amount** | `decimal(10,2) NOT NULL DEFAULT 0.00` |
| **registered_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **verified_at** | `datetime DEFAULT NULL` |
| **verified_by** | `int(10) UNSIGNED DEFAULT NULL` |
| **payment_label** | `varchar(80) DEFAULT NULL COMMENT 'Label of the payment account used (copied from payment_settings.account_label at time of verification)'` |
| **payment_label_color** | `varchar(7) DEFAULT NULL COMMENT 'Hex colour of the label badge at time of assignment'` |

## Table: `remember_tokens`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **token_hash** | `varchar(255) NOT NULL` |
| **expires_at** | `datetime NOT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `session_attendance`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **session_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **attended** | `tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=attended, 0=not attended'` |
| **marked_at** | `timestamp NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `session_notifications`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **session_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **notification_type** | `enum('scheduled','updated','reminder','live','recording_available') NOT NULL` |
| **sent_at** | `timestamp NULL DEFAULT current_timestamp()` |

## Table: `site_content`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **content_key** | `varchar(100) NOT NULL` |
| **content_value** | `text DEFAULT NULL` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `site_settings`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **setting_key** | `varchar(100) NOT NULL` |
| **setting_value** | `text DEFAULT NULL` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |

## Table: `sms_logs`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **mobile** | `varchar(20) NOT NULL` |
| **endpoint** | `varchar(30) NOT NULL` |
| **http_code** | `int(11) NOT NULL DEFAULT 0` |
| **response** | `varchar(1000) DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `users`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **name** | `varchar(100) NOT NULL` |
| **certificate_name** | `varchar(255) DEFAULT NULL` |
| **certificate_name_verified_at** | `timestamp NULL DEFAULT NULL` |
| **email** | `varchar(255) NOT NULL` |
| **google_id** | `varchar(255) DEFAULT NULL` |
| **password_hash** | `varchar(255) NOT NULL` |
| **phone** | `varchar(20) DEFAULT NULL` |
| **mobile_number** | `varchar(20) DEFAULT NULL COMMENT 'Primary mobile/login number'` |
| **auth_method** | `enum('google','otp','both') NOT NULL DEFAULT 'google' COMMENT 'Primary auth method used'` |
| **whatsapp_collected** | `tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 once WhatsApp popup has been completed'` |
| **dob** | `date DEFAULT NULL` |
| **gender** | `enum('male','female','other') DEFAULT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **state** | `varchar(100) DEFAULT NULL` |
| **district** | `varchar(100) DEFAULT NULL` |
| **college** | `varchar(255) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **role** | `enum('user','admin') NOT NULL DEFAULT 'user'` |
| **email_verified** | `tinyint(1) NOT NULL DEFAULT 0` |
| **is_placeholder** | `tinyint(1) NOT NULL DEFAULT 0` |
| **profile_image** | `varchar(255) DEFAULT NULL` |
| **login_attempts** | `int(11) NOT NULL DEFAULT 0` |
| **locked_until** | `datetime DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **updated_at** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **last_visit_at** | `datetime DEFAULT NULL` |
| **last_ip** | `varchar(45) DEFAULT NULL` |
| **visit_count** | `int(10) UNSIGNED DEFAULT 0` |
| **ip_address** | `varchar(50) DEFAULT NULL` |
| **region** | `varchar(100) DEFAULT NULL` |
| **isp** | `varchar(255) DEFAULT NULL` |
| **as_name** | `varchar(255) DEFAULT NULL` |
| **device_type** | `varchar(50) DEFAULT NULL` |
| **os** | `varchar(100) DEFAULT NULL` |
| **browser** | `varchar(100) DEFAULT NULL` |
| **last_activity** | `timestamp NULL DEFAULT NULL` |
| **last_login** | `timestamp NULL DEFAULT NULL` |
| **merged_into** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'If set, this account was merged into the given user id'` |
| **mobile_verified_at** | `timestamp NULL DEFAULT NULL COMMENT 'When the mobile number was last verified by OTP'` |

## Table: `user_activity_log`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **session_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **action_type** | `varchar(50) NOT NULL COMMENT 'page_view, button_click, download, registration, profile_update, etc.'` |
| **action_details** | `varchar(255) DEFAULT NULL COMMENT 'Specific page/button/file name'` |
| **page_url** | `varchar(500) DEFAULT NULL` |
| **referrer_url** | `varchar(500) DEFAULT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **metadata** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional action-specific data' CHECK (json_valid(` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **deleted_at** | `datetime DEFAULT NULL` |
| **deleted_by_admin** | `tinyint(1) DEFAULT 0` |

## Table: `user_event_activities`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **activity_type** | `enum('registered','session_attended','session_missed','material_read','assignment_submitted','quiz_attempted','quiz_completed','feedback_submitted','certificate_downloaded') NOT NULL` |
| **reference_id** | `int(10) UNSIGNED DEFAULT NULL COMMENT 'ID of session/material/assignment/quiz'` |
| **reference_name** | `varchar(255) DEFAULT NULL` |
| **score** | `decimal(5,2) DEFAULT NULL` |
| **max_score** | `decimal(5,2) DEFAULT NULL` |
| **meta** | `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(` |
| **created_at** | `timestamp NOT NULL DEFAULT current_timestamp()` |

## Table: `user_ip_tracking`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **ip_address** | `varchar(45) NOT NULL` |
| **user_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **first_seen** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **last_seen** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **country** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **visit_count** | `int(10) UNSIGNED NOT NULL DEFAULT 1` |

## Table: `user_login_history`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **login_type** | `enum('login','auto_login','signup') NOT NULL DEFAULT 'login'` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **device_info** | `varchar(255) DEFAULT NULL` |
| **browser_info** | `varchar(255) DEFAULT NULL` |
| **success** | `tinyint(1) NOT NULL DEFAULT 1` |
| **failure_reason** | `varchar(255) DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `user_sessions`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **session_token** | `varchar(64) NOT NULL` |
| **ip_address** | `varchar(45) DEFAULT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **region** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **timezone** | `varchar(50) DEFAULT NULL` |
| **isp** | `varchar(255) DEFAULT NULL` |
| **device_type** | `varchar(50) DEFAULT NULL COMMENT 'desktop, mobile, tablet'` |
| **device_name** | `varchar(255) DEFAULT NULL` |
| **os_name** | `varchar(100) DEFAULT NULL` |
| **os_version** | `varchar(50) DEFAULT NULL` |
| **browser_name** | `varchar(100) DEFAULT NULL` |
| **browser_version** | `varchar(50) DEFAULT NULL` |
| **user_agent** | `text DEFAULT NULL` |
| **screen_resolution** | `varchar(20) DEFAULT NULL` |
| **language** | `varchar(20) DEFAULT NULL` |
| **is_active** | `tinyint(1) NOT NULL DEFAULT 1` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **last_activity** | `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()` |
| **logged_out_at** | `datetime DEFAULT NULL` |

## Table: `user_stats`

| Column | Definition |
|---|---|
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **total_visits** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |
| **total_page_views** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |
| **total_downloads** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |
| **total_events_registered** | `int(10) UNSIGNED NOT NULL DEFAULT 0` |
| **last_visit_at** | `datetime DEFAULT NULL` |
| **last_ip_address** | `varchar(45) DEFAULT NULL` |
| **last_country** | `varchar(100) DEFAULT NULL` |
| **last_city** | `varchar(100) DEFAULT NULL` |
| **registration_ip** | `varchar(45) DEFAULT NULL` |
| **registration_country** | `varchar(100) DEFAULT NULL` |
| **registration_city** | `varchar(100) DEFAULT NULL` |
| **registration_device** | `varchar(255) DEFAULT NULL` |
| **registration_browser** | `varchar(100) DEFAULT NULL` |
| **registration_os** | `varchar(100) DEFAULT NULL` |

## Table: `user_visits`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **ip_address** | `varchar(45) NOT NULL` |
| **country** | `varchar(100) DEFAULT NULL` |
| **state** | `varchar(100) DEFAULT NULL` |
| **city** | `varchar(100) DEFAULT NULL` |
| **device_name** | `varchar(255) DEFAULT NULL` |
| **device_type** | `varchar(50) DEFAULT NULL COMMENT 'desktop, mobile, tablet'` |
| **os** | `varchar(100) DEFAULT NULL` |
| **browser** | `varchar(100) DEFAULT NULL` |
| **user_agent** | `text DEFAULT NULL` |
| **network_type** | `varchar(50) DEFAULT NULL COMMENT 'wifi, cellular, ethernet'` |
| **isp** | `varchar(255) DEFAULT NULL` |
| **as_name** | `varchar(255) DEFAULT NULL` |
| **visited_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `verification_emails`

| Column | Definition |
|---|---|
| **id** | `int(11) NOT NULL` |
| **user_id** | `int(11) NOT NULL` |
| **email_type** | `enum('badge_approved','badge_removed') NOT NULL` |
| **recipient_email** | `varchar(255) NOT NULL` |
| **recipient_name** | `varchar(255) DEFAULT NULL` |
| **status** | `enum('pending','sent','failed') DEFAULT 'pending'` |
| **attempts** | `int(11) DEFAULT 0` |
| **created_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **sent_at** | `timestamp NULL DEFAULT NULL` |
| **error_message** | `text DEFAULT NULL` |

## Table: `whatsapp_broadcasts`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **admin_id** | `int(10) UNSIGNED NOT NULL` |
| **title** | `varchar(255) NOT NULL` |
| **message** | `longtext NOT NULL` |
| **message_type** | `enum('event','manual','all') NOT NULL DEFAULT 'manual'` |
| **event_id** | `int(10) UNSIGNED DEFAULT NULL` |
| **time_gap_seconds** | `int(11) DEFAULT 2` |
| **recipient_count** | `int(11) DEFAULT 0` |
| **sent_count** | `int(11) DEFAULT 0` |
| **status** | `enum('draft','scheduled','sending','completed','failed','paused') NOT NULL DEFAULT 'draft'` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |
| **scheduled_at** | `datetime DEFAULT NULL` |
| **started_at** | `datetime DEFAULT NULL` |
| **completed_at** | `datetime DEFAULT NULL` |

## Table: `whatsapp_invitations`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **event_id** | `int(10) UNSIGNED NOT NULL` |
| **invitation_sent_at** | `timestamp NULL DEFAULT current_timestamp()` |
| **clicked_at** | `timestamp NULL DEFAULT NULL` |

## Table: `whatsapp_queue`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **broadcast_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **phone** | `varchar(20) NOT NULL` |
| **recipient_name** | `varchar(255) NOT NULL` |
| **event_name** | `varchar(255) DEFAULT NULL` |
| **personalized_message** | `longtext NOT NULL` |
| **status** | `enum('pending','sent','failed','error') NOT NULL DEFAULT 'pending'` |
| **error_message** | `varchar(500) DEFAULT NULL` |
| **sent_at** | `datetime DEFAULT NULL` |
| **created_at** | `datetime NOT NULL DEFAULT current_timestamp()` |

## Table: `whatsapp_recipients`

| Column | Definition |
|---|---|
| **id** | `int(10) UNSIGNED NOT NULL` |
| **broadcast_id** | `int(10) UNSIGNED NOT NULL` |
| **user_id** | `int(10) UNSIGNED NOT NULL` |
| **phone** | `varchar(20) NOT NULL` |

