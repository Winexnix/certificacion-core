<?php

// PRODUCCION - Consulta el estado de un envio de boleta (api.sii.cl).
// Uso: php bin/produccion/consultar-envio.php <TRACKID>
// El TRACKID lo imprime bin/produccion/emitir-boleta.php al subir la boleta.

require __DIR__ . '/../bootstrap.php';

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Core\FirmaElectronica;
use Winex\Certificacion\Produccion\SiiBoletaProduccion;

if ($argc < 2) {
    echo "Uso: php bin/produccion/consultar-envio.php <TRACKID>\n";
    exit(1);
}

$trackId = trim($argv[1]);
$rutEmpresa = datosEmisorDesdeEnv()['rutEmpresa'];

try {
    $cert = new Certificate($_ENV['SII_CERT_PATH'], $_ENV['SII_CERT_PASS'], $_ENV['SII_OPENSSL_PATH']);
    $firmador = new FirmaElectronica($cert->getPrivateKey(), $cert->getPublicKey());
    $siiBoleta = new SiiBoletaProduccion($firmador, true);

    $token = $siiBoleta->getToken();
    $data = $siiBoleta->consultarEnvio($token, $rutEmpresa, $trackId);

    echo "Estado del envio TrackID {$trackId}:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
