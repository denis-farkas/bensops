<?php
// Test du processus de connexion
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->loadEnv(dirname(__DIR__).'/.env');

echo "APP_ENV: " . $_ENV['APP_ENV'] . "<br>";
echo "APP_DEBUG: " . $_ENV['APP_DEBUG'] . "<br><br>";

echo "Test de connexion POST simulation:<br>";
echo "URL de connexion: /connexion<br>";
echo "Méthode: POST<br><br>";

// Simuler ce qui devrait se passer
echo "Données attendues:<br>";
echo "- _username<br>";
echo "- _password<br>";
echo "- _csrf_token<br>";
echo "- _target_path (optionnel)<br><br>";

// Vérifier que le contrôleur existe
$controllerFile = dirname(__DIR__) . '/src/Controller/LoginController.php';
echo "Contrôleur existe: " . (file_exists($controllerFile) ? "OUI" : "NON") . "<br>";

if (file_exists($controllerFile)) {
    echo "<pre>";
    echo "Contenu du LoginController:\n";
    echo htmlspecialchars(file_get_contents($controllerFile));
    echo "</pre>";
}
