<?php

/*
 * Einmalige Einrichtung am Webspace (z. B. All-Inkl), Aufruf im Backend-Ordner:
 *
 *     php84 bin/server-setup.php
 *
 * Fragt die Datenbank-Daten und die Frontend-Adresse ab, prueft die Verbindung,
 * schreibt alles korrekt in die .env (Sonderzeichen im Passwort werden
 * kodiert), erzeugt bei Bedarf neue Login-Schluessel (JWT) und spielt danach
 * Cache und Datenbank-Migrationen ein. Die alte .env wird als .env.bak gesichert.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$envFile = $root.'/.env';

function ask(string $question, string $default = ''): string
{
    echo $question.('' !== $default ? " [$default]" : '').': ';
    $answer = fgets(\STDIN);
    if (false === $answer) {
        exit(1);
    }
    $answer = trim($answer);

    return '' === $answer ? $default : $answer;
}

function fail(string $message): never
{
    fwrite(\STDERR, "\nFEHLER: $message\n");
    exit(1);
}

/** Setzt KEY=... in der .env (ersetzt die Zeile oder haengt sie an). */
function setEnv(string $content, string $key, string $value): string
{
    $line = $key."='".$value."'";
    $count = 0;
    $content = preg_replace_callback('/^'.preg_quote($key, '/').'=.*$/m', static fn (): string => $line, $content, -1, $count);

    return $count > 0 ? $content : rtrim($content, "\n")."\n".$line."\n";
}

function getEnv_(string $content, string $key): ?string
{
    if (!preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $content, $m)) {
        return null;
    }

    return trim(trim($m[1]), '"\'');
}

function console(string $command): void
{
    echo "\n> php bin/console $command\n";
    passthru(escapeshellarg(\PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/console').' '.$command, $code);
    if (0 !== $code) {
        fail("Der Befehl \"$command\" ist fehlgeschlagen (siehe oben).");
    }
}

if (\PHP_VERSION_ID < 80400) {
    fail('Bitte mit PHP 8.4 starten: php84 bin/server-setup.php');
}
if (!is_file($envFile)) {
    fail(".env nicht gefunden in $root");
}
foreach (['pdo_mysql', 'openssl', 'intl'] as $extension) {
    if (!extension_loaded($extension)) {
        fail("PHP-Erweiterung $extension fehlt.");
    }
}

echo "\n=== Datenbank (Daten stehen im KAS unter \"Datenbanken\") ===\n\n";
$host = ask('Host', 'localhost');
$name = ask('Datenbank-Name');
$user = ask('Datenbank-Benutzer', $name);
$password = ask('Datenbank-Passwort');
$port = 3306;

if ('' === $name || '' === $password) {
    fail('Datenbank-Name und Passwort duerfen nicht leer sein.');
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $rawVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
} catch (PDOException $e) {
    fail('Verbindung zur Datenbank klappt nicht: '.$e->getMessage()."\nDaten pruefen und das Skript nochmal starten.");
}

preg_match('/\d+\.\d+\.\d+/', $rawVersion, $m);
$serverVersion = ($m[0] ?? '8.0.0').(false !== stripos($rawVersion, 'mariadb') ? '-MariaDB' : '');
echo "\nVerbindung OK (Datenbank-Server $rawVersion)\n";

echo "\n=== Frontend ===\n\n";
$origin = ask('Adresse, unter der die App im Browser laeuft (z. B. https://ganglbauer.com)');
if ('' !== $origin && !preg_match('#^https?://#i', $origin)) {
    $origin = 'https://'.$origin;
}
$origin = rtrim($origin, '/');
if (!is_file($root.'/src/EventListener/CorsListener.php')) {
    echo "\nACHTUNG: src/EventListener/CorsListener.php fehlt am Server – ohne diese Datei\n"
        ."(und die neue config/services.yaml) klappt der Login vom Frontend aus nicht.\n";
}

// --- .env schreiben ------------------------------------------------------------
// Benutzer/Passwort fuer die URL kodieren; "%" verdoppeln, weil Symfony die
// Variable mit "resolve:" liest (sonst haelt es %..% fuer einen Parameter).
$encode = static fn (string $value): string => str_replace('%', '%%', rawurlencode($value));
$databaseUrl = sprintf(
    'mysql://%s:%s@%s:%d/%s?serverVersion=%s&charset=utf8mb4',
    $encode($user), $encode($password), $host, $port, $encode($name), $serverVersion,
);

$content = (string) file_get_contents($envFile);
copy($envFile, $envFile.'.bak') || fail('.env.bak konnte nicht angelegt werden.');

$content = setEnv($content, 'APP_ENV', 'prod');
$content = setEnv($content, 'APP_DEBUG', '0');
$content = setEnv($content, 'APP_SECRET', bin2hex(random_bytes(24)));
$content = setEnv($content, 'DATABASE_URL', $databaseUrl);
$content = setEnv($content, 'CORS_ALLOW_ORIGIN', $origin);
if (null === getEnv_($content, 'JWT_TTL')) {
    $content = setEnv($content, 'JWT_TTL', '43200');
}

// --- Login-Schluessel (JWT) ----------------------------------------------------
$jwtDir = $root.'/config/jwt';
$privateFile = $jwtDir.'/private.pem';
$publicFile = $jwtDir.'/public.pem';
$passphrase = (string) getEnv_($content, 'JWT_PASSPHRASE');

$keysUsable = '' !== $passphrase
    && !str_contains($passphrase, 'nicht-fuer-produktion')
    && is_file($privateFile) && is_file($publicFile)
    && false !== @openssl_pkey_get_private((string) file_get_contents($privateFile), $passphrase);

if ($keysUsable) {
    echo "\nLogin-Schluessel vorhanden und passend.\n";
} else {
    $passphrase = bin2hex(random_bytes(24));
    @mkdir($jwtDir, 0o750, true);
    $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => \OPENSSL_KEYTYPE_RSA])
        ?: fail('Login-Schluessel konnten nicht erzeugt werden (openssl).');
    openssl_pkey_export($key, $privatePem, $passphrase) || fail('Login-Schluessel konnten nicht gespeichert werden.');
    file_put_contents($privateFile, $privatePem) || fail("$privateFile ist nicht beschreibbar.");
    file_put_contents($publicFile, openssl_pkey_get_details($key)['key']) || fail("$publicFile ist nicht beschreibbar.");
    chmod($privateFile, 0o640);
    $content = setEnv($content, 'JWT_PASSPHRASE', $passphrase);
    echo "\nNeue Login-Schluessel erzeugt.\n";
}

file_put_contents($envFile, $content) || fail('.env ist nicht beschreibbar.');
chmod($envFile, 0o640);
echo ".env geschrieben (alte Version: .env.bak)\n";

// --- Ordner, Cache, Datenbank --------------------------------------------------
@mkdir($root.'/var', 0o775, true);
@chmod($root.'/var', 0o775);

console('cache:clear --no-warmup');
console('cache:warmup');
console('doctrine:migrations:migrate --no-interaction --allow-no-migration');

echo "\n=== Fertig! ===\n\nJetzt noch den ersten Chef anlegen:\n\n    php84 bin/console app:create-user p.hofer Peter Hofer --role=chef\n\n";
