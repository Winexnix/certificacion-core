<?php

// CERTIFICACION - Genera y envia el Reporte de Consumo de Folios (RCOF) a maullin.sii.cl.
// Los montos y el rango de folios deben ser EXACTAMENTE los mismos que se usaron en
// enviar-set-boletas.php en la misma corrida, o el SII rechaza el Set.

require __DIR__ . '/../bootstrap.php';

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Core\FirmaElectronica;
use Winex\Certificacion\Certificacion\SiiSoapClient;
use Winex\Certificacion\Certificacion\GeneradorConsumoFolios;

try {
    $cert = new Certificate($_ENV['SII_CERT_PATH'], $_ENV['SII_CERT_PASS'], $_ENV['SII_OPENSSL_PATH']);
    $firmador = new FirmaElectronica($cert->getPrivateKey(), $cert->getPublicKey());
    $siiClient = new SiiSoapClient($firmador);

    echo "1. Obteniendo Token (Cache)...\n";
    $token = $siiClient->getToken($cert->getPrivateKey(), $cert->getPublicKey());

    ['rutEmpresa' => $rutEmpresa, 'rutFirma' => $rutFirma] = datosEmisorDesdeEnv();

    // IMPORTANTE: esta fecha y este rango de folios deben ser EXACTAMENTE los mismos
    // que se usaron para generar el set de boletas en enviar-set-boletas.php en la misma
    // corrida. El SII compara el Monto Total de las boletas contra este RCOF: si no
    // coinciden fecha/folios, la revision del Set lo va a rechazar.
    $fechaBoletas = date('Y-m-d');
    $folioInicial = 56; // TODO: folio inicial del CAF nuevo usado en enviar-set-boletas.php
    $folioFinal = 60;   // TODO: folio final del CAF nuevo usado en enviar-set-boletas.php
    $secEnvio = 1;      // Subir en 1 si ya se envio un RCOF para esta MISMA fecha antes

    // Montos brutos (IVA incluido) por caso, en el mismo orden que $casos en enviar-set-boletas.php
    $montosBrutosPorCaso = [
        29800, // Caso 1: Cambio de aceite + Alineacion y balanceo
        2040,  // Caso 2: Papel de regalo
        4100,  // Caso 3: Sandwic + Bebida
        12720, // Caso 4: item afecto 1 (el exento se declara aparte)
        3500,  // Caso 5: Arroz
    ];
    $montoExento = 2000; // Caso 4: item exento 2

    $mntNeto = 0;
    $mntIVA = 0;
    foreach ($montosBrutosPorCaso as $bruto) {
        $neto = (int) round($bruto / 1.19);
        $mntNeto += $neto;
        $mntIVA += $bruto - $neto;
    }
    $mntTotal = $mntNeto + $mntIVA + $montoExento;
    $cantidadDocs = count($montosBrutosPorCaso);

    echo "2. Totales del dia {$fechaBoletas} (Tipo 39, folios {$folioInicial}-{$folioFinal}): Neto={$mntNeto} IVA={$mntIVA} Exento={$montoExento} Total={$mntTotal}\n";

    $caratula = [
        'rut_emisor' => $rutEmpresa,
        'rut_envia' => $rutFirma,
        'fch_resol' => '2026-09-04',
        'nro_resol' => '0',
        'fch_inicio' => $fechaBoletas,
        'fch_final' => $fechaBoletas,
        'sec_envio' => $secEnvio,
    ];

    $resumenes = [
        [
            'tipo' => '39',
            'neto' => $mntNeto,
            'iva' => $mntIVA,
            'tasa_iva' => '19.00',
            'exento' => $montoExento,
            'total' => $mntTotal,
            'folios_emitidos' => $cantidadDocs,
            'folios_anulados' => 0,
            'folios_utilizados' => $cantidadDocs,
            'rangos_utilizados' => [
                ['inicial' => $folioInicial, 'final' => $folioFinal],
            ],
            'rangos_anulados' => [],
        ],
    ];

    echo "3. Generando XML de Consumo de Folios (RCOF)...\n";
    $generador = new GeneradorConsumoFolios();
    $xmlRcof = $generador->generar($caratula, $resumenes);
    $xmlRcofFirmado = $firmador->firmarDte($xmlRcof, '#RCOF');

    $rutaArchivo = 'data/output/consumo_folios.xml';
    $xmlLimpio = mb_convert_encoding($xmlRcofFirmado, 'ISO-8859-1', 'UTF-8');
    file_put_contents($rutaArchivo, $xmlLimpio);
    echo "   -> Guardado en {$rutaArchivo}\n";

    echo "4. Subiendo Consumo de Folios al SII...\n";
    $trackId = $siiClient->enviarDte($token, $rutEmpresa, $rutFirma, $rutaArchivo);

    echo "\n[EXITO] Consumo de Folios subido! TrackID: " . $trackId . "\n";

} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
