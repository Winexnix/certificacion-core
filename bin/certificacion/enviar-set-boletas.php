<?php

// CERTIFICACION - Envia el Set de Pruebas de Boletas (tipo 39) a maullin.sii.cl.
// Los casos se leen del archivo que entrega el SII en data/set_pruebas/.

require __DIR__ . '/../bootstrap.php';

use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Core\FirmaElectronica;
use Winex\Certificacion\Core\GeneradorBoleta;
use Winex\Certificacion\Core\EnvioBoleta;
use Winex\Certificacion\Core\Timbre;
use Winex\Certificacion\Certificacion\SiiSoapClient;
use Winex\Certificacion\Certificacion\SetPruebasParser;

try {
    $cert = new Certificate($_ENV['SII_CERT_PATH'], $_ENV['SII_CERT_PASS'], $_ENV['SII_OPENSSL_PATH']);
    $firmador = new FirmaElectronica($cert->getPrivateKey(), $cert->getPublicKey());
    $siiClient = new SiiSoapClient($firmador);

    echo "1. Obteniendo Token (Cache)...\n";
    $token = $siiClient->getToken($cert->getPrivateKey(), $cert->getPublicKey());

    ['rutEmpresa' => $rutEmpresa, 'rutFirma' => $rutFirma, 'emisor' => $emisor] = datosEmisorDesdeEnv();

    // El giro NO debe tener abreviaciones segun instrucciones del SII

    // El receptor en boletas puede ser anonimo
    $receptor = [
        'rut' => '66666666-6',
        'razon_social' => 'Cliente General'
    ];

    // Los casos se leen automaticamente del Set de Pruebas que entrega el SII.
    // Para actualizarlos: reemplaza el archivo en data/set_pruebas/Set Prueba BE.txt
    // por el que te entregue el SII (mismo formato .txt del portal), sin tocar este script.
    $rutaSetPruebas = 'data/set_pruebas/Set Prueba BE.txt';
    $parser = new SetPruebasParser();
    $casos = $parser->parsear($rutaSetPruebas, '39');

    echo "2. Casos leidos de {$rutaSetPruebas}:\n";
    foreach ($casos as $i => $caso) {
        echo "   -> {$caso['ref']['razon']}:\n";
        foreach ($caso['det'] as $item) {
            $flags = [];
            if (!empty($item['exento'])) $flags[] = 'exento';
            if (!empty($item['unidad'])) $flags[] = "unidad={$item['unidad']}";
            $sufijo = $flags ? ' [' . implode(', ', $flags) . ']' : '';
            echo "        {$item['nombre']} | cantidad={$item['cantidad']} | precio={$item['precio']}{$sufijo}\n";
        }
    }
    echo "   Revisa que lo anterior coincida con tu Set de Pruebas antes de continuar.\n";

    // TODO: actualizar con la ruta del CAF nuevo (folios totalmente frescos, sin usar)
    $rutasCaf = [
        '39' => 'data/caf/Boleta/TU_CAF_BOLETA_39.xml'
    ];

    // TODO: actualizar con el rango de folios del CAF nuevo (uno por caso, mismo orden)
    $folioQueue = [
        '39' => [56, 57, 58, 59, 60]
    ];

    if (count($folioQueue['39']) !== count($casos)) {
        throw new \Exception('La cantidad de folios en $folioQueue (' . count($folioQueue['39']) . ') no coincide con la cantidad de casos leidos (' . count($casos) . ').');
    }

    echo "3. Procesando " . count($casos) . " Casos de Boletas (envio unico)...\n";
    $generador = new GeneradorBoleta();
    $dtesFirmados = '';

    foreach ($casos as $i => $caso) {
        $numCaso = $i + 1;
        echo "   -> Generando Caso {$numCaso}\n";

        $folio = array_shift($folioQueue['39']);
        $rutaCaf = $rutasCaf['39'];

        // Se inyecta la referencia exigida: <CodRef> SET y <RazonRef> CASO-X
        $xmlDte = $generador->generarBoleta($emisor, $receptor, (string) $folio, $caso['det'], $caso['tipo'], $caso['ref']);

        $dom = new DOMDocument();
        $dom->loadXML($xmlDte);
        $montoTotal = $dom->getElementsByTagName('MntTotal')->item(0)->nodeValue;
        $item1 = $dom->getElementsByTagName('NmbItem')->item(0)->nodeValue;

        $datosDte = [
            'rut_emisor' => $rutEmpresa,
            'tipo_dte' => $caso['tipo'],
            'folio' => (string) $folio,
            'fecha' => date('Y-m-d'),
            'rut_receptor' => $receptor['rut'],
            'razon_social_receptor' => $receptor['razon_social'],
            'monto_total' => $montoTotal,
            'nombre_item_1' => $item1
        ];

        $timbre = new Timbre($rutaCaf);
        $stringTed = $timbre->generarTed($datosDte);
        $tmstFirma = "<TmstFirma>" . date('Y-m-d\TH:i:s') . "</TmstFirma>";

        $xmlDteConTed = str_replace('</Documento>', $stringTed . "\n" . $tmstFirma . "\n</Documento>", $xmlDte);
        $referenciaUri = '#F' . $folio . 'T' . $caso['tipo'];
        $dtesFirmados .= $firmador->firmarDte($xmlDteConTed, $referenciaUri) . "\n";
    }

    echo "4. Empaquetando en un EnvioDTE...\n";
    $empaquetador = new EnvioBoleta($firmador, $rutEmpresa, $rutFirma, '0', '2026-09-05');
    $xmlEnvioFinal = $empaquetador->empaquetar($dtesFirmados);

    $rutaArchivo = 'data/output/envio_set_boletas.xml';
    $xmlLimpio = mb_convert_encoding($xmlEnvioFinal, 'ISO-8859-1', 'UTF-8');
    file_put_contents($rutaArchivo, $xmlLimpio);

    echo "5. Subiendo Set de Boletas al SII...\n";
    $trackId = $siiClient->enviarDte($token, $rutEmpresa, $rutFirma, $rutaArchivo);

    echo "\n[EXITO] Set de Boletas subido! TrackID: " . $trackId . "\n";

} catch (\Exception $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
}
