# Unciano Grading System

Simple PHP grading management system with role-based portals:

- Admin
- Registrar
- Principal
- Dean
- Teacher
- Student

## Requirements

- PHP 8.1+
- MySQL 8+
- Nginx or Apache
- Composer

## Quick Setup

1. Clone/upload project to server path (example):
   - /var/www/unciano-grading-system

2. Install PHP dependencies:
   - composer install --no-dev --optimize-autoloader

3. Create database (example):
   - unciano-grading-system

4. Import schema/data from project root:
   - mysql -u <db_user> -p --database="unciano-grading-system" < database.sql

5. Update database config:
   - Edit config/database.php
   - Set DB_HOST, DB_NAME, DB_USER, DB_PASS

6. Set web root to project root:
   - /var/www/unciano-grading-system
   - Ensure index.php is accessible

7. Set permissions:
   - Directories: 755
   - Files: 644

## Nginx Notes

Use a server block with:

- root /var/www/unciano-grading-system;
- index index.php index.html;
- PHP-FPM enabled

Then run:

- sudo nginx -t
- sudo systemctl reload nginx

## Common Issues

- 403 Forbidden:
  - Wrong root path
  - Missing/invalid index directive
  - Bad permissions

- DB Access Denied:
  - Do not use MySQL root for app login
  - Create a dedicated DB user and update config/database.php

## Default Entry

- index.php redirects to login.php
