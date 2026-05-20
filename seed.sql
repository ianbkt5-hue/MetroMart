-- =============================================
-- METROMART — Seed Data
-- Run AFTER schema.sql
--
-- All demo passwords use the same hash for "Password@123"
-- Generate fresh hashes with:
--   php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT, ['cost'=>12]);"
--
-- Demo accounts:
--   admin@metromart.com     / Admin@123
--   employee@metromart.com  / Employee@123
--   rider@metromart.com     / Rider@123
--   customer@metromart.com  / Customer@123
-- =============================================

USE metromart;

-- All passwords below are bcrypt hashes of "Password@123"
-- You can change individual passwords after seeding via the admin dashboard.

-- ── Admin ─────────────────────────────────────
INSERT INTO users (email, password, role, status, name) VALUES
('admin@metromart.com',
 $2y$12$N8TMdimaOlFXtLPfAP7PRetq.DijEGe/8lg238bQ6IqIxgbm5wz1u,
 'admin', 'active', 'MetroMart Admin')
ON DUPLICATE KEY UPDATE id = id;

-- ── Demo Merchants ────────────────────────────
INSERT INTO merchants (name, address, contact, created_by)
SELECT 'SM Supermarket Cebu', 'SM City Cebu, North Reclamation Area, Cebu City', '+63 32 231 3868', id
FROM users WHERE email = 'admin@metromart.com' LIMIT 1
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO merchants (name, address, contact, created_by)
SELECT 'Landers Superstore',  'Ouano Ave, Mandaue City, Cebu', '+63 32 341 0000', id
FROM users WHERE email = 'admin@metromart.com' LIMIT 1
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO merchants (name, address, contact, created_by)
SELECT 'Robinsons Supermarket', 'Fuente Osmeña Blvd, Cebu City', '+63 32 418 3888', id
FROM users WHERE email = 'admin@metromart.com' LIMIT 1
ON DUPLICATE KEY UPDATE id = id;

-- ── Employee (assigned to merchant 1) ─────────
INSERT INTO users (email, password, role, status, name) VALUES
('employee@metromart.com',
 $2y$12$N8TMdimaOlFXtLPfAP7PRetq.DijEGe/8lg238bQ6IqIxgbm5wz1u,
 'employee', 'active', 'Juan Dela Cruz')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO employees (id, fname, lname, phone, position, merchant_id)
SELECT u.id, 'Juan', 'Dela Cruz', '+63 917 111 2222', 'Store Manager', m.id
FROM users u
JOIN merchants m ON m.name = 'SM Supermarket Cebu'
WHERE u.email = 'employee@metromart.com' LIMIT 1
ON DUPLICATE KEY UPDATE id = id;

-- ── Rider ─────────────────────────────────────
INSERT INTO users (email, password, role, status, name) VALUES
('rider@metromart.com',
 $2y$12$N8TMdimaOlFXtLPfAP7PRetq.DijEGe/8lg238bQ6IqIxgbm5wz1u,
 'rider', 'active', 'Pedro Santos')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO riders (id, fname, lname, phone, vehicle_type, rider_status)
SELECT id, 'Pedro', 'Santos', '+63 918 333 4444', 'motorcycle', 'offline'
FROM users WHERE email = 'rider@metromart.com' LIMIT 1
ON DUPLICATE KEY UPDATE id = id;

-- ── Customer ──────────────────────────────────
INSERT INTO users (email, password, role, status, name) VALUES
('customer@metromart.com',
 $2y$12$N8TMdimaOlFXtLPfAP7PRetq.DijEGe/8lg238bQ6IqIxgbm5wz1u,
 'customer', 'active', 'Maria Santos')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO customers (id, fname, lname, phone, address)
SELECT id, 'Maria', 'Santos', '+63 919 555 6666', 'Brgy. Lahug, Cebu City'
FROM users WHERE email = 'customer@metromart.com' LIMIT 1
ON DUPLICATE KEY UPDATE id = id;

-- ── Demo Products (SM Supermarket) ────────────
INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'fresh', 'Fresh Tomatoes (1kg)', 'Locally grown red tomatoes', 45.00, 100
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'fresh', 'White Onions (500g)', 'Premium white onions', 35.00, 80
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'dairy', 'Fresh Milk 1L', 'Full cream fresh milk', 89.00, 50
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'dairy', 'Eggs (12pcs)', 'Large white chicken eggs', 95.00, 60
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'meat', 'Chicken Breast (1kg)', 'Boneless skinless chicken', 199.00, 30
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'snacks', 'Chips Ahoy 135g', 'Chocolate chip cookies', 62.00, 120
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'beverages', 'Coca-Cola 1.5L', 'Regular 1.5 litre bottle', 65.00, 90
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'grocery', 'Jasmine Rice (5kg)', 'Premium jasmine rice', 280.00, 40
FROM merchants m WHERE m.name = 'SM Supermarket Cebu' LIMIT 1;

-- ── Demo Products (Landers) ───────────────────
INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'household', 'Joy Dishwashing 500ml', 'Lemon scent dishwashing liquid', 55.00, 70
FROM merchants m WHERE m.name = 'Landers Superstore' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'personal', 'Safeguard Bar Soap 135g', 'Anti-bacterial soap', 45.00, 80
FROM merchants m WHERE m.name = 'Landers Superstore' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'grocery', 'Lucky Me Pancit Canton 5s', 'Original flavour multipack', 65.00, 150
FROM merchants m WHERE m.name = 'Landers Superstore' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'beverages', 'Nestea Iced Tea 500ml', 'Lemon flavoured iced tea', 25.00, 200
FROM merchants m WHERE m.name = 'Landers Superstore' LIMIT 1;

-- ── Demo Products (Robinsons) ─────────────────
INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'bakery', 'Gardenia Loaf Bread', 'Classic white sliced bread', 68.00, 45
FROM merchants m WHERE m.name = 'Robinsons Supermarket' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'dairy', 'Eden Cheese 165g', 'Filled cheese block', 89.00, 55
FROM merchants m WHERE m.name = 'Robinsons Supermarket' LIMIT 1;

INSERT INTO products (merchant_id, category_id, name, description, price, qty)
SELECT m.id, 'fresh', 'Lakatan Bananas (1kg)', 'Ripe lakatan bananas', 65.00, 35
FROM merchants m WHERE m.name = 'Robinsons Supermarket' LIMIT 1;