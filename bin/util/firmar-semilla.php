<?php

// UTIL - Toma una semilla obtenida manualmente (ej. desde el "Try it out" del
// Swagger del SII, o desde Postman) y devuelve el XML de getToken ya firmado,
// listo para usar como body de POST /boleta.electronica.token.
//
// Uso: php bin/util/firmar-semilla.php <SEMILLA>

require __DIR__ . '/../bootstrap.php';

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Core\FirmaElectronica;

if ($argc < 2) {
    echo "Uso: php bin/util/firmar-semilla.php <SEMILLA>\n";
    exit(1);
}

$semilla = trim($argv[1]);

try {
    $cert = new Certificate($_ENV['SII_CERT_PATH'], $_ENV['SII_CERT_PASS'], $_ENV['SII_OPENSSL_PATH']);
    $firmador = new FirmaElectronica($cert->getPrivateKey(), $cert->getPublicKey());

    $xmlFirmado = $firmador->firmarSemilla($semilla);

    echo "===== COPIA DESDE AQUI =====\n";
    echo $xmlFirmado . "\n";
    echo "===== HASTA AQUI =====\n";

} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
