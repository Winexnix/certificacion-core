<?php

// PRODUCCION - Emite una boleta electronica real contra la API REST del SII
// (api.sii.cl para auth/token, rahue.sii.cl para envio).
//
// Interruptor de seguridad: con $confirmarEnvioReal = false el script solo prueba
// el token (solo lectura, no genera ningun documento) y se detiene ANTES de
// generar o subir la boleta real. Cambialo a true solo cuando tengas el CAF de
// produccion y quieras emitir de verdad.

require __DIR__ . '/../bootstrap.php';

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Core\FirmaElectronica;
use Winex\Certificacion\Core\GeneradorBoleta;
use Winex\Certificacion\Core\EnvioBoleta;
use Winex\Certificacion\Core\Timbre;
use Winex\Certificacion\Produccion\SiiBoletaProduccion;

$confirmarEnvioReal = false;

try {
    $cert = new Certificate($_ENV['SII_CERT_PATH'], $_ENV['SII_CERT_PASS'], $_ENV['SII_OPENSSL_PATH']);
    $firmador = new FirmaElectronica($cert->getPrivateKey(), $cert->getPublicKey());

    $siiBoleta = new SiiBoletaProduccion($firmador, true); // true = produccion

    echo "1. Probando autenticacion contra la API de Boleta Electronica (produccion)...\n";
    $token = $siiBoleta->getToken();
    echo "   -> Token obtenido OK: " . substr($token, 0, 6) . "...\n";

    if (!$confirmarEnvioReal) {
        echo "\n[OK] Autenticacion funciona. No se genero ni subio ninguna boleta (\$confirmarEnvioReal = false).\n";
        echo "Cuando tengas el CAF de produccion:\n";
        echo "  1) Actualiza \$rutaCaf y \$folio mas abajo.\n";
        echo "  2) Cambia \$confirmarEnvioReal a true.\n";
        echo "  3) Revisa el resumen que imprime el script ANTES de que suba nada.\n";
        exit;
    }

    ['rutEmpresa' => $rutEmpresa, 'rutFirma' => $rutFirma, 'emisor' => $emisor] = datosEmisorDesdeEnv();

    $receptor = ['rut' => '66666666-6', 'razon_social' => 'Cliente General'];

    // TODO: completar con datos reales del CAF de produccion antes de usar $confirmarEnvioReal = true
    $rutaCaf = 'data/caf/Boletapro/PENDIENTE_CAF_PRODUCCION.xml';
    $folio = 0; // primer folio disponible del CAF de produccion

    $detalle = [
        ['nombre' => 'Producto de prueba', 'cantidad' => 1, 'precio' => 1000],
    ];

    echo "\n2. Boleta a emitir (PRODUCCION - documento real, consume folio):\n";
    echo "   Emisor: {$emisor['razon_social']} ({$rutEmpresa})\n";
    echo "   Receptor: {$receptor['razon_social']} ({$receptor['rut']})\n";
    echo "   Folio: {$folio} | CAF: {$rutaCaf}\n";
    echo "   Item: {$detalle[0]['nombre']} x{$detalle[0]['cantidad']} @ \${$detalle[0]['precio']}\n";

    $generador = new GeneradorBoleta();
    $xmlDte = $generador->generarBoleta($emisor, $receptor, (string) $folio, $detalle, '39', null);

    $dom = new DOMDocument();
    $dom->loadXML($xmlDte);
    $montoTotal = $dom->getElementsByTagName('MntTotal')->item(0)->nodeValue;
    $item1 = $dom->getElementsByTagName('NmbItem')->item(0)->nodeValue;

    $datosDte = [
        'rut_emisor' => $rutEmpresa,
        'tipo_dte' => '39',
        'folio' => (string) $folio,
        'fecha' => date('Y-m-d'),
        'rut_receptor' => $receptor['rut'],
        'razon_social_receptor' => $receptor['razon_social'],
        'monto_total' => $montoTotal,
        'nombre_item_1' => $item1,
    ];

    $timbre = new Timbre($rutaCaf);
    $stringTed = $timbre->generarTed($datosDte);
    $tmstFirma = "<TmstFirma>" . date('Y-m-d\TH:i:s') . "</TmstFirma>";

    $xmlDteConTed = str_replace('</Documento>', $stringTed . "\n" . $tmstFirma . "\n</Documento>", $xmlDte);
    $referenciaUri = '#F' . $folio . 'T39';
    $dteFirmado = $firmador->firmarDte($xmlDteConTed, $referenciaUri);

    echo "3. Empaquetando en EnvioBOLETA...\n";
    // NroResol/FchResol de PRODUCCION: Resolucion Exenta SII N.80, del 22-08-2014.
    $nroResolProduccion = '80';
    $fchResolProduccion = '2014-08-22';
    $empaquetador = new EnvioBoleta($firmador, $rutEmpresa, $rutFirma, $nroResolProduccion, $fchResolProduccion);
    $xmlEnvioFinal = $empaquetador->empaquetar($dteFirmado);

    $rutaArchivo = 'data/output/boleta_produccion_' . $folio . '.xml';
    $xmlLimpio = mb_convert_encoding($xmlEnvioFinal, 'ISO-8859-1', 'UTF-8');
    file_put_contents($rutaArchivo, $xmlLimpio);
    echo "   -> Guardado en {$rutaArchivo}\n";

    echo "4. Subiendo boleta a PRODUCCION (rahue.sii.cl)...\n";
    $resultado = $siiBoleta->enviarBoleta($token, $rutEmpresa, $rutFirma, $rutaArchivo);

    echo "\n[EXITO] Boleta subida. TrackID: {$resultado['trackid']} | Estado: {$resultado['estado']}\n";
    echo "Consulta el resultado en unos minutos con bin/produccion/consultar-envio.php.\n";

} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
