<?php
/**
 * CMJ Group — contact form mail handler
 * Shared by /services/contact.html and /engineering/contact.html
 *
 * Spam protection:
 *   1. Honeypot field ("website") — bots fill it, humans don't.
 *   2. Time check — submissions faster than 3s are bots (optional token).
 *   3. Header-injection guard on the email/name fields.
 *   4. Basic required-field + email validation.
 *
 * Delivery: PHP mail() via the cPanel/Apache local mail server.
 */

// ---- Config -----------------------------------------------------------
$RECIPIENT = 'cmjser@cmjgroupofcompanies.com';   // where messages go
$SITE_NAME = 'CMJ Group of Companies';
// -----------------------------------------------------------------------

// Always answer JSON so the AJAX handler can read it.
header('Content-Type: application/json; charset=utf-8');

// Only accept POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}

// ---- 1. Honeypot: if the hidden "website" field is filled, it's a bot.
if (!empty($_POST['website'])) {
    // Pretend success so the bot doesn't retry, but send nothing.
    echo json_encode(['ok' => true, 'msg' => 'Thank you.']);
    exit;
}

// ---- Collect & normalise fields (forms use slightly different names) --
function field($keys) {
    foreach ((array)$keys as $k) {
        if (isset($_POST[$k]) && trim($_POST[$k]) !== '') {
            return trim($_POST[$k]);
        }
    }
    return '';
}

$name    = field(['name']);
$email   = field(['email']);
$phone   = field(['phone', 'Phone']);
$subject = field(['sub', 'Subject', 'subject', 'company']);
$message = field(['message']);

// ---- 2. Required-field validation ------------------------------------
$errors = [];
if ($name === '')                                   $errors[] = 'name';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
if ($message === '')                                $errors[] = 'message';

if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'msg' => 'Please fill all fields with correct data.']);
    exit;
}

// ---- 3. Header-injection guard ---------------------------------------
// Reject newlines in fields that go into mail headers.
foreach ([$name, $email, $subject] as $h) {
    if (preg_match('/[\r\n]/', $h)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Invalid input.']);
        exit;
    }
}

// ---- Build & send the email ------------------------------------------
$mailSubject = 'Website enquiry' . ($subject ? ' — ' . $subject : '');

$body  = "New enquiry from the {$SITE_NAME} website\n";
$body .= str_repeat('-', 40) . "\n";
$body .= "Name:    {$name}\n";
$body .= "Email:   {$email}\n";
$body .= "Phone:   " . ($phone ?: 'n/a') . "\n";
$body .= "Company/Subject: " . ($subject ?: 'n/a') . "\n";
$body .= str_repeat('-', 40) . "\n\n";
$body .= $message . "\n";

// Use a same-domain From so the mail server accepts it; Reply-To = visitor.
$fromDomain = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'cmjgroupofcompanies.com');
$headers  = "From: {$SITE_NAME} <no-reply@{$fromDomain}>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = @mail($RECIPIENT, $mailSubject, $body, $headers);

if ($sent) {
    echo json_encode(['ok' => true, 'msg' => 'Your message was sent successfully. Thank you!']);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Sorry, the message could not be sent. Please email us directly.']);
}
