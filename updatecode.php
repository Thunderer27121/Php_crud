<?php
require 'connect.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/**
 * sendemail: compose with PHPMailer, send via Brevo HTTP API (no SMTP).
 * Keeps signature same so your call site does not change.
 */
function sendemail($name, $email, $verify_token)
{
    // Use the Brevo HTTP API key and sender details from environment.
    // Make sure these env vars are set on your server/Render:
    // BREVO_API_KEY, BREVO_FROM_EMAIL, BREVO_FROM_NAME
    $brevoApiKey = getenv('BREVO_API_KEY');
    $fromEmail   = getenv('BREVO_FROM_EMAIL');
    $fromName    = getenv('BREVO_FROM_NAME');

    if (!$brevoApiKey || !$fromEmail) {
        // don't break your existing flow — log and return false-ish
        error_log("sendemail: missing Brevo env vars");
        return false;
    }

    // Build verification URL (keeps same domain you used previously)
    $verifyUrl = "https://php-crud-sflf.onrender.com/verify_email.php?token=" . urlencode($verify_token);

    // Compose message with PHPMailer (only to build HTML/text bodies)
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
        error_log("sendemail compose error: " . $e->getMessage());
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
        error_log("sendemail cURL error: " . $curlErr);
        return false;
    }

    if ($status === 201) {
        // accepted
        return true;
    } else {
        // log the response for debugging
        error_log("sendemail failed. status: $status response: " . ($response ?? ''));
        return false;
    }
}

if (isset($_POST['updatenow'])) {
    $name =  $_POST['name'];
    $phone =  $_POST['phone']; 
    $email =  $_POST['email'];
    $password =  $_POST['password'];
    $verify_token = md5(rand());

        $id = $_POST['id'];
        $sql = "UPDATE `users` SET `name`='{$name}',`phone`='{$phone}',`email`='{$email}',`password`='{$password}',`verify_token`='{$verify_token}',`verify_status`= 0 WHERE `id` = $id";
        $result = mysqli_query($con, $sql);
        if ($result) {
            sendemail("$name", "$email", "$verify_token");
            $_SESSION['status'] = "Updation successful, Now go to your mails and verify your email";
            echo "
            <script>
            window.location.href = 'login.php';
            </script>
            ";
        } else {
            $_SESSION['status'] = "Updation failed";
            echo "
            <script>
            window.location.href = 'update.php';
            </script>
            ";
        }
    }
?>
