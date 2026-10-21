-- Import this file in phpMyAdmin after you open the database.
-- Shared hosting blocks CREATE DATABASE, so this file does not create one.
-- Local XAMPP: open the database named spcc_academic, then Import.
-- InfinityFree: open if0_43038034_spcc_academic, then Import.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS prediction_results;
DROP TABLE IF EXISTS legacy_predictions;
DROP TABLE IF EXISTS model_runs;
DROP TABLE IF EXISTS historical_training_data;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS scholarship_assignments;
DROP TABLE IF EXISTS scholarship_categories;
DROP TABLE IF EXISTS recognition_events;
DROP TABLE IF EXISTS recognition_cases;
DROP TABLE IF EXISTS dl_pl_evaluations;
DROP TABLE IF EXISTS eligibility_rules;
DROP TABLE IF EXISTS submission_snapshots;
DROP TABLE IF EXISTS submission_reviews;
DROP TABLE IF EXISTS official_grades;
DROP TABLE IF EXISTS grade_submissions;
DROP TABLE IF EXISTS score_adjustments;
DROP TABLE IF EXISTS assessment_scores;
DROP TABLE IF EXISTS assessment_components;
DROP TABLE IF EXISTS category_weights;
DROP TABLE IF EXISTS period_weights;
DROP TABLE IF EXISTS default_category_weights;
DROP TABLE IF EXISTS grading_scale;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS professor_assignments;
DROP TABLE IF EXISTS professors;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS semesters;
DROP TABLE IF EXISTS academic_years;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS programs;
DROP TABLE IF EXISTS school_settings;

CREATE TABLE roles (
    id TINYINT UNSIGNED NOT NULL,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    title VARCHAR(20) NOT NULL DEFAULT '',
    email VARCHAR(150) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(150) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_programs_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id TINYINT UNSIGNED NOT NULL,
    program_id INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id),
    CONSTRAINT fk_user_roles_program FOREIGN KEY (program_id) REFERENCES programs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_professors_user (user_id),
    CONSTRAINT fk_professors_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE academic_years (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    label VARCHAR(20) NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_years_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE semesters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    academic_year_id INT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    prelim_deadline DATE DEFAULT NULL,
    midterm_deadline DATE DEFAULT NULL,
    finals_deadline DATE DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_semester (academic_year_id, name),
    CONSTRAINT fk_semesters_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id INT UNSIGNED NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(150) NOT NULL,
    year_level VARCHAR(20) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_subject (program_id, code),
    CONSTRAINT fk_subjects_program FOREIGN KEY (program_id) REFERENCES programs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id INT UNSIGNED NOT NULL,
    academic_year_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    year_level VARCHAR(20) NOT NULL,
    name VARCHAR(20) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_section (program_id, semester_id, year_level, name),
    CONSTRAINT fk_sections_program FOREIGN KEY (program_id) REFERENCES programs (id),
    CONSTRAINT fk_sections_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
    CONSTRAINT fk_sections_semester FOREIGN KEY (semester_id) REFERENCES semesters (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_number VARCHAR(20) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    student_type ENUM('Regular','Irregular') NOT NULL DEFAULT 'Regular',
    program_id INT UNSIGNED NOT NULL,
    year_level VARCHAR(20) NOT NULL,
    section_id INT UNSIGNED DEFAULT NULL,
    adviser_id INT UNSIGNED DEFAULT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_student_number (student_number),
    KEY idx_students_program (program_id),
    KEY idx_students_adviser (adviser_id),
    CONSTRAINT fk_students_program FOREIGN KEY (program_id) REFERENCES programs (id),
    CONSTRAINT fk_students_section FOREIGN KEY (section_id) REFERENCES sections (id),
    CONSTRAINT fk_students_adviser FOREIGN KEY (adviser_id) REFERENCES professors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professor_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    professor_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_assignment (subject_id, section_id, semester_id),
    CONSTRAINT fk_assign_professor FOREIGN KEY (professor_id) REFERENCES professors (id),
    CONSTRAINT fk_assign_subject FOREIGN KEY (subject_id) REFERENCES subjects (id),
    CONSTRAINT fk_assign_section FOREIGN KEY (section_id) REFERENCES sections (id),
    CONSTRAINT fk_assign_semester FOREIGN KEY (semester_id) REFERENCES semesters (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE enrollments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_enrollment (assignment_id, student_id),
    CONSTRAINT fk_enroll_assignment FOREIGN KEY (assignment_id) REFERENCES professor_assignments (id),
    CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grading_scale (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    min_percent DECIMAL(5,2) NOT NULL,
    max_percent DECIMAL(5,2) NOT NULL,
    equivalent DECIMAL(3,2) NOT NULL,
    description VARCHAR(40) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE default_category_weights (
    category VARCHAR(20) NOT NULL,
    weight_percent DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_weights (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    period ENUM('prelim','midterm','finals') NOT NULL,
    category VARCHAR(20) NOT NULL,
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_category_weight (assignment_id, period, category),
    CONSTRAINT fk_cat_weight_assignment FOREIGN KEY (assignment_id) REFERENCES professor_assignments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE period_weights (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    period ENUM('prelim','midterm','finals') NOT NULL,
    weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_period_weight (assignment_id, period),
    CONSTRAINT fk_period_weight_assignment FOREIGN KEY (assignment_id) REFERENCES professor_assignments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_components (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    period ENUM('prelim','midterm','finals') NOT NULL,
    category VARCHAR(20) NOT NULL,
    code VARCHAR(20) NOT NULL,
    label VARCHAR(80) NOT NULL,
    max_score DECIMAL(6,2) NOT NULL,
    item_date DATE DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_component (assignment_id, period, category, code),
    KEY idx_components_assignment (assignment_id),
    CONSTRAINT fk_components_assignment FOREIGN KEY (assignment_id) REFERENCES professor_assignments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assessment_scores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    component_id INT UNSIGNED NOT NULL,
    enrollment_id INT UNSIGNED NOT NULL,
    score DECIMAL(6,2) DEFAULT NULL,
    mark VARCHAR(12) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_score (component_id, enrollment_id),
    CONSTRAINT fk_scores_component FOREIGN KEY (component_id) REFERENCES assessment_components (id) ON DELETE CASCADE,
    CONSTRAINT fk_scores_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE score_adjustments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    enrollment_id INT UNSIGNED NOT NULL,
    period ENUM('prelim','midterm','finals') NOT NULL,
    adjustment DECIMAL(6,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_adjustment (assignment_id, enrollment_id, period),
    CONSTRAINT fk_adj_assignment FOREIGN KEY (assignment_id) REFERENCES professor_assignments (id) ON DELETE CASCADE,
    CONSTRAINT fk_adj_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grade_submissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id INT UNSIGNED NOT NULL,
    status ENUM('draft','submitted','returned','chair_verified','oic_approved','osa_endorsed','registrar_finalized') NOT NULL DEFAULT 'draft',
    submitted_periods VARCHAR(64) NOT NULL DEFAULT '',
    submitted_at DATETIME DEFAULT NULL,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    version INT NOT NULL DEFAULT 1,
    return_note TEXT DEFAULT NULL,
    flag_note TEXT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_submission_assignment (assignment_id),
    CONSTRAINT fk_submission_assignment FOREIGN KEY (assignment_id) REFERENCES professor_assignments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submission_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    from_status VARCHAR(40) NOT NULL,
    to_status VARCHAR(40) NOT NULL,
    remarks TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reviews_submission (submission_id),
    CONSTRAINT fk_reviews_submission FOREIGN KEY (submission_id) REFERENCES grade_submissions (id),
    CONSTRAINT fk_reviews_user FOREIGN KEY (reviewer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submission_snapshots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id INT UNSIGNED NOT NULL,
    version INT NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_snapshots_submission (submission_id),
    CONSTRAINT fk_snapshots_submission FOREIGN KEY (submission_id) REFERENCES grade_submissions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE official_grades (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    enrollment_id INT UNSIGNED NOT NULL,
    prelim_percent DECIMAL(5,2) DEFAULT NULL,
    prelim_eqv DECIMAL(3,2) DEFAULT NULL,
    midterm_percent DECIMAL(5,2) DEFAULT NULL,
    midterm_eqv DECIMAL(3,2) DEFAULT NULL,
    finals_percent DECIMAL(5,2) DEFAULT NULL,
    finals_eqv DECIMAL(3,2) DEFAULT NULL,
    final_percent DECIMAL(5,2) DEFAULT NULL,
    final_eqv DECIMAL(3,2) DEFAULT NULL,
    remarks VARCHAR(20) DEFAULT NULL,
    finalized_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_official_enrollment (enrollment_id),
    CONSTRAINT fk_official_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE eligibility_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    semester_id INT UNSIGNED DEFAULT NULL,
    irregular_ineligible TINYINT(1) NOT NULL DEFAULT 0,
    disqualify_at DECIMAL(3,2) NOT NULL DEFAULT 2.00,
    pl_min DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    pl_max DECIMAL(3,2) NOT NULL DEFAULT 1.25,
    dl_min DECIMAL(3,2) NOT NULL DEFAULT 1.26,
    dl_max DECIMAL(3,2) NOT NULL DEFAULT 1.75,
    confirmed TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dl_pl_evaluations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    basis ENUM('predicted','official') NOT NULL,
    gwa DECIMAL(4,2) DEFAULT NULL,
    status VARCHAR(40) NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    review_note TEXT DEFAULT NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 0,
    reviewer_id INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_eval (student_id, semester_id, basis),
    CONSTRAINT fk_eval_student FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT fk_eval_semester FOREIGN KEY (semester_id) REFERENCES semesters (id),
    CONSTRAINT fk_eval_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarship_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    discount_percent DECIMAL(5,2) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarship_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    discount_percent DECIMAL(5,2) NOT NULL,
    status ENUM('assigned','revoked') NOT NULL DEFAULT 'assigned',
    reviewer_id INT UNSIGNED DEFAULT NULL,
    notes TEXT,
    assigned_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_scholarship_term (student_id, semester_id),
    CONSTRAINT fk_sch_student FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT fk_sch_semester FOREIGN KEY (semester_id) REFERENCES semesters (id),
    CONSTRAINT fk_sch_category FOREIGN KEY (category_id) REFERENCES scholarship_categories (id),
    CONSTRAINT fk_sch_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    link_url VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id, is_read),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(60) NOT NULL,
    entity VARCHAR(60) NOT NULL,
    entity_id VARCHAR(40) DEFAULT NULL,
    details TEXT,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE historical_training_data (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_student_id VARCHAR(30) NOT NULL,
    attendance_score DECIMAL(8,2) NOT NULL,
    attendance_total DECIMAL(8,2) NOT NULL,
    recitation_score DECIMAL(8,2) NOT NULL,
    recitation_total DECIMAL(8,2) NOT NULL,
    activities_score DECIMAL(8,2) NOT NULL,
    activities_total DECIMAL(8,2) NOT NULL,
    quizzes_score DECIMAL(8,2) NOT NULL,
    quizzes_total DECIMAL(8,2) NOT NULL,
    project_score DECIMAL(8,2) NOT NULL,
    project_total DECIMAL(8,2) NOT NULL,
    exam_score DECIMAL(8,2) NOT NULL,
    exam_total DECIMAL(8,2) NOT NULL,
    actual_final_grade DECIMAL(6,2) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE model_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    model_name VARCHAR(60) NOT NULL,
    mae DECIMAL(8,4) DEFAULT NULL,
    r2 DECIMAL(8,4) DEFAULT NULL,
    sample_size INT DEFAULT NULL,
    train_size INT DEFAULT NULL,
    test_size INT DEFAULT NULL,
    trained_at DATETIME DEFAULT NULL,
    version VARCHAR(40) DEFAULT NULL,
    source VARCHAR(40) NOT NULL,
    notes TEXT,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prediction_results (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED DEFAULT NULL,
    model_name VARCHAR(60) NOT NULL,
    predicted_percent DECIMAL(5,2) DEFAULT NULL,
    predicted_equivalent DECIMAL(3,2) DEFAULT NULL,
    run_label VARCHAR(40) NOT NULL DEFAULT 'advisory-sheet',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_predictions_student (student_id, semester_id),
    CONSTRAINT fk_pred_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_predictions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_number VARCHAR(20) NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    student_type VARCHAR(20) NOT NULL,
    year_level VARCHAR(20) NOT NULL,
    program VARCHAR(20) NOT NULL,
    section_name VARCHAR(20) NOT NULL,
    subject_name VARCHAR(150) NOT NULL,
    quiz_avg DECIMAL(5,2) NOT NULL,
    activities_avg DECIMAL(5,2) NOT NULL,
    exam DECIMAL(5,2) NOT NULL,
    peta DECIMAL(5,2) NOT NULL,
    predicted_percent DECIMAL(5,2) NOT NULL,
    numerical_grade DECIMAL(3,2) NOT NULL,
    predicted_gwa DECIMAL(4,2) DEFAULT NULL,
    dlpl_status VARCHAR(120) NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recognition_cases (
    id INT UNSIGNED AUTO_INCREMENT,
    student_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NOT NULL,
    list_type ENUM('DL', 'PL') NOT NULL,
    gwa DECIMAL(4,2) DEFAULT NULL,
    standing VARCHAR(40) NOT NULL,
    stage VARCHAR(40) NOT NULL,
    criteria_id INT UNSIGNED DEFAULT NULL,
    last_note TEXT DEFAULT NULL,
    required_action TEXT DEFAULT NULL,
    chair_user_id INT UNSIGNED DEFAULT NULL,
    endorsed_at DATETIME DEFAULT NULL,
    oic_user_id INT UNSIGNED DEFAULT NULL,
    committee_at DATETIME DEFAULT NULL,
    osa_user_id INT UNSIGNED DEFAULT NULL,
    osa_at DATETIME DEFAULT NULL,
    registrar_user_id INT UNSIGNED DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_recognition_case (student_id, semester_id),
    KEY idx_recognition_stage (stage, program_id),
    CONSTRAINT fk_recognition_student FOREIGN KEY (student_id) REFERENCES students (id),
    CONSTRAINT fk_recognition_semester FOREIGN KEY (semester_id) REFERENCES semesters (id),
    CONSTRAINT fk_recognition_program FOREIGN KEY (program_id) REFERENCES programs (id),
    CONSTRAINT fk_recognition_chair FOREIGN KEY (chair_user_id) REFERENCES users (id),
    CONSTRAINT fk_recognition_oic FOREIGN KEY (oic_user_id) REFERENCES users (id),
    CONSTRAINT fk_recognition_osa FOREIGN KEY (osa_user_id) REFERENCES users (id),
    CONSTRAINT fk_recognition_registrar FOREIGN KEY (registrar_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recognition_events (
    id INT UNSIGNED AUTO_INCREMENT,
    case_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(40) NOT NULL,
    from_stage VARCHAR(40) DEFAULT NULL,
    to_stage VARCHAR(40) NOT NULL,
    note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recognition_events_case (case_id, id),
    CONSTRAINT fk_recognition_event_case FOREIGN KEY (case_id) REFERENCES recognition_cases (id) ON DELETE CASCADE,
    CONSTRAINT fk_recognition_event_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE school_settings (
    setting_key VARCHAR(60) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO roles (id, code, name) VALUES
(1, 'system_admin', 'System Administrator'),
(2, 'professor', 'Professor'),
(3, 'program_chair', 'Program Chair'),
(4, 'college_oic', 'College Department OIC'),
(5, 'osa', 'Office of Student Affairs'),
(6, 'registrar', 'Registrar');

INSERT INTO users (id, username, password_hash, full_name, title, must_change_password) VALUES
(1, 'admin', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'System Administrator', '', 1),
(2, 'fquiza', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'Febrick Quiza', 'Mr.', 1),
(3, 'psantos', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'Santos, Patricia', 'Ms.', 1),
(4, 'chair.bsit', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'BSIT Program Chair', '', 1),
(5, 'chair.bshm', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'BSHM Program Chair', '', 1),
(6, 'oic', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'Cristobal', 'Sir', 1),
(7, 'osa', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'Student Affairs Officer', '', 1),
(8, 'registrar', '$2y$10$SdzXZd1D6Paybz94fh7X.uc7RtDSi5JvgZKSc8GjUIYiGSFQ3I7gi', 'College Registrar', '', 1);

INSERT INTO programs (id, code, name) VALUES
(1, 'BSIT', 'Bachelor of Science in Information Technology'),
(2, 'BSHM', 'Bachelor of Science in Hospitality Management'),
(3, 'BSTM', 'Bachelor of Science in Tourism Management'),
(4, 'BSBA', 'Bachelor of Science in Business Administration');

INSERT INTO user_roles (user_id, role_id, program_id) VALUES
(1, 1, NULL),
(2, 2, NULL),
(3, 2, NULL),
(4, 3, 1),
(5, 3, 2),
(6, 4, NULL),
(7, 5, NULL),
(8, 6, NULL);

INSERT INTO professors (id, user_id) VALUES (1, 2), (2, 3);

INSERT INTO academic_years (id, label, is_current) VALUES (1, '2026-2027', 1);
INSERT INTO semesters (id, academic_year_id, name, is_current, prelim_deadline, midterm_deadline, finals_deadline) VALUES
(1, 1, '1st Semester', 1, '2026-10-15', '2026-11-20', '2026-12-18');

INSERT INTO subjects (id, program_id, code, name, year_level) VALUES
(1, 1, 'ITP1', 'IT Project 1', '4th Year'),
(2, 1, 'SAM', 'SAM', '4th Year'),
(3, 1, 'IAS2', 'IAS 2', '4th Year'),
(4, 1, 'FDWDM', 'FDWDM', '3rd Year'),
(5, 1, 'SOCPRO', 'SOCPRO', '4th Year'),
(6, 2, 'THC101', 'Macro Perspective of Tourism and Hospitality', '1st Year'),
(7, 3, 'TM101', 'Tourism Management Sample', '1st Year'),
(8, 4, 'BA101', 'Business Administration Sample', '1st Year');

INSERT INTO sections (id, program_id, academic_year_id, semester_id, year_level, name) VALUES
(1, 1, 1, 1, '4th Year', '401-C'),
(2, 1, 1, 1, '4th Year', '401-B'),
(3, 2, 1, 1, '1st Year', 'HM1A'),
(4, 3, 1, 1, '1st Year', 'TM1A'),
(5, 4, 1, 1, '1st Year', 'BA1A');

INSERT INTO students (id, student_number, full_name, student_type, program_id, year_level, section_id, is_demo) VALUES
(1, '04-24-32733', 'Dela Cruz, Reena DC', 'Regular', 1, '4th Year', 1, 1),
(2, '08-15-41258', 'Agpoon, Cyrus', 'Irregular', 1, '4th Year', 1, 1),
(3, '06-21-40517', 'Domingo, Maluntag Cabuang III', 'Regular', 1, '4th Year', 1, 1),
(4, '09-18-43826', 'Villajuan, Vlen Alhen Capalad', 'Regular', 1, '4th Year', 1, 1),
(5, '11-03-38192', 'Bonifacio, Jed', 'Regular', 1, '4th Year', 2, 1),
(6, '01-26-10001', 'Santos, Andrea D.', 'Regular', 1, '4th Year', 1, 1),
(7, '01-26-10002', 'Reyes, Marco L.', 'Regular', 1, '4th Year', 1, 1),
(8, '01-26-10003', 'Cruz, Hannah P.', 'Regular', 1, '4th Year', 1, 1),
(9, '01-26-10004', 'Garcia, Luis M.', 'Regular', 1, '4th Year', 1, 1),
(10, '01-26-10005', 'Flores, Nina S.', 'Regular', 1, '4th Year', 1, 1),
(11, '01-26-10006', 'Ramos, Paolo T.', 'Regular', 1, '4th Year', 1, 1),
(12, '01-26-10007', 'Mendoza, Clara V.', 'Regular', 1, '4th Year', 1, 1),
(13, '01-26-10008', 'Torres, Ethan J.', 'Regular', 1, '4th Year', 1, 1),
(14, '02-26-20001', 'Navarro, Pia D.', 'Regular', 2, '1st Year', 3, 1),
(15, '02-26-20002', 'Lim, Jonah C.', 'Regular', 2, '1st Year', 3, 1),
(16, '02-26-20003', 'Ortiz, Bea M.', 'Irregular', 2, '1st Year', 3, 1);

INSERT INTO professor_assignments (id, professor_id, subject_id, section_id, semester_id) VALUES
(1, 1, 1, 1, 1),
(2, 1, 2, 1, 1),
(3, 2, 6, 3, 1);

INSERT INTO enrollments (assignment_id, student_id)
SELECT 1, id FROM students WHERE section_id = 1
UNION ALL SELECT 2, id FROM students WHERE section_id = 1
UNION ALL SELECT 3, id FROM students WHERE section_id = 3;

INSERT INTO grading_scale (min_percent, max_percent, equivalent, description, sort_order) VALUES
(98, 100, 1.00, 'Excellent', 1),
(95, 97.99, 1.25, 'Very Superior', 2),
(92, 94.99, 1.50, 'Superior', 3),
(89, 91.99, 1.75, 'Very Good', 4),
(86, 88.99, 2.00, 'Very Satisfactory', 5),
(83, 85.99, 2.25, 'Good', 6),
(80, 82.99, 2.50, 'Satisfactory', 7),
(77, 79.99, 2.75, 'Fair', 8),
(75, 76.99, 3.00, 'Passed', 9),
(0, 74.99, 5.00, 'Failed', 10);

INSERT INTO default_category_weights (category, weight_percent) VALUES
('attendance', 10),
('recitation', 10),
('activities', 10),
('quizzes', 15),
('project', 15),
('exam', 40);

INSERT INTO category_weights (assignment_id, period, category, weight_percent)
SELECT a.id, p.period, d.category, d.weight_percent
FROM professor_assignments a
CROSS JOIN (SELECT 'prelim' AS period UNION SELECT 'midterm' UNION SELECT 'finals') p
CROSS JOIN default_category_weights d;

INSERT INTO period_weights (assignment_id, period, weight_percent)
SELECT id, 'prelim', 30 FROM professor_assignments
UNION ALL SELECT id, 'midterm', 30 FROM professor_assignments
UNION ALL SELECT id, 'finals', 40 FROM professor_assignments;

INSERT INTO assessment_components (assignment_id, period, category, code, label, max_score, sort_order)
SELECT a.id, p.period, t.category, t.code, t.label, t.max_score, t.sort_order
FROM professor_assignments a
CROSS JOIN (SELECT 'prelim' AS period UNION SELECT 'midterm' UNION SELECT 'finals') p
CROSS JOIN (
    SELECT 'attendance' AS category, 'WK1' AS code, 'Attendance 1' AS label, 10.00 AS max_score, 1 AS sort_order
    UNION ALL SELECT 'attendance', 'WK2', 'Attendance 2', 10, 2
    UNION ALL SELECT 'attendance', 'WK3', 'Attendance 3', 10, 3
    UNION ALL SELECT 'attendance', 'WK4', 'Attendance 4', 10, 4
    UNION ALL SELECT 'attendance', 'WK5', 'Attendance 5', 10, 5
    UNION ALL SELECT 'recitation', 'REC#1', 'Recitation 1', 10, 6
    UNION ALL SELECT 'recitation', 'REC#2', 'Recitation 2', 10, 7
    UNION ALL SELECT 'recitation', 'REC#3', 'Recitation 3', 10, 8
    UNION ALL SELECT 'recitation', 'REC#4', 'Recitation 4', 10, 9
    UNION ALL SELECT 'recitation', 'REC#5', 'Recitation 5', 10, 10
    UNION ALL SELECT 'activities', 'ACT#1', 'Activity 1', 10, 11
    UNION ALL SELECT 'activities', 'ACT#2', 'Activity 2', 10, 12
    UNION ALL SELECT 'activities', 'ACT#3', 'Activity 3', 10, 13
    UNION ALL SELECT 'activities', 'ACT#4', 'Activity 4', 10, 14
    UNION ALL SELECT 'activities', 'ACT#5', 'Activity 5', 10, 15
    UNION ALL SELECT 'quizzes', 'Q1', 'Quiz 1', 20, 16
    UNION ALL SELECT 'quizzes', 'Q2', 'Quiz 2', 20, 17
    UNION ALL SELECT 'quizzes', 'Q3', 'Quiz 3', 20, 18
    UNION ALL SELECT 'quizzes', 'Q4', 'Quiz 4', 20, 19
    UNION ALL SELECT 'quizzes', 'Q5', 'Quiz 5', 20, 20
    UNION ALL SELECT 'project', 'PR', 'Project', 100, 21
    UNION ALL SELECT 'exam', 'EX', 'Exam', 100, 22
) t;

INSERT INTO assessment_scores (component_id, enrollment_id, score)
SELECT c.id, e.id, c.max_score
FROM assessment_components c
JOIN enrollments e ON e.assignment_id = c.assignment_id
JOIN students s ON s.id = e.student_id
WHERE c.assignment_id = 1 AND s.student_number IN ('04-24-32733', '08-15-41258');

INSERT INTO assessment_scores (component_id, enrollment_id, score)
SELECT c.id, e.id, ROUND(c.max_score * 0.85, 2)
FROM assessment_components c
JOIN enrollments e ON e.assignment_id = c.assignment_id
JOIN students s ON s.id = e.student_id
WHERE c.assignment_id = 1 AND s.student_number = '06-21-40517';

INSERT INTO assessment_scores (component_id, enrollment_id, score)
SELECT c.id, e.id, ROUND(c.max_score * 0.95, 2)
FROM assessment_components c
JOIN enrollments e ON e.assignment_id = c.assignment_id
JOIN students s ON s.id = e.student_id
WHERE c.assignment_id = 1 AND c.period = 'prelim' AND s.student_number = '09-18-43826';

INSERT INTO assessment_scores (component_id, enrollment_id, score)
SELECT c.id, e.id, ROUND(c.max_score * 0.92, 2)
FROM assessment_components c
JOIN enrollments e ON e.assignment_id = c.assignment_id
WHERE c.assignment_id = 3;

INSERT INTO grade_submissions (id, assignment_id, status, submitted_periods, submitted_at, version) VALUES
(1, 1, 'draft', '', NULL, 1),
(2, 2, 'draft', '', NULL, 1),
(3, 3, 'submitted', 'prelim,midterm,finals', '2026-09-27 15:00:00', 1);

INSERT INTO submission_reviews (submission_id, reviewer_id, action, from_status, to_status, remarks, created_at) VALUES
(3, 3, 'submit', 'draft', 'submitted', 'Submitted from the grading sheet.', '2026-09-27 15:00:00');

INSERT INTO eligibility_rules (id, irregular_ineligible, disqualify_at, pl_min, pl_max, dl_min, dl_max, confirmed, notes) VALUES
(1, 0, 2.00, 1.00, 1.25, 1.26, 1.75, 0,
 'A subject grade numerically greater than or equal to 2.00 disqualifies a student. Regular and irregular students use the same ranges. GWA 1.00 to 1.25 is marked Possible PL. GWA 1.26 to 1.75 is marked Possible DL.');

INSERT INTO scholarship_categories (name, discount_percent, description) VALUES
('Dean''s List scholarship', NULL, 'Discount is blank until the college confirms the official percentage.'),
('President''s List scholarship', NULL, 'Discount is blank until the college confirms the official percentage.');

INSERT INTO notifications (user_id, title, body, link_url) VALUES
(5, 'Grades submitted', 'Ms. Santos, Patricia submitted THC101 for section HM1A.', 'admin/review.php?id=3');

INSERT INTO school_settings (setting_key, setting_value) VALUES
('period_prelim', '30'),
('period_midterm', '30'),
('period_finals', '40'),
('demo_banner', '1');



INSERT INTO legacy_predictions (student_number, student_name, student_type, year_level, program, section_name, subject_name, quiz_avg, activities_avg, exam, peta, predicted_percent, numerical_grade, predicted_gwa, dlpl_status, recorded_at) VALUES
('04-24-32733', 'Dela Cruz, Reena DC', 'Regular', '4th Year', 'BSIT', '401-C', 'IT Project 1', 88, 87, 85, 80, 85.12, 2.25, 2.25, 'Not DL/PL Qualified - Has a Disqualifying Grade', '2026-09-27 13:39:05'),
('08-15-41258', 'Agpoon, Cyrus', 'Irregular', '4th Year', 'BSIT', '401-C', 'FDWDM', 90, 80, 90, 96, 88.43, 2.00, 2.00, 'Not Eligible for DL/PL - Irregular Student', '2026-09-27 13:39:42'),
('11-03-38192', 'Bonifacio, Jed', 'Regular', '4th Year', 'BSIT', '401-B', 'IT Project 1', 95, 95, 95, 95, 94.80, 1.50, 1.50, 'Possible DL - For Final Evaluation', '2026-09-27 13:40:01');



