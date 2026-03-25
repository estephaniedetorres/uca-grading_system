<?php
/**
 * Quick email test - run this to verify SMTP is working
 * Usage: php test_email.php
 */
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

echo "Testing SMTP connection to " . MAIL_HOST . ":" . MAIL_PORT . "...\n";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = (MAIL_ENCRYPTION === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER; // Show detailed output

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_FROM_EMAIL); // Send test to yourself

    $mail->isHTML(true);
    $mail->Subject = 'Grading System - Email Test';
    $mail->Body    = '<h2>Email Test Successful!</h2><p>Your SMTP configuration is working correctly.</p>';
    $mail->AltBody = 'Email Test Successful! Your SMTP configuration is working correctly.';

    $mail->send();
    echo "\n✓ Email sent successfully! Check your inbox at " . MAIL_FROM_EMAIL . "\n";
} catch (Exception $e) {
    echo "\n✗ Email could not be sent.\n";
    echo "Error: " . $mail->ErrorInfo . "\n";
}
