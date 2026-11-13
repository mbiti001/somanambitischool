SET @smb_collation = (SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id');
SET @smb_schema = REPLACE('CREATE TABLE IF NOT EXISTS speaking_accounts (
 user_id VARCHAR(32) PRIMARY KEY,
 state_json MEDIUMTEXT NOT NULL,
 version INTEGER NOT NULL DEFAULT 1,
 updated_at VARCHAR(30) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'utf8mb4_unicode_ci', @smb_collation);
PREPARE smb_migration FROM @smb_schema;
EXECUTE smb_migration;
DEALLOCATE PREPARE smb_migration;
SET @smb_schema = REPLACE('CREATE TABLE IF NOT EXISTS speaking_quiz_results (
 id VARCHAR(32) PRIMARY KEY,
 user_id VARCHAR(32) NOT NULL,
 module_index INTEGER NOT NULL,
 answers_json MEDIUMTEXT NOT NULL,
 score INTEGER NOT NULL,
 submitted_at VARCHAR(30) NOT NULL,
 UNIQUE (user_id,id),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', 'utf8mb4_unicode_ci', @smb_collation);
PREPARE smb_migration FROM @smb_schema;
EXECUTE smb_migration;
DEALLOCATE PREPARE smb_migration;
