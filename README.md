
# GlowWell Beauty Clinic Management System

A full-stack beauty clinic management system developed to simplify appointment booking, staff scheduling, service management and payment tracking.

## Project Overview

GlowWell Beauty Clinic is a web-based application designed to manage the daily operations of a beauty clinic. It provides separate functionality for customers, staff members and administrators.

## Features

### Customer
- Customer registration and secure login.
- Browse available beauty services.
- View service prices and durations.
- Book appointments based on staff availability.
- View appointment history.
- Cancel eligible appointments.

### Staff
- Staff dashboard.
- View assigned appointments.
- Confirm pending appointments.
- Update appointments to completed when eligible.

### Administrator
- Dashboard with business statistics.
- Manage customers and staff.
- Add, update and deactivate services.
- Manage appointment statuses.
- Record and track payments.
- View financial summaries and service reports.

## Technologies Used

- PHP 8.2
- MySQL / MariaDB
- HTML5
- CSS3
- JavaScript
- Bootstrap-free custom CSS
- XAMPP
- phpMyAdmin

## Database

The application uses a relational database called `glowwell_db`, consisting of seven tables:

1. users
2. staff
3. services
4. staff_services
5. staff_availability
6. appointments
7. payments

## Installation and Setup

1. Install XAMPP with Apache, PHP and MySQL/MariaDB.
2. Copy the project folder into the XAMPP `htdocs` directory.
3. Start Apache and MySQL from the XAMPP Control Panel.
4. Open phpMyAdmin at `http://localhost/phpmyadmin`.
5. Create a database named `glowwell_db`.
6. Import the supplied `database/schema.sql` file.
7. Configure your local database connection in `config/db.php` using your own local credentials.
8. Open `http://localhost/glowwell` in your browser.
9. Register a customer account to test the customer functionality.

## Security

The application includes:
- Password hashing.
- Prepared SQL statements.
- Server-side input validation.
- CSRF protection.
- Session-based authentication and role checks.
- Output escaping.

## Project Structure

```text
glowwell/
├── admin/
├── customer/
├── staff/
├── assets/
│   ├── css/
│   └── js/
├── config/
├── database/
├── includes/
├── index.php
├── login.php
├── register.php
└── logout.php
```

## Developer

**Developed by:** sibiyanelly87-afk

## Project Status

Completed academic full-stack development project.
