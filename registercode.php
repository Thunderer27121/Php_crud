<?php
session_start();
require "connect.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
function sendemail($name, $email, $verify_token)
{
    // env vars (set these on Render)
    $brevoApiKey   = getenv('brevo_apikey');      // xkeysib-...
    $fromEmail     = getenv('bbrevo_email');   // verified sender email
    $fromName      = getenv('brevo_name');    // sender name

    if (!$brevoApiKey || !$fromEmail) {
        return ['ok' => false, 'status' => 0, 'body' => 'Missing BREVO_API_KEY or BREVO_FROM_EMAIL'];
    }

    // Build verification URL (keep same domain you used previously)
    $verifyUrl = "https://php-crud-sflf.onrender.com/verify_email.php?token=" . urlencode($verify_token);

    // ----- Compose with PHPMailer (only for building body/altBody) -----
    $mail = new PHPMailer(true);
    try {
        $mail->setFrom($fromEmail, $fromName ?: '');
        $mail->addAddress($email, $name);
        $mail->Subject = 'email verification from platform';
        $mail->isHTML(true);

        // Keep your exact HTML body style you supplied earlier
        $htmlBody = "<h2>You have registered with platform</h2>
            <h5>Verify your email address to login with the below given link</h5>
            <br><br>
            <a href='$verifyUrl'>click me</a>";

        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        // If you were using attachments before, you can keep addAttachment calls here
        // but note: attachments require special handling when sending via API (base64 encoding).
        // For now this keeps it simple (no attachments).
    } catch (Exception $e) {
        return ['ok' => false, 'status' => 0, 'body' => 'PHPMailer compose error: ' . $e->getMessage()];
    }

    // ----- Build Brevo payload -----
    $payload = [
        "sender" => ["email" => $fromEmail, "name" => $fromName ?: ''],
        "to" => [
            ["email" => $email, "name" => $name]
        ],
        "subject" => $mail->Subject,
        "htmlContent" => $mail->Body,
        "textContent" => $mail->AltBody
    ];

    // ----- Send via Brevo HTTP API -----
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

    // Optional: log response for debugging (uncomment to enable)
    // file_put_contents(__DIR__ . '/email_debug.log', date('c') . " | status:$status | err:$curlErr | resp:$response\n", FILE_APPEND);

    if ($curlErr) {
        return ['ok' => false, 'status' => 0, 'body' => 'cURL error: ' . $curlErr];
    }

    return ['ok' => ($status === 201), 'status' => $status, 'body' => $response];
}
if (isset($_POST['submit'])) {
    $name =  $_POST['name'];
    $phone =  $_POST['phone'];
    $email =  $_POST['email'];
    $password =  $_POST['password'];
    $cpass = $_POST['cpass'];
    $verify_token = md5(rand());
    if (!empty($name) || !empty($phone) || !empty($email) || !empty($password) || !empty($cpass)) {
        if ($password != $cpass) {
            $_SESSION['status'] = "Password and Confirm Password should contain same value";
            echo "
         <script>
         window.location.href = 'register.php';
         </script>
         ";
        } else {
            $check = "SELECT `email` FROM `users` WHERE `email` = '{$email}' LIMIT 1";
            $data = mysqli_query($con, $check);
            if (mysqli_num_rows($data) > 0) {
                $_SESSION['status'] = "email already exists";
                echo "
        <script>
        window.location.href = 'register.php';
        </script>
        ";
            } else {
                $sql = "INSERT INTO users(name,phone,email,password,verify_token) VALUES ('{$name}','{$phone}','{$email}','{$password}','{$verify_token}')";
                $result = mysqli_query($con, $sql);
                if ($result) {
                    sendemail("$name", "$email", "$verify_token");
                    $_SESSION['status'] = "registration successful, now please verify your email";
                    echo "
            <script>
            window.location.href = 'register.php';
            </script>
            ";
                } else {
                    $_SESSION['status'] = "registration failed";
                    echo "
            <script>
            window.location.href = 'register.php';
            </script>
            ";
                }
            }
        }
    } else {
        $_SESSION['status'] = "All fields are mandatory";
        echo "
            <script>
            window.location.href = 'register.php';
            </script>
            ";
    }
}
