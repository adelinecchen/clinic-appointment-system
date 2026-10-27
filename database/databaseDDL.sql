USE clinic_db;

-- 1. PATIENT
CREATE TABLE Patient (
    Patient_ID INT AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(50) NOT NULL,
    PhoneNumber VARCHAR(15) NOT NULL
) ENGINE=InnoDB;


-- 2. SCHEDULE
-- A time slot is identified by its date + start time.
CREATE TABLE Schedule (
    ScheduleDate DATE NOT NULL,
    ScheduleTime TIME NOT NULL,
    Status VARCHAR(30) NOT NULL DEFAULT 'Open',

    PRIMARY KEY (ScheduleDate, ScheduleTime)
) ENGINE=InnoDB;


-- 3. ADMIN
CREATE TABLE Admin (
    Admin_ID INT AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(50) NOT NULL,
    PhoneNumber VARCHAR(15) NOT NULL UNIQUE,
    Email VARCHAR(80) NOT NULL UNIQUE,
    PasswordHash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;


-- 4. APPOINTMENT
CREATE TABLE Appointment (
    Appointment_ID INT AUTO_INCREMENT PRIMARY KEY,

    ScheduleDate DATE NOT NULL,
    ScheduleTime TIME NOT NULL,
    Patient_ID INT NOT NULL,

    AppointmentStatus VARCHAR(30) NOT NULL DEFAULT 'Scheduled',
    ConfirmationStatus VARCHAR(30) NOT NULL DEFAULT 'Pending',
    AppointmentType VARCHAR(20) NOT NULL,

    NotificationStatus VARCHAR(30) NULL,
    NotificationDate DATETIME NULL,

    CONSTRAINT fk_appointment_schedule
        FOREIGN KEY (ScheduleDate, ScheduleTime)
        REFERENCES Schedule (ScheduleDate, ScheduleTime),

    CONSTRAINT fk_appointment_patient
        FOREIGN KEY (Patient_ID)
        REFERENCES Patient (Patient_ID),

    CONSTRAINT chk_appointment_type
        CHECK (AppointmentType IN ('New patient', 'Follow-up')),

    INDEX idx_appointment_patient (Patient_ID),
    INDEX idx_appointment_schedule (ScheduleDate, ScheduleTime)
) ENGINE=InnoDB;


-- 5. WAITLIST
CREATE TABLE Waitlist (
    Waitlist_ID INT AUTO_INCREMENT PRIMARY KEY,
    Patient_ID INT NOT NULL,
    AppointmentType VARCHAR(20) NOT NULL,
    Status VARCHAR(50) NOT NULL DEFAULT 'Waiting',

    CONSTRAINT fk_waitlist_patient
        FOREIGN KEY (Patient_ID)
        REFERENCES Patient (Patient_ID),

    CONSTRAINT chk_waitlist_type
        CHECK (AppointmentType IN ('New patient', 'Follow-up')),

    INDEX idx_waitlist_patient (Patient_ID)
) ENGINE=InnoDB;
