<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Die Fachlogik rechnet mit Werkstattzeiten in Wien.
date_default_timezone_set('Europe/Vienna');

// JWT-Schluessel fuer die Funktionstests (einmalig, nur lokal unter var/).
$jwtDir = dirname(__DIR__).'/var/jwt-test';
if (!is_file($jwtDir.'/private.pem')) {
    @mkdir($jwtDir, 0o777, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
        ?: throw new RuntimeException('JWT-Testschluessel konnten nicht erzeugt werden (openssl).');
    openssl_pkey_export($key, $private, 'test-passphrase');
    file_put_contents($jwtDir.'/private.pem', $private);
    file_put_contents($jwtDir.'/public.pem', openssl_pkey_get_details($key)['key']);
}
