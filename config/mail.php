<?php
/**
 * Mail Configuration (SMTP via PHPMailer)
 * 
 * Using Gmail SMTP. Make sure to:
 * 1. Enable 2-Step Verification on your Google Account
 * 2. Generate an App Password at: https://myaccount.google.com/apppasswords
 * 3. Use the App Password below (NOT your regular Gmail password)
 */

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'estephaniedetorres15@gmail.com');
define('MAIL_PASSWORD', 'gradingmanagementapp');
define('MAIL_FROM_EMAIL', 'estephaniedetorres15@gmail.com');
define('MAIL_FROM_NAME', 'Grading Management System');
define('MAIL_ENCRYPTION', 'tls'); // tls or ssl
