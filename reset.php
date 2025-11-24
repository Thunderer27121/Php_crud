<?php
session_start();
require 'connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

/**
 * password_reset($name, $email, $token)
 * - Composes email with PHPMailer and sends via Brevo HTTP API (no SMTP).
 * - Returns true on accepted send, false otherwise (caller ignores return but we log failures).
 */
function password_reset($name, $email, $token)
{
    // Use Brevo HTTP API env vars (set these on your host/Render)
   $brevoApiKey   = getenv('brevo_apikey');    // example: xkeysib-...
    $fromEmail     = getenv('brevo_email');    // verified sender email
    $fromName      = getenv('brevo_name');   

    if (!$brevoApiKey || !$fromEmail) {
        error_log("password_reset: missing Brevo env vars");
        return false;
    }

    // Build reset URL (keeps same domain you used previously)
    $resetUrl = "https://php-crud-sflf.onrender.com/changepass.php?token=" . urlencode($token) . "&email=" . urlencode($email);

    // Compose email with PHPMailer (only to build HTML/text bodies)
    $mail = new PHPMailer(true);
    try {
        $mail->setFrom($fromEmail, $fromName ?: '');
        $mail->addAddress($email, $name);
        $mail->Subject = 'Password reset from platform';
        $mail->isHTML(true);

        $htmlBody = "<h2>Hey there!!</h2>
        <h5>below is the password reset link for your account</h5>
        <br><br>
        <a href='$resetUrl'>Click me</a>";

        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
    } catch (Exception $e) {
        error_log("password_reset compose error: " . $e->getMessage());
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
        error_log("password_reset cURL error: " . $curlErr);
        return false;
    }

    if ($status === 201) {
        // accepted
        return true;
    } else {
        error_log("password_reset failed. status: $status response: " . ($response ?? ''));
        return false;
    }
}

if (isset($_POST['resetpass'])) {
    $email = mysqli_real_escape_string($con, $_POST['email']);
    $token = md5(rand());
    $check_email = "SELECT email FROM users WHERE email = '$email' LIMIT 1";
    $data = mysqli_query($con, $check_email);
    if (mysqli_num_rows($data) > 0) {
        $row = mysqli_fetch_assoc($data);
        $name = $_POST['name'];
        $email = $_POST['email'];
        $update = "UPDATE users SET verify_token = '$token' where email = '$email' LIMIT 1";
        $run = mysqli_query($con, $update);
        if ($run) {
            password_reset($name, $email, $token);
            $_SESSION['status'] = "we have sent an email to your id to reset password";
            echo "
          <script>
      window.location.href = 'password_reset.php';
  </script>
          ";
            exit(0);
        } else {
            $_SESSION['status'] = "Something went wrong";
            echo "
        <script>
    window.location.href = 'password_reset.php';
</script>
        ";
            exit(0);
        }
    } else {
        $_SESSION['status'] = "No email found";
        echo "
        <script>
    window.location.href = 'password_reset.php';
</script>
        ";
        exit(0);
    }
}
?>



<?php
if (isset($_POST['reset'])) {
    $email = mysqli_real_escape_string($con, $_POST['email']);
    $pass = mysqli_real_escape_string($con, $_POST['pass']);
    $cpass = mysqli_real_escape_string($con, $_POST['cpass']);
    $token = mysqli_real_escape_string($con, $_POST['token']);
    if (!empty($token)) {
        if (!empty($email) && !empty($pass) && !empty($cpass)) {
            $check_token = "SELECT verify_token FROM users WHERE verify_token = '$token' LIMIT 1";
            $newdata = mysqli_query($con, $check_token);
            if (mysqli_num_rows($newdata) > 0) {
                if ($pass == $cpass) {
                    $updatepass = "UPDATE users SET password = '$pass' WHERE verify_token = '$token' LIMIT 1";
                    $newpassdata = mysqli_query($con, $updatepass);
                    if ($newpassdata) {
                        $_SESSION['status'] = "Password updated successfully";
                        echo "
                    <script>
                window.location.href = 'login.php';
            </script>
                    ";
                        exit(0);
                    } else {
                        $_SESSION['status'] = "Unknown error occured";
                        echo "
                    <script>
                window.location.href = 'changepass.php?token=$token&email=$email';
            </script>
                    ";
                        exit(0);
                    }
                } else {
                    $_SESSION['status'] = "Password and confirm password does not match";
                    echo "
            <script>
        window.location.href = 'changepass.php?token=$token&email=$email';
    </script>
            ";
                    exit(0);
                }
            } else {
                $_SESSION['status'] = "Invalid token";
                echo "
            <script>
        window.location.href = 'changepass.php?token=$token&email=$email';
    </script>
            ";
                exit(0);
            }
        } else {
            $_SESSION['status'] = "All fields are mandatory";
            echo "
        <script>
    window.location.href = 'changepass.php?token=$token&email=$email';
</script>
        ";
            exit(0);
        }
    } else {
        $_SESSION['status'] = "No token found";
        echo "
        <script>
    window.location.href = 'changepass.php';
</script>
        ";
        exit(0);
    }
}
?>
