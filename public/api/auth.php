<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

api_method('POST');
api_csrf();
$b = json_body();
$action = (string) ($b['action'] ?? '');

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

if (!in_array($action, ['login', 'register'], true)) {
    json_error('Unknown action.', 400);
}

$email = strtolower(trim((string) ($b['email'] ?? '')));
$pass  = (string) ($b['password'] ?? '');
$errors = [];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if ($action === 'register') {
    $name = trim((string) ($b['full_name'] ?? ''));
    if ($name === '' || strlen($name) > 120) {
        $errors[] = 'Please enter your name (max 120 characters).';
    }
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($errors) {
        json_error('Please fix the highlighted problems.', 422, $errors);
    }
    $st = db()->prepare('SELECT 1 FROM users WHERE email = :e');
    $st->execute([':e' => $email]);
    if ($st->fetch()) {
        json_error('An account with this email already exists.', 409, ['An account with this email already exists.']);
    }
    $st = db()->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (:e, :h, :n) RETURNING id');
    try {
        $st->execute([':e' => $email, ':h' => password_hash($pass, PASSWORD_DEFAULT), ':n' => $name]);
    } catch (PDOException $ex) {
        if ((string) $ex->getCode() === '23505') { // two sign-ups with the same email at the same moment
            json_error('An account with this email already exists.', 409, ['An account with this email already exists.']);
        }
        throw $ex;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $st->fetchColumn();
    json_out(['user' => current_user(), 'csrf' => csrf_token()], 201);
}

// login
if ($errors || $pass === '') {
    json_error('Please enter your email and password.', 422, $errors ?: ['Please enter your password.']);
}
$st = db()->prepare('SELECT id, password_hash FROM users WHERE email = :e AND active');
$st->execute([':e' => $email]);
$row = $st->fetch();
if (!$row || !password_verify($pass, $row['password_hash'])) {
    json_error('Incorrect email or password.', 401, ['Incorrect email or password.']);
}
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $row['id'];
json_out(['user' => current_user(), 'csrf' => csrf_token()]);
