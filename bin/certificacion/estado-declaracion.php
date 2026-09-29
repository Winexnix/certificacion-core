<?php

/**
 * Consulta (solo lectura) el estado de la certificacion de boletas de una empresa
 * en www4.sii.cl: P90 = falta declarar cumplimiento. No graba nada.
 *
 *   php bin/certificacion/estado-declaracion.php 76123456-0
 */

require dirname(__DIR__).'/bootstrap.php';

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Postulacion\DeclaracionBoletas;
use Winex\Certificacion\Postulacion\PostulacionSii;

$rut = $argv[1] ?? exit("Uso: php bin/certificacion/estado-declaracion.php <rut-empresa>\n");

$cert = new Certificate($_ENV['SII_CERT_PATH'], $_ENV['SII_CERT_PASS'], $_ENV['SII_OPENSSL_PATH'] ?? 'openssl');

$sesion = (new PostulacionSii)->autenticar($cert);
$estado = (new DeclaracionBoletas)->estado($sesion, $rut);

echo "Empresa: {$estado->empresa}\n";
echo 'Estado:  '.($estado->codigo ?? '(sin tramite pendiente)')." {$estado->glosa}\n";
echo 'Declarar cumplimiento: '.($estado->porDeclarar() ? 'SI, pendiente' : 'no corresponde')."\n";
