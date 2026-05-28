-- WMS LSN Database Setup
-- Run this file to initialize the database

CREATE DATABASE IF NOT EXISTS wms_lsn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wms_lsn;

-- ================================================
-- USERS
-- ================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(25)  NOT NULL,
    userid      VARCHAR(10)  NOT NULL UNIQUE,
    role        ENUM('admin','supervisor','staff','softchecker') NOT NULL,
    password    VARCHAR(255) NOT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin: userid=admin001 / password=Admin@123
INSERT INTO users (username, userid, role, password) VALUES
('Administrator', 'admin001', 'admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE id=id;

-- ================================================
-- TRANSACTIONS
-- ================================================
CREATE TABLE transactions (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id       VARCHAR(25)  NOT NULL,
    movement_type        ENUM('inbound','outbound','softcase','moving') NOT NULL,
    batch                VARCHAR(25),
    quantity             INT,
    uom                  VARCHAR(10),
    quantity_kg          DECIMAL(10,2),
    source_location      VARCHAR(25),
    destination_location VARCHAR(25),
    user_id              INT,
    remarks              VARCHAR(225),
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ================================================
-- BIN LOCATIONS
-- ================================================
CREATE TABLE IF NOT EXISTS bin_locations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    batch          VARCHAR(25) NOT NULL,
    pallet_number  VARCHAR(3)  NOT NULL,
    quantity       INT         DEFAULT 0,
    quantity_kg    DECIMAL(10,2) DEFAULT 0,
    uom            VARCHAR(10),
    bin_location   VARCHAR(25) NOT NULL,
    location_type  VARCHAR(25),
    updated_at     DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bin (batch, pallet_number, bin_location)
) ENGINE=InnoDB;

-- ================================================
-- SOFTCASE
-- ================================================
CREATE TABLE IF NOT EXISTS softcase (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    batch         VARCHAR(25) NOT NULL,
    pallet_number VARCHAR(3)  NOT NULL,
    qty_checked   INT         DEFAULT 0,
    uom_checked   VARCHAR(10),
    qty_soft      INT         DEFAULT 0,
    uom_soft      VARCHAR(10),
    checked_at    DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_softcase (batch, pallet_number)
) ENGINE=InnoDB;

-- ================================================
-- CONFIG
-- ================================================
CREATE TABLE IF NOT EXISTS config (
    config_key   VARCHAR(50) PRIMARY KEY,
    config_value VARCHAR(255)
) ENGINE=InnoDB;

INSERT INTO config (config_key, config_value) VALUES
('warehouse_capacity', '1330')
ON DUPLICATE KEY UPDATE config_key=config_key;

-- ================================================
-- INDEXES
-- ================================================
ALTER TABLE transactions ADD INDEX idx_transaction_id (transaction_id);
ALTER TABLE transactions ADD INDEX idx_movement_type (movement_type);
ALTER TABLE transactions ADD INDEX idx_created_at (created_at);
ALTER TABLE transactions ADD INDEX idx_batch (batch);
ALTER TABLE bin_locations ADD INDEX idx_batch (batch);
ALTER TABLE bin_locations ADD INDEX idx_bin_location (bin_location);
ALTER TABLE softcase ADD INDEX idx_batch (batch);
