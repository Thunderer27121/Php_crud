<?php
session_start();
require "connect.php"; // should set $con (mysqli connection)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/**
 * sendemail($name, $email, $verify_token)
 * - keeps your PHPMailer->Brevo API composer function
 * - returns ['ok'=>bool,'status'=>int,'body'=>string]
 */
function sendemail($name, $email, $verify_token)
{
    $brevoApiKey   = getenv('brevo_apikey');      // check your env var names
    $fromEmail     = getenv('bbrevo_email');
    $fromName      = getenv('brevo_name');

    if (!$brevoApiKey || !$fromEmail) {
        return ['ok' => false, 'status' => 0, 'body' => 'Missing BREVO_API_KEY or BREVO_FROM_EMAIL'];
    }

    $verifyUrl = "https://php-crud-sflf.onrender.com/verify_email.php?token=" . urlencode($verify_token);

    $mail = new PHPMailer(true);
    try {
        $mail->setFrom($fromEmail, $fromName ?: '');
        $mail->addAddress($email, $name);
        $mail->Subject = 'email verification from platform';
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
        header("Location: register.php");
        exit;
    }

    // 2) Password match
    if ($password !== $cpass) {
        $_SESSION['status'] = "Password and Confirm Password should contain same value";
        header("Location: register.php");
        exit;
    }

    // 3) Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['status'] = "Invalid email address";
        header("Location: register.php");
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
            header("Location: register.php");
            exit;
        }
        $stmt->close();
    } else {
        // DB prepare failed
        error_log("DB prepare failed (check): " . $con->error);
        $_SESSION['status'] = "Server error (try again later)";
        header("Location: register.php");
        exit;
    }

    // 5) All good — insert user
    $verify_token = bin2hex(random_bytes(16)); // secure token
    $hashed_pass = password_hash($password, PASSWORD_DEFAULT);

    $insertSql = "INSERT INTO users (name, phone, email, password, verify_token, is_verified, email_sent, created_at) VALUES (?, ?, ?, ?, ?, 0, 0, NOW())";
    if ($ins = $con->prepare($insertSql)) {
        $ins->bind_param('sssss', $name, $phone, $email, $hashed_pass, $verify_token);
        $executed = $ins->execute();
        if (!$executed) {
            // insert failed
            error_log("DB insert failed: " . $ins->error);
            $ins->close();
            $_SESSION['status'] = "Registration failed (server error)";
            header("Location: register.php");
            exit;
        }
        $userId = $ins->insert_id;
        $ins->close();
    } else {
        error_log("DB prepare failed (insert): " . $con->error);
        $_SESSION['status'] = "Server error (try again later)";
        header("Location: register.php");
        exit;
    }

    // 6) Attempt to send verification email
    $res = sendemail($name, $email, $verify_token);

    if (is_array($res) && $res['ok']) {
        // mark email_sent = 1
        $updSql = "UPDATE users SET email_sent = 1 WHERE id = ?";
        if ($upd = $con->prepare($updSql)) {
            $upd->bind_param('i', $userId);
            $upd->execute();
            $upd->close();
        }
        $_SESSION['status'] = "Registration successful. Verification email sent — check your inbox (and spam).";
        header("Location: register.php");
        exit;
    } else {
        // email failed: log details, keep user in DB so you can resend
        $logEntry = date('c') . " | user_id:$userId | mail_status:" . ($res['status'] ?? '0') . " | body: " . substr($res['body'] ?? '', 0, 1000) . PHP_EOL;
        @file_put_contents(__DIR__ . '/email_send_fail.log', $logEntry, FILE_APPEND | LOCK_EX);
        error_log("Email sending failed for user_id $userId: " . json_encode($res));

        // Inform user but don't delete the account; allow resend from UI
        $_SESSION['status'] = "Registration saved, but verification email failed to send. Click 'Resend verification' or contact support.";
        header("Location: register.php");
        exit;
    }
} // end if submit

// If script reaches here without POST submit, just redirect to register
header("Location: register.php");
exit;
