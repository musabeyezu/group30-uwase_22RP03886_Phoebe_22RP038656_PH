CREATE TABLE farmers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    phone VARCHAR(20),
    id_number VARCHAR(20) UNIQUE,
    residence VARCHAR(100)
);

CREATE TABLE workshops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    venue VARCHAR(100),
    date DATE,
    time TIME,
    capacity INT DEFAULT 100,
    booked_seats INT DEFAULT 0
);

CREATE TABLE registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT,
    workshop_id INT,
    FOREIGN KEY (farmer_id) REFERENCES farmers(id),
    FOREIGN KEY (workshop_id) REFERENCES workshops(id),
    UNIQUE (farmer_id, workshop_id)
); 