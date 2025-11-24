<?php
// registercode.php
// NOTE: This file uses JS redirects instead of header() as requested.

// Hide deprecation notices from vendor libs (prevents stray output warnings)
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

session_start();
require "connect.php"; // should set $con = new mysqli(...)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/**
 * sendemail($name, $email, $verify_token)
 * - Compose with PHPMailer and send via Brevo HTTP API
 * - Returns ['ok'=>bool, 'status'=>int, 'body'=>string]
 */
function sendemail($name, $email, $verify_token)
{
    // env var names (match what you have in Render)
    $brevoApiKey   = getenv('brevo_apikey');    // example: xkeysib-...
    $fromEmail     = getenv('brevo_email');    // verified sender email
    $fromName      = getenv('brevo_name');      // sender name

    if (!$brevoApiKey || !$fromEmail) {
        return ['ok' => false, 'status' => 0, 'body' => 'Missing BREVO_API_KEY or BREVO_FROM_EMAIL'];
    }

    $verifyUrl = "https://php-crud-sflf.onrender.com/verify_email.php?token=" . urlencode($verify_token);

    // Compose email with PHPMailer (only to build HTML and plain text body)
    $mail = new PHPMailer(true);
    try {
        $mail->setFrom($fromEmail, $fromName ?: '');
        $mail->addAddress($email, $name);
        $mail->Subject = 'Email verification from platform';
        $mail->isHTML(true);

        $htmlBody = "<h2>You have registered with platform</h2>
            <h5>Verify your email address to login with the below given link</h5>
            <br><br>
            <a href='$verifyUrl'>click me</a>";

        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
    } catch (Exception $e) {
        return ['ok' => false, 'status' => 0, 'body' => 'PHPMailer compose error: ' . $e->getMessage()];
    }

    // Build payload for Brevo API
    $payload = [
        "sender" => ["email" => $fromEmail, "name" => $fromName ?: ''],
        "to" => [
            ["email" => $email, "name" => $name]
        ],
        "subject" => $mail->Subject,
        "htmlContent" => $mail->Body,
        "textContent" => $mail->AltBody
    ];

    $ch = curl_init("https://api.brevo.com/v3/smtp/email");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "api-key: {$brevoApiKey}",
        "Content-Type: application/json",
        "accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'status' => 0, 'body' => 'cURL error: ' . $curlErr];
    }

    return ['ok' => ($status === 201), 'status' => $status, 'body' => $response];
}

/* -------------------------
   Registration handler
   ------------------------- */
if (isset($_POST['submit'])) {
    // sanitize + trim inputs
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $cpass = $_POST['cpass'] ?? '';

    // 1) Required fields check (ALL required)
    if ($name === '' || $phone === '' || $email === '' || $password === '' || $cpass === '') {
        $_SESSION['status'] = "All fields are mandatory";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    }

    // 2) Password match
    if ($password !== $cpass) {
        $_SESSION['status'] = "Password and Confirm Password should contain same value";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    }

    // 3) Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['status'] = "Invalid email address";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    }

    // 4) Check existing email using prepared statement
    $checkSql = "SELECT id FROM users WHERE email = ? LIMIT 1";
    if ($stmt = $con->prepare($checkSql)) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            $_SESSION['status'] = "Email already exists";
            echo "<script>window.location.href = 'register.php';</script>";
            exit;
        }
        $stmt->close();
    } else {
        // DB prepare failed
        error_log("DB prepare failed (check): " . $con->error);
        $_SESSION['status'] = "Server error (try again later)";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    }

    // 5) All good — insert user (using verify_status column, no email_sent)
    $verify_token = bin2hex(random_bytes(16)); // secure token

    // Insert includes verify_status (0)
    $insertSql = "INSERT INTO users (name, phone, email, password, verify_token, verify_status, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())";
    if ($ins = $con->prepare($insertSql)) {
        $ins->bind_param('sssss', $name, $phone, $email, $password, $verify_token);
        $executed = $ins->execute();
        if (!$executed) {
            error_log("DB insert failed: " . $ins->error);
            $ins->close();
            $_SESSION['status'] = "Registration failed (server error)";
            echo "<script>window.location.href = 'register.php';</script>";
            exit;
        }
        $userId = $ins->insert_id;
        $ins->close();
    } else {
        error_log("DB prepare failed (insert): " . $con->error);
        $_SESSION['status'] = "Server error (try again later)";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    }

    // 6) Attempt to send verification email
    $res = sendemail($name, $email, $verify_token);

    if (is_array($res) && $res['ok']) {
        // Email accepted by provider; user remains with verify_status = 0 until they click verify link.
        $_SESSION['status'] = "Registration successful. Verification email sent — check your inbox (and spam).";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    } else {
        // email failed: log details, keep user in DB so you can resend later
        $logEntry = date('c') . " | user_id:$userId | mail_status:" . ($res['status'] ?? '0') . " | body: " . substr($res['body'] ?? '', 0, 1000) . PHP_EOL;
        @file_put_contents(__DIR__ . '/email_send_fail.log', $logEntry, FILE_APPEND | LOCK_EX);
        error_log("Email sending failed for user_id $userId: " . json_encode($res));

        // Inform user but keep account; user can request "resend verification" from UI
        $_SESSION['status'] = "Registration saved, but verification email failed to send. Click 'Resend verification' or contact support.";
        echo "<script>window.location.href = 'register.php';</script>";
        exit;
    }
} // end if submit

// If script reaches here without POST submit, redirect to register page using JS
echo "<script>window.location.href = 'register.php';</script>";
exit;
