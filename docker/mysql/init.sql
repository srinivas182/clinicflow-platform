-- Local development only: platform and hub databases, plus rights for the
-- application user to create one database per provider (cf_provider_*).
CREATE DATABASE IF NOT EXISTS clinicflow_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS clinicflow_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'clinicflow'@'%' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON *.* TO 'clinicflow'@'%';
FLUSH PRIVILEGES;
