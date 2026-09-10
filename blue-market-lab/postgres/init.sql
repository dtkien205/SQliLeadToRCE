CREATE ROLE app_user LOGIN PASSWORD 'app_user_password';
CREATE ROLE report_user LOGIN PASSWORD 'report_user_password';
CREATE ROLE extension_user LOGIN SUPERUSER PASSWORD 'extension_user_password';

GRANT pg_execute_server_program TO report_user;
GRANT CONNECT ON DATABASE bluemarket TO app_user, report_user, extension_user;
GRANT USAGE ON SCHEMA public TO app_user, report_user, extension_user;
GRANT CREATE ON SCHEMA public TO report_user, extension_user;

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    description TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NOT NULL,
    category TEXT NOT NULL,
    price NUMERIC(10, 2) NOT NULL,
    image_url TEXT NOT NULL,
    owner_id INT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE posts (
    id SERIAL PRIMARY KEY,
    author_id INT NOT NULL REFERENCES users(id),
    author_email TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    product_id INT NOT NULL REFERENCES products(id),
    buyer_id INT NOT NULL REFERENCES users(id),
    status TEXT NOT NULL,
    total NUMERIC(10, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE report_templates (
    id SERIAL PRIMARY KEY,
    type TEXT NOT NULL,
    label TEXT NOT NULL,
    description TEXT NOT NULL,
    query_name TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (username, email, password_hash, role, description) VALUES
    ('admin', 'admin@bluemarket.local', 'admin123', 'admin', 'Operations administrator for catalog, media, templates, and system reports.'),
    ('analyst', 'analyst@bluemarket.local', 'analyst123', 'member', 'Internal analyst tracking search performance and marketplace data.'),
    ('lan_store', 'lan.store@bluemarket.local', 'seller123', 'seller', 'Seller for office equipment, work accessories, and operations tools.'),
    ('minh_audio', 'minh.audio@bluemarket.local', 'seller123', 'seller', 'Seller for audio, livestream, and small studio equipment.'),
    ('greenlab', 'hello@greenlab.local', 'seller123', 'seller', 'Seller for sustainable office products and modern workspace supplies.');

INSERT INTO products (name, description, category, price, image_url, owner_id) VALUES
    ('Docking Station Pro', 'Multi-port USB-C dock for content operations teams.', 'Workspace', 149.00, 'https://images.unsplash.com/photo-1625842268584-8f3296236761?auto=format&fit=crop&w=900&q=80', 3),
    ('Studio Microphone Kit', 'Microphone and arm stand bundle for internal webinars.', 'Audio', 219.00, 'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?auto=format&fit=crop&w=900&q=80', 4),
    ('Inventory Scanner', 'Handheld barcode scanner for small warehouse workflows.', 'Operations', 89.00, 'https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=900&q=80', 3),
    ('Recycled Notebook Pack', 'Recycled paper notebooks for field sales teams.', 'Office', 24.00, 'https://images.unsplash.com/photo-1517842645767-c639042777db?auto=format&fit=crop&w=900&q=80', 5),
    ('Content Light Panel', 'Compact LED panel for product photography.', 'Studio', 132.00, 'https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=900&q=80', 4),
    ('Desk Plant Set', 'Low-maintenance desk plants for office spaces.', 'Lifestyle', 38.00, 'https://images.unsplash.com/photo-1485955900006-10f4d324d411?auto=format&fit=crop&w=900&q=80', 5);

INSERT INTO posts (author_id, author_email, title, body, created_at) VALUES
    (3, 'lan.store@bluemarket.local', 'New scanner batch', 'Inventory scanners are ready for the September restock.', CURRENT_TIMESTAMP - INTERVAL '5 hours'),
    (3, 'lan.store@bluemarket.local', 'Dock compatibility note', 'The Pro dock was tested with Linux, macOS and Windows workstations.', CURRENT_TIMESTAMP - INTERVAL '1 day'),
    (4, 'minh.audio@bluemarket.local', 'Studio bundle update', 'The microphone kit now ships with a shock mount and spare cable.', CURRENT_TIMESTAMP - INTERVAL '3 hours'),
    (5, 'hello@greenlab.local', 'Sustainable office pack', 'GreenLab added recycled notebooks and low-water desk plants.', CURRENT_TIMESTAMP - INTERVAL '2 days'),
    (1, 'admin@bluemarket.local', 'System reporting window', 'Report jobs run every morning after catalog sync.', CURRENT_TIMESTAMP - INTERVAL '4 hours');

INSERT INTO orders (product_id, buyer_id, status, total) VALUES
    (1, 2, 'paid', 149.00),
    (2, 2, 'processing', 219.00),
    (4, 1, 'paid', 24.00),
    (6, 2, 'shipped', 38.00);

INSERT INTO report_templates (type, label, description, query_name) VALUES
    ('health', 'Worker Health', 'Worker status, hostname, and background task state.', 'report_worker_health'),
    ('health', 'Database Health', 'Connection and internal PostgreSQL table metrics.', 'report_database_health'),
    ('inventory', 'Catalog Inventory', 'Product totals grouped by seller and category.', 'report_inventory_summary'),
    ('sellers', 'Seller Activity', 'Seller activity powered by the profile/report backend.', 'report_seller_activity');

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO report_user;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO extension_user;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO app_user, report_user, extension_user;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_user, report_user, extension_user;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO app_user, report_user, extension_user;
