CREATE TABLE IF NOT EXISTS student_number_counter (
    counter_key VARCHAR(20) PRIMARY KEY,
    next_number BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO student_number_counter (counter_key,next_number)
SELECT 'student',0 WHERE NOT EXISTS (SELECT 1 FROM student_number_counter WHERE counter_key='student');
CREATE TABLE IF NOT EXISTS course_students (
    user_id VARCHAR(32) PRIMARY KEY,
    student_sequence BIGINT NOT NULL UNIQUE,
    enrolled_at VARCHAR(30) NOT NULL,
    full_name VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    region VARCHAR(80) NULL,
    experience VARCHAR(30) NULL,
    learning_goal VARCHAR(40) NULL,
    preferred_language VARCHAR(20) NULL,
    occupation VARCHAR(30) NULL,
    consented_at VARCHAR(30) NULL,
    profile_updated_at VARCHAR(30) NULL,
    profile_version INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS assessment_attempts (
    id VARCHAR(32) PRIMARY KEY,
    user_id VARCHAR(32) NOT NULL,
    assessment_key VARCHAR(20) NOT NULL,
    active_key VARCHAR(60) NULL UNIQUE,
    paper_json MEDIUMTEXT NOT NULL,
    answers_json MEDIUMTEXT NOT NULL,
    result_json MEDIUMTEXT NULL,
    current_question INTEGER NOT NULL DEFAULT 0,
    deadline_at BIGINT NOT NULL,
    started_at VARCHAR(30) NOT NULL,
    updated_at VARCHAR(30) NOT NULL,
    submitted_at VARCHAR(30) NULL,
    score INTEGER NULL,
    maximum INTEGER NULL,
    passed INTEGER NULL,
    version INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_certificates (
    id VARCHAR(32) PRIMARY KEY,
    user_id VARCHAR(32) NOT NULL UNIQUE,
    certificate_number VARCHAR(40) NOT NULL UNIQUE,
    student_number VARCHAR(40) NOT NULL,
    student_name VARCHAR(120) NOT NULL,
    course_title VARCHAR(160) NOT NULL,
    attempt_id VARCHAR(32) NOT NULL,
    score INTEGER NOT NULL,
    maximum INTEGER NOT NULL,
    signatories_json MEDIUMTEXT NOT NULL,
    issued_at VARCHAR(30) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (attempt_id) REFERENCES assessment_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
