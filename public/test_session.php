<?php
// Test session en mode prod
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

$_SESSION['test'] = 'Session fonctionne !';

echo "Session ID: " . session_id() . "<br>";
echo "Session test: " . ($_SESSION['test'] ?? 'Vide') . "<br>";
echo "Cookie params: ";
print_r(session_get_cookie_params());
echo "<br><br>";
echo "PHP Session settings:<br>";
echo "session.cookie_secure: " . ini_get('session.cookie_secure') . "<br>";
echo "session.cookie_httponly: " . ini_get('session.cookie_httponly') . "<br>";
echo "session.cookie_samesite: " . ini_get('session.cookie_samesite') . "<br>";
echo "session.use_only_cookies: " . ini_get('session.use_only_cookies') . "<br>";
