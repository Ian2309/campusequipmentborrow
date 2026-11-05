-- CampusMate / RTU Equipment Hub schema. Run on a fresh database (drop the old prototype DB first).
CREATE DATABASE rtu_equipment_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rtu_equipment_hub;

CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(120) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  first_name VARCHAR(50) NOT NULL,
  last_name VARCHAR(50) NOT NULL,
  course VARCHAR(80) NOT NULL,
  year_level VARCHAR(20) NOT NULL,
  status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(40) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE equipment (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(40) NOT NULL,
  total_units INT NOT NULL DEFAULT 0,
  available_units INT NOT NULL DEFAULT 0,
  description VARCHAR(500) NOT NULL DEFAULT '',
  image_url VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE equipment_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  equipment_id INT NOT NULL,
  purpose VARCHAR(300) NOT NULL,
  borrow_date DATE NOT NULL,
  return_date DATE NOT NULL,
  course_code VARCHAR(20) NOT NULL,
  status ENUM('Pending Approval','Approved','Rejected','Returned','Returned Damaged') NOT NULL DEFAULT 'Pending Approval',
  admin_note VARCHAR(300) NOT NULL DEFAULT '',
  waiver_signed TINYINT(1) NOT NULL DEFAULT 0,
  waiver_signature VARCHAR(120) NOT NULL,
  waiver_version VARCHAR(20) NOT NULL,
  waiver_signed_at DATETIME NOT NULL,
  processed_by INT NULL,
  processed_at DATETIME NULL,
  returned_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id),
  FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  INDEX (status), INDEX (student_id)
) ENGINE=InnoDB;

CREATE TABLE damage_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  equipment_id INT NOT NULL,
  student_id INT NOT NULL,
  severity ENUM('Minor','Moderate','Severe') NOT NULL DEFAULT 'Minor',
  description VARCHAR(500) NOT NULL,
  estimated_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
  unusable TINYINT(1) NOT NULL DEFAULT 0,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL,
  recorded_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES equipment_requests(id),
  FOREIGN KEY (equipment_id) REFERENCES equipment(id),
  FOREIGN KEY (student_id) REFERENCES students(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient_type ENUM('admin','student') NOT NULL,
  recipient_id INT NULL,            -- students.id for students; NULL = all PEMU admins
  kind VARCHAR(30) NOT NULL,
  message VARCHAR(600) NOT NULL,
  request_id INT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (recipient_type, recipient_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(160) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (identifier, attempted_at)
) ENGINE=InnoDB;
