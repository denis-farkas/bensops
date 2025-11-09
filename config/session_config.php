<?php
// Configuration des directives de session PHP pour la production
// Fichier à inclure via php.ini ou dans config/bootstrap.php

// Directives de sécurité de session
ini_set('session.use_strict_mode', '1');          // Mode strict
ini_set('session.use_only_cookies', '1');         // Seulement les cookies
ini_set('session.use_trans_sid', '0');            // Pas d'ID dans l'URL
ini_set('session.cookie_httponly', '1');          // Cookie non accessible via JS
ini_set('session.cookie_samesite', 'Lax');        // Protection CSRF

// En production, ajuster selon votre environnement
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'prod') {
    // En production avec HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');     // Cookie sécurisé HTTPS uniquement
    } else {
        ini_set('session.cookie_secure', '0');     // HTTP autorisé (pour test)
    }
} else {
    // En développement
    ini_set('session.cookie_secure', '0');         // HTTP autorisé
}

// Durée de vie
ini_set('session.gc_maxlifetime', '7200');        // 2 heures
ini_set('session.cookie_lifetime', '7200');       // 2 heures

// Fréquence du garbage collection
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '1000');

// Configuration du nom et du chemin
ini_set('session.name', 'PHPSESSID');
ini_set('session.cookie_path', '/');

// Logs pour debugging (à retirer en production finale)
error_log('Session configuration loaded - Environment: ' . ($_ENV['APP_ENV'] ?? 'unknown'));