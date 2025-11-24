<?php
session_start();
require "connect.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/**
 * resend_email($name, $email, $verify_token)
 * - Composes the verification email using PHPMailer (keeps your HTML),
 * - Sends via Brevo HTTP API (no SMTP), and logs failures.
 * - Returns true on accepted send, false otherwise.
 */
function resend_email($name, $email, $verify_token){
    // Brevo HTTP API env vars (set these on your host/Render)
    $brevoApiKey = getenv('BREVO_API_KEY');
    $fromEmail   = getenv('BREVO_FROM_EMAIL');
    $fromName    = getenv('BREVO_FROM_NAME');

    if (!$brevoApiKey || !$fromEmail) {
        error_log("resend_email: missing Brevo env vars");
        return false;
    }

    // Build verification URL (keeps same domain you used previously)
    $verifyUrl = "https://php-crud-sflf.onrender.com/verify_email.php?token=" . urlencode($verify_token);

    // Compose email with PHPMailer (only to create HTML and plain text content)
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
        error_log("resend_email compose error: " . $e->getMessage());
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
        error_log("resend_email cURL error: " . $curlErr);
        return false;
    }

    if ($status === 201) {
        return true;
    } else {
        error_log("resend_email failed. status: $status response: " . ($response ?? ''));
        return false;
    }
}

if(isset($_POST['resend'])){
    if(!empty(trim($_POST['email']))){
        $email = mysqli_real_escape_string($con,$_POST['email']);
        $chek_query = "SELECT * FROM users WHERE email = '$email' LIMIT 1";
        $data = mysqli_query($con,$chek_query);
        if(mysqli_num_rows($data)>0){
          $row = mysqli_fetch_array($data);
          if($row['verify_status']==0){
            $name = $row['name'];
            $email = $row['email'];
            $verify_token = $row['verify_token'];
             resend_email($name,$email,$verify_token);
             $_SESSION['status'] = "verification link has been sent to your email address";
             echo "
             <script>
             window.location.href = 'login.php';
             </script>
             ";
             exit(0);
          }else{
            $_SESSION['status'] = "Email is already verified please login";
            echo "
            <script>
            window.location.href = 'login.php';
            </script>
            ";
          }
        }else{
            $_SESSION['status'] = "Email is not registered";
            echo "
            <script>
            window.location.href = 'register.php';
            </script>
            ";
            exit(0);
        }
    }else{
        $_SESSION['status'] = "Enter your email first";
        echo "
        <script>
        window.location.href = 'resend_email.php.php';
        </script>
        ";
        exit(0);
    }
}
?>
