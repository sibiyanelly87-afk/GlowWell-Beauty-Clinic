
-- =====================================================
-- GLOWWELL BEAUTY CLINIC MANAGEMENT SYSTEM
-- Database: glowwell_db
-- Project: Systems Development 3 - HSYD300-1
-- =====================================================

CREATE DATABASE IF NOT EXISTS glowwell_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE glowwell_db;

-- =====================================================
-- TABLE 1: USERS
-- Stores customer, administrator and staff accounts.
-- =====================================================

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'admin', 'staff')
        NOT NULL DEFAULT 'customer',
    account_status ENUM('active', 'inactive')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================
-- TABLE 2: STAFF
-- Stores employee information.
-- Each staff member has a user account.
-- =====================================================

CREATE TABLE staff (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    job_title VARCHAR(100) NOT NULL,
    employment_status ENUM('active', 'inactive')
        NOT NULL DEFAULT 'active',
    date_hired DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_staff_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =====================================================
-- TABLE 3: SERVICES
-- Stores beauty and wellness services.
-- =====================================================

CREATE TABLE services (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    category VARCHAR(60) NOT NULL,
    duration_minutes INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    service_status ENUM('active', 'inactive')
        NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_service_duration
        CHECK (duration_minutes > 0),

    CONSTRAINT chk_service_price
        CHECK (price >= 0)
) ENGINE=InnoDB;

-- =====================================================
-- TABLE 4: STAFF_SERVICES
-- Connects staff members to the services they provide.
-- Resolves the many-to-many relationship.
-- =====================================================

CREATE TABLE staff_services (
    staff_id INT NOT NULL,
    service_id INT NOT NULL,
    PRIMARY KEY (staff_id, service_id),

    CONSTRAINT fk_staff_services_staff
        FOREIGN KEY (staff_id)
        REFERENCES staff(staff_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT fk_staff_services_service
        FOREIGN KEY (service_id)
        REFERENCES services(service_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================
-- TABLE 5: STAFF_AVAILABILITY
-- Stores the normal weekly working schedule.
-- weekday: 1 = Monday, 7 = Sunday.
-- =====================================================

CREATE TABLE staff_availability (
    availability_id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    weekday TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available BOOLEAN NOT NULL DEFAULT TRUE,

    CONSTRAINT fk_availability_staff
        FOREIGN KEY (staff_id)
        REFERENCES staff(staff_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT chk_weekday
        CHECK (weekday BETWEEN 1 AND 7),

    CONSTRAINT chk_availability_hours
        CHECK (end_time > start_time),

    CONSTRAINT uq_staff_weekday_start
        UNIQUE (staff_id, weekday, start_time)
) ENGINE=InnoDB;

-- =====================================================
-- TABLE 6: APPOINTMENTS
-- Stores customer bookings and appointment details.
-- =====================================================

CREATE TABLE appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    staff_id INT NOT NULL,
    service_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    booking_status ENUM(
        'pending',
        'confirmed',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_appointment_customer
        FOREIGN KEY (customer_id)
        REFERENCES users(user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointment_staff
        FOREIGN KEY (staff_id)
        REFERENCES staff(staff_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_appointment_service
        FOREIGN KEY (service_id)
        REFERENCES services(service_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_appointment_times
        CHECK (end_time > start_time),

    INDEX idx_appointment_date (appointment_date),
    INDEX idx_appointment_staff_date (staff_id, appointment_date),
    INDEX idx_appointment_customer (customer_id)
) ENGINE=InnoDB;

-- =====================================================
-- TABLE 7: PAYMENTS
-- Stores payment records linked to appointments.
-- One payment record per appointment.
-- =====================================================

CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM(
        'cash',
        'card',
        'eft'
    ) NOT NULL,
    payment_status ENUM(
        'pending',
        'paid',
        'refunded'
    ) NOT NULL DEFAULT 'pending',
    payment_date DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_payment_appointment
        FOREIGN KEY (appointment_id)
        REFERENCES appointments(appointment_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT chk_payment_amount
        CHECK (amount >= 0)
) ENGINE=InnoDB;

-- =====================================================
-- SAMPLE DATA: BEAUTY AND WELLNESS SERVICES
-- =====================================================

INSERT INTO services
(service_name, description, category, duration_minutes, price)
VALUES
('Classic Facial', 'A refreshing facial treatment.', 'Facials', 60, 450.00),
('Deep Cleansing Facial', 'A deep cleansing and skin treatment.', 'Facials', 75, 550.00),
('Relaxation Massage', 'A relaxing full-body massage.', 'Massages', 60, 600.00),
('Hot Stone Massage', 'A therapeutic hot stone massage.', 'Massages', 90, 750.00),
('Hair Treatment', 'A nourishing hair treatment.', 'Hair Treatments', 60, 400.00),
('Hair Styling', 'Professional hair styling service.', 'Hair Treatments', 45, 350.00),
('Aromatherapy', 'A calming aromatherapy wellness session.', 'Wellness', 60, 500.00),
('Speciality Treatment', 'A personalised speciality beauty treatment.', 'Speciality', 90, 800.00);

-- =====================================================
-- END OF GLOWWELL DATABASE SCHEMA
-- =====================================================
