<?php
/**
 * Database Layer for Custom Booking Widget
 * Supports both SQLite and MySQL via PDO
 */
require_once __DIR__ . '/config.php';

class DB {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            try {
                if (DB_DRIVER === 'sqlite') {
                    $dir = dirname(DB_FILE);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    self::$pdo = new PDO('sqlite:' . DB_FILE);
                    self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$pdo->exec('PRAGMA foreign_keys = ON;');
                } else {
                    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
                    self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                }
                self::initTables();
            } catch (PDOException $e) {
                die('Database Connection Error: ' . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    private static function initTables() {
        $db = self::$pdo;
        $isSqlite = (DB_DRIVER === 'sqlite');
        $pkAuto = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

        // 1. Services Table
        $db->exec("CREATE TABLE IF NOT EXISTS services (
            id $pkAuto,
            name VARCHAR(255) NOT NULL,
            duration_minutes INT DEFAULT 30,
            price DECIMAL(10,2) DEFAULT 0.00,
            color VARCHAR(30) DEFAULT '#3b82f6',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Staff Table
        $db->exec("CREATE TABLE IF NOT EXISTS staff (
            id $pkAuto,
            name VARCHAR(255) NOT NULL,
            b24_user_id INT DEFAULT 0,
            email VARCHAR(255) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            working_start VARCHAR(10) DEFAULT '09:00',
            working_end VARCHAR(10) DEFAULT '18:00',
            is_active INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 3. Bookings Table
        $db->exec("CREATE TABLE IF NOT EXISTS bookings (
            id $pkAuto,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            client_name VARCHAR(255) DEFAULT '',
            client_phone VARCHAR(50) DEFAULT '',
            client_email VARCHAR(255) DEFAULT '',
            service_id INT NOT NULL,
            staff_id INT NOT NULL,
            booking_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            status VARCHAR(50) DEFAULT 'Scheduled',
            calendar_target VARCHAR(50) DEFAULT 'responsible',
            b24_activity_id INT DEFAULT 0,
            b24_calendar_event_id INT DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        try {
            $db->exec("ALTER TABLE bookings ADD COLUMN entity_title VARCHAR(255) DEFAULT ''");
        } catch (Exception $e) {
            // Column already exists
        }

        try {
            $db->exec("ALTER TABLE bookings ADD COLUMN b24_spa_item_id INT DEFAULT 0");
        } catch (Exception $e) {
            // Column already exists
        }

        try {
            $db->exec("ALTER TABLE bookings ADD COLUMN ufCrm29_1787324188722 VARCHAR(255) DEFAULT ''");
        } catch (Exception $e) {}

        try {
            $db->exec("ALTER TABLE bookings ADD COLUMN ufCrm29_1787324656 VARCHAR(255) DEFAULT ''");
        } catch (Exception $e) {}

        try {
            $db->exec("ALTER TABLE bookings ADD COLUMN ufCrm29_1787324769682 INT DEFAULT 0");
        } catch (Exception $e) {}

        try {
            $db->exec("ALTER TABLE bookings ADD COLUMN created_by_name VARCHAR(255) DEFAULT ''");
        } catch (Exception $e) {}

        // Insert Default Services & Staff if empty
        $stmt = $db->query("SELECT COUNT(*) FROM services");
        if ($stmt->fetchColumn() == 0) {
            $db->exec("INSERT INTO services (name, duration_minutes, price, color) VALUES 
                ('Initial Consultation', 30, 50.00, '#3b82f6'),
                ('Product Demo & Strategy', 45, 100.00, '#10b981'),
                ('Technical Onboarding', 60, 150.00, '#8b5cf6'),
                ('Follow-up Review', 30, 0.00, '#f59e0b')");
        }

        $stmt = $db->query("SELECT COUNT(*) FROM staff");
        if ($stmt->fetchColumn() == 0) {
            $db->exec("INSERT INTO staff (name, b24_user_id, email, phone, working_start, working_end, is_active) VALUES 
                ('Default Consultant', 1, 'consultant@example.com', '+123456789', '09:00', '18:00', 1),
                ('Senior Specialist', 2, 'specialist@example.com', '+987654321', '10:00', '17:00', 1)");
        }
    }
}
