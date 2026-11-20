-- ==========================================
-- Company Portal Database
-- Version : 1.1
-- Author  : Nivlektech
-- Compatible :
-- MySQL 8.x / 9.x
-- PHP 8.x
-- IIS / Apache
-- ==========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ==========================================
-- USERS
-- ==========================================

CREATE TABLE users
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    fullname VARCHAR(150) NOT NULL,

    username VARCHAR(50) NOT NULL UNIQUE,

    email VARCHAR(150) NOT NULL UNIQUE,

    password VARCHAR(255) NOT NULL,

    phone VARCHAR(20),

    department_id INT DEFAULT NULL,

    role ENUM
    (
        'admin',
        'user'
    ) DEFAULT 'user',

    status ENUM
    (
        'active',
        'disabled'
    ) DEFAULT 'active',

    remember_token VARCHAR(255),

    last_login DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NULL DEFAULT NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- DEPARTMENTS
-- ==========================================

CREATE TABLE departments
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    department_name VARCHAR(100) NOT NULL,

    description TEXT,

    status ENUM
    (
        'active',
        'disabled'
    ) DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- EMPLOYEES
-- ==========================================

CREATE TABLE employees
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    employee_code VARCHAR(30) UNIQUE,

    fullname VARCHAR(150),

    email VARCHAR(150),

    phone VARCHAR(20),

    designation VARCHAR(100),

    department_id INT,

    address TEXT,

    joining_date DATE,

    profile_photo VARCHAR(255),

    status ENUM
    (
        'active',
        'disabled'
    ) DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- ANNOUNCEMENTS
-- ==========================================

CREATE TABLE announcements
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255),

    description TEXT,

    created_by INT,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- DOCUMENTS
-- ==========================================

CREATE TABLE documents
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255),

    description TEXT,

    filename VARCHAR(255),

    filesize BIGINT,

    uploaded_by INT,

    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- UPLOADS
-- ==========================================

CREATE TABLE uploads
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255) NOT NULL,

    description TEXT,

    filename VARCHAR(255) NOT NULL,

    original_name VARCHAR(255),

    filesize BIGINT,

    filetype VARCHAR(100),

    uploaded_by INT,

    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- ACTIVITY LOGS
-- ==========================================

CREATE TABLE activity_logs
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT,

    activity VARCHAR(255) NOT NULL,

    ip_address VARCHAR(50),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- SETTINGS
-- ==========================================

CREATE TABLE settings
(
    id INT AUTO_INCREMENT PRIMARY KEY,

    company_name VARCHAR(255),

    company_email VARCHAR(255),

    company_phone VARCHAR(50),

    company_address TEXT,

    company_website VARCHAR(255),

    company_logo VARCHAR(255),

    timezone VARCHAR(100) DEFAULT 'Asia/Kolkata',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP NULL DEFAULT NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- DEFAULT DEPARTMENTS
-- ==========================================

INSERT INTO departments
(
    department_name,
    description
)
VALUES
('Administration','Administration Department'),
('Human Resource','HR Department'),
('IT','IT Department'),
('Accounts','Accounts Department'),
('Sales','Sales Department');

-- ==========================================
-- DEFAULT SETTINGS
-- ==========================================

INSERT INTO settings
(
    company_name,
    company_email,
    company_phone,
    company_address,
    company_website,
    company_logo,
    timezone
)
VALUES
(
    'My Company',
    '',
    '',
    '',
    '',
    '',
    'Asia/Kolkata'
);

-- ==========================================
-- FOREIGN KEYS
-- ==========================================

ALTER TABLE users
ADD CONSTRAINT fk_users_department
FOREIGN KEY (department_id)
REFERENCES departments(id)
ON DELETE SET NULL;

ALTER TABLE employees
ADD CONSTRAINT fk_employee_department
FOREIGN KEY (department_id)
REFERENCES departments(id)
ON DELETE SET NULL;

ALTER TABLE announcements
ADD CONSTRAINT fk_announcements_user
FOREIGN KEY (created_by)
REFERENCES users(id)
ON DELETE SET NULL;

ALTER TABLE documents
ADD CONSTRAINT fk_documents_user
FOREIGN KEY (uploaded_by)
REFERENCES users(id)
ON DELETE SET NULL;

ALTER TABLE uploads
ADD CONSTRAINT fk_uploads_user
FOREIGN KEY (uploaded_by)
REFERENCES users(id)
ON DELETE SET NULL;

ALTER TABLE activity_logs
ADD CONSTRAINT fk_logs_user
FOREIGN KEY (user_id)
REFERENCES users(id)
ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================
-- TICKETING / LIVE MANAGEMENT / ANALYTICS / EMAIL (added in 1.1)
-- These are also created automatically the first time a ticket page is opened,
-- so existing installations do not need to run this.
-- ==========================================

CREATE TABLE IF NOT EXISTS tickets
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_no VARCHAR(20) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(60) DEFAULT 'General',
    priority VARCHAR(20) NOT NULL DEFAULT 'medium',
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_by INT NOT NULL,
    assigned_to INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    due_at DATETIME DEFAULT NULL,
    first_response_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_tickets_status (status),
    INDEX idx_tickets_creator (created_by),
    INDEX idx_tickets_assignee (assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_replies
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    is_internal TINYINT NOT NULL DEFAULT 0,
    is_system TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_replies_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_presence
(
    user_id INT PRIMARY KEY,
    last_seen DATETIME NOT NULL,
    watching_ticket INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_settings
(
    id INT PRIMARY KEY,
    enabled TINYINT NOT NULL DEFAULT 0,
    driver VARCHAR(10) NOT NULL DEFAULT 'log',
    from_email VARCHAR(150) DEFAULT '',
    from_name VARCHAR(150) DEFAULT 'Company Portal',
    smtp_host VARCHAR(150) DEFAULT '',
    smtp_port INT DEFAULT 587,
    smtp_encryption VARCHAR(10) DEFAULT 'tls',
    smtp_username VARCHAR(150) DEFAULT '',
    smtp_password VARCHAR(255) DEFAULT '',
    notify_new_ticket TINYINT NOT NULL DEFAULT 1,
    notify_assigned TINYINT NOT NULL DEFAULT 1,
    notify_reply TINYINT NOT NULL DEFAULT 1,
    notify_status TINYINT NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO mail_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS email_logs
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(150),
    subject VARCHAR(255),
    event VARCHAR(40),
    status VARCHAR(15),
    error_message TEXT,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- IT KNOWLEDGE BASE (added in 1.2, IT team only)
-- Also created automatically on first use.
-- ==========================================

CREATE TABLE IF NOT EXISTS kb_articles
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(60) DEFAULT 'General',
    tags VARCHAR(255) DEFAULT '',
    problem TEXT,
    solution TEXT NOT NULL,
    source_ticket_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    views INT NOT NULL DEFAULT 0,
    helpful INT NOT NULL DEFAULT 0,
    not_helpful INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kb_votes
(
    article_id INT NOT NULL,
    user_id INT NOT NULL,
    vote TINYINT NOT NULL,
    PRIMARY KEY (article_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- SMS ALERTS FOR THE IT TEAM (added in 1.3)
-- Also created automatically on first use.
-- ==========================================

CREATE TABLE IF NOT EXISTS sms_settings
(
    id INT PRIMARY KEY,
    enabled TINYINT NOT NULL DEFAULT 0,
    provider VARCHAR(20) NOT NULL DEFAULT 'log',
    country_code VARCHAR(5) NOT NULL DEFAULT '254',
    twilio_sid VARCHAR(100) DEFAULT '',
    twilio_token VARCHAR(100) DEFAULT '',
    twilio_from VARCHAR(30) DEFAULT '',
    at_username VARCHAR(100) DEFAULT '',
    at_api_key VARCHAR(150) DEFAULT '',
    at_sender_id VARCHAR(20) DEFAULT '',
    new_ticket_level VARCHAR(10) NOT NULL DEFAULT 'high',
    notify_assigned TINYINT NOT NULL DEFAULT 1,
    notify_due TINYINT NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO sms_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS user_sms
(
    user_id INT PRIMARY KEY,
    enabled TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_logs
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT DEFAULT NULL,
    recipient VARCHAR(30),
    message VARCHAR(400),
    event VARCHAR(30),
    provider VARCHAR(20),
    status VARCHAR(15),
    error_message TEXT,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================
-- AUTO-ASSIGN + ASSET TRACKING (added in 1.4)
-- Also created automatically on first use.
-- ==========================================

CREATE TABLE IF NOT EXISTS autoassign_settings
(
    id INT PRIMARY KEY,
    enabled TINYINT NOT NULL DEFAULT 0,
    fallback_any TINYINT NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO autoassign_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS it_agents
(
    user_id INT PRIMARY KEY,
    auto_assign TINYINT NOT NULL DEFAULT 0,
    away TINYINT NOT NULL DEFAULT 0,
    max_open INT NOT NULL DEFAULT 0,
    specialties VARCHAR(400) NOT NULL DEFAULT '',
    last_assigned_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS assets
(
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(20) DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    asset_type VARCHAR(40) NOT NULL DEFAULT 'Other',
    brand VARCHAR(80) DEFAULT '',
    model VARCHAR(80) DEFAULT '',
    serial_number VARCHAR(100) DEFAULT '',
    purchase_date DATE DEFAULT NULL,
    warranty_end DATE DEFAULT NULL,
    status VARCHAR(15) NOT NULL DEFAULT 'in_use',
    assigned_to INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    location VARCHAR(120) DEFAULT '',
    notes TEXT,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_assets
(
    ticket_id INT PRIMARY KEY,
    asset_id INT NOT NULL,
    INDEX idx_ta_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
