# leren.eem64.nl

PHP + MariaDB quiz platform for practicing school tests.

## Setup
1. Create database `leren` in MariaDB.
2. Run `database/schema.sql`.
3. Copy `config/config.example.php` to `config/config.php` and enter the database credentials.
4. Point the web server document root to `public/`.
5. Visit `public/setup.php` once to create the first admin account.
6. Delete or disable `public/setup.php` after setup.

## Features
- Subjects and topics
- Tests and multiple-choice questions
- Student quiz flow
- Automatic scoring
- Stored attempt history
- Admin login and dashboard
- Admin CRUD for tests and questions
