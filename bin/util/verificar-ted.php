<?php

// UTIL - Verifica criptograficamente el TED (timbre) de cada boleta dentro de un
// XML de envio, usando la llave publica derivada del CAF.
//
// Uso: php bin/util/verificar-ted.php [ruta_xml] [ruta_caf]
// Por defecto revisa data/output/envio_set_boletas.xml con el CAF de data/caf/Boleta/.

require __DIR__ . '/../bootstrap.php';

function verificarTED(string $rutaArchivoXml, string $rutaArchivoCaf): void
{
    if (!file_exists($rutaArchivoXml)) {
        throw new \Exception("No existe el XML: {$rutaArchivoXml}");
    }
    if (!file_exists($rutaArchivoCaf)) {
        throw new \Exception("No existe el CAF: {$rutaArchivoCaf}");
    }

    // 1. Extraer y limpiar la llave publica
    $domCaf = new DOMDocument();
    $domCaf->load($rutaArchivoCaf);
    $rawKey = $domCaf->getElementsByTagName('RSASK')->item(0)->nodeValue;
    $llaveLimpia = str_replace(['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', "\r", "\n", " ", "\t"], '', $rawKey);
    $privateKeyPem = "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($llaveLimpia, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    $detalles = openssl_pkey_get_details(openssl_pkey_get_private($privateKeyPem));
    $publicKeyPem = $detalles['key'];

    // 2. Extraer crudo con Regex para evadir inyeccion de Namespaces
    $xmlCrudo = file_get_contents($rutaArchivoXml);
    preg_match_all('/<TED version="1\.0">(.*?)<FRMT.*?>(.*?)<\/FRMT><\/TED>/s', $xmlCrudo, $matches, PREG_SET_ORDER);

    if (!$matches) {
        echo "No se encontro ningun TED en {$rutaArchivoXml}\n";
        return;
    }

    foreach ($matches as $match) {
        $ddString = $match[1]; // Captura exactamente <DD>...</DD>
        $firmaDecodificada = base64_decode($match[2]);

        preg_match('/<F>(.*?)<\/F>/', $ddString, $fMatch);
        preg_match('/<TD>(.*?)<\/TD>/', $ddString, $tMatch);
        $folio = $fMatch[1] ?? 'N/A';
        $tipo = $tMatch[1] ?? 'N/A';

        // 3. Verificacion criptografica
        $ok = openssl_verify($ddString, $firmaDecodificada, $publicKeyPem, OPENSSL_ALGO_SHA1);
        echo ($ok === 1 ? "OK   " : "FALLA") . "  TED de TipoDTE: {$tipo} | Folio: {$folio}\n";
    }
}

$rutaXml = $argv[1] ?? 'data/output/envio_set_boletas.xml';
$rutaCaf = $argv[2] ?? 'data/caf/Boleta/TU_CAF_BOLETA_39.xml';

try {
    verificarTED($rutaXml, $rutaCaf);
} catch (\Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
