<?php
require "connect.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/**
 * sendemail($name, $email)
 * - Composes with PHPMailer and sends via Brevo HTTP API (no SMTP).
 * - Returns true on accepted send, false otherwise.
 */
function sendemail($name, $email)
{
    // Brevo HTTP API env vars (set these on your host/Render)
    $brevoApiKey = getenv('BREVO_API_KEY');
    $fromEmail   = getenv('BREVO_FROM_EMAIL');
    $fromName    = getenv('BREVO_FROM_NAME');

    if (!$brevoApiKey || !$fromEmail) {
        error_log("sendemail (delete.php): missing Brevo env vars");
        return false;
    }

    // Compose message with PHPMailer (only to build HTML/text content)
    $mail = new PHPMailer(true);
    try {
        $mail->setFrom($fromEmail, $fromName ?: '');
        $mail->addAddress($email, $name);

        // Use a subject that includes the user's name (keeps intent of original)
        $mail->Subject = "User {$name} removed from platform";
        $mail->isHTML(true);

        $htmlBody = "<h2>You have been removed from platform</h2>";

        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
    } catch (Exception $e) {
        error_log("sendemail (delete.php) compose error: " . $e->getMessage());
        return false;
    }

    // Build Brevo payload
    $payload = [
        "sender" => ["email" => $fromEmail, "name" => $fromName ?: ''],
        "to" => [
            ["email" => $email, "name" => $name]
        ],
        "subject" => $mail->Subject,
        "htmlContent" => $mail->Body,
        "textContent" => $mail->AltBody
    ];

    // Send via Brevo HTTP API
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
        error_log("sendemail (delete.php) cURL error: " . $curlErr);
        return false;
    }

    if ($status === 201) {
        return true;
    } else {
        error_log("sendemail (delete.php) failed. status: $status response: " . ($response ?? ''));
        return false;
    }
}

if (isset($_POST['delete'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $sql = $con->prepare("delete from users where id = ?");
    $sql->bind_param("i", $id);
    $sql->execute();
    if ($sql->affected_rows > 0) {
        echo "<script>
               window.alert('User data deleted');
               window.location.href = 'userdata.php';
              </script>
       ";
        sendemail($name, $email);
    } else {
        echo "<script>
               window.alert('failed to delete data');
               window.location.href = 'userdata.php';
              </script>";
    }
}
