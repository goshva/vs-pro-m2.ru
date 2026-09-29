<?php
/**
 * Endpoint for the lead form on the site.
 * Accepts POST (JSON body or classic form-encoded), sends the lead by email,
 * always responds with JSON so the frontend can show inline success/error UI.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'message' => 'Метод не поддерживается.'], 405);
}

// Support both fetch()-with-JSON and a plain HTML form fallback.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $data = [];
    }
} else {
    $data = $_POST;
}

// Honeypot: a hidden field real visitors never fill in.
if (!empty($data['website'])) {
    json_response(['ok' => true]); // pretend success, drop silently
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($data['csrf_token'] ?? null);
if (!is_string($csrfHeader)) {
    $csrfHeader = null;
}
if (!csrf_valid($csrfHeader)) {
    json_response(['ok' => false, 'message' => 'Сессия устарела, обновите страницу и попробуйте снова.'], 403);
}

if (too_soon_since_last_submit()) {
    json_response(['ok' => false, 'message' => 'Заявка уже отправлена, спасибо! Подождите немного перед повторной отправкой.'], 429);
}

$name = clean_input($data['name'] ?? '', 60);
$phone = clean_input($data['phone'] ?? '', 20);
// Keep line breaks in the free-text comment, just cap its length.
$message = is_string($data['message'] ?? null) ? trim(strip_tags($data['message'])) : '';
$message = mb_substr($message, 0, 1000, 'UTF-8');

if ($name === '' || $phone === '') {
    json_response(['ok' => false, 'message' => 'Укажите имя и номер телефона.'], 422);
}
if (!is_valid_phone($phone)) {
    json_response(['ok' => false, 'message' => 'Проверьте номер телефона.'], 422);
}

// Consent to personal-data processing (152-FZ) is mandatory.
if (empty($data['consent'])) {
    json_response(['ok' => false, 'message' => 'Чтобы отправить заявку, отметьте согласие на обработку персональных данных.'], 422);
}

$consentVersion = clean_input($data['consent_version'] ?? '', 120);
$page = clean_input($data['page'] ?? '', 300);

$subject = 'Заявка с сайта — ' . $name;
$lines = [
    'Имя' => $name,
    'Телефон' => $phone,
    'Комментарий' => $message,
    'Согласие на обработку ПД' => 'дано ' . date('d.m.Y H:i') . ($consentVersion !== '' ? ' (' . $consentVersion . ')' : ''),
    'IP' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'Страница' => $page,
];

$sent = send_lead_email($subject, $lines);

if (!$sent) {
    json_response(['ok' => false, 'message' => 'Не удалось отправить. Позвоните: ' . CONTACT_PHONE . '.'], 500);
}

json_response(['ok' => true, 'message' => 'Спасибо! Заявка отправлена, я свяжусь с вами.']);
