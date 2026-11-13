CREATE TABLE users (
    id VARCHAR(32) PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    display_name VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    verified_at VARCHAR(30) NULL,
    session_version INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE learning_accounts (
    user_id VARCHAR(32) PRIMARY KEY,
    state_json MEDIUMTEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at VARCHAR(30) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE auth_tokens (
    token_hash VARCHAR(64) PRIMARY KEY,
    user_id VARCHAR(32) NOT NULL,
    purpose VARCHAR(12) NOT NULL,
    expires_at INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX auth_tokens_expiry ON auth_tokens (expires_at);
CREATE TABLE rate_limits (
    bucket VARCHAR(64) PRIMARY KEY,
    window_start INTEGER NOT NULL,
    hits INTEGER NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
