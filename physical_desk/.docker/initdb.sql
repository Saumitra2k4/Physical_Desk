-- The MariaDB entrypoint provisions the application account from
-- MARIADB_USER and MARIADB_PASSWORD supplied by the local ignored .env file.
-- Keep this initialization hook free of fixed credentials.
SELECT 1;
