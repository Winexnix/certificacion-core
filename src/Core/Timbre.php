<?php

namespace Winex\Certificacion\Core;

use DOMDocument;

class Timbre
{
    private $cafXml;
    private $rsaKey;

    /**
     * @param string $caf Ruta al XML del CAF, o el XML crudo (si empieza con "<").
     */
    public function __construct(string $caf)
    {
        $esXml = str_starts_with(ltrim($caf), '<');

        if (!$esXml && !file_exists($caf)) {
            throw new \Exception("No se encontró el archivo CAF en: {$caf}");
        }

        $contenido = $esXml ? $caf : file_get_contents($caf);

        // Algunos CAF del SII traen bytes ISO-8859-1 reales (tildes de la razón
        // social, ej. "DÍAZ") aunque el prolog no lo declare. DOMDocument asume
        // UTF-8 y truena con "Input is not proper UTF-8". Se detecta y convierte.
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
            $contenido = preg_replace('/(<\?xml[^>]*encoding=")[^"]*(")/i', '$1UTF-8$2', $contenido, 1);
        }

        // El bloque <CAF> se toma tal cual del XML original en vez de vía
        // saveXML() del nodo: reserializar puede cambiar comillas de atributos
        // o entidades, y ese bloque lleva la <FRMA> que el SII calculó sobre su
        // propio <DA>. Los blancos sí se aplanan después, junto con el resto del
        // <DD> (ver normalizarParaFirma).
        if (!preg_match('/<CAF\b.*?<\/CAF>/s', $contenido, $mCaf)) {
            throw new \Exception('El XML no contiene el nodo <CAF>.');
        }
        $this->cafXml = $mCaf[0];

        $dom = new DOMDocument();
        $dom->loadXML($contenido);

        $rawKeyNode = $dom->getElementsByTagName('RSASK')->item(0);
        if (!$rawKeyNode) {
            throw new \Exception("El CAF no contiene el nodo <RSASK> (llave privada del timbre).");
        }
        $rawKey = $rawKeyNode->nodeValue;

        // 1. Limpiamos absolutamente todo: cabeceras existentes, saltos de línea, tabulaciones y espacios
        $llaveLimpia = str_replace(
            ['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', "\r", "\n", " ", "\t"],
            '',
            $rawKey
        );

        // 2. Reconstruimos el formato PEM estricto que exige openssl_sign de PHP
        $this->rsaKey = "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($llaveLimpia, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    public static function fromXml(string $cafXml): self
    {
        return new self($cafXml);
    }

    private function normalizarParaFirma(string $xml): string
    {
        // El SII elimina blancos, tabs y saltos de línea entre tags antes de
        // validar la firma del TED (y la del propio CAF).
        return preg_replace('/>\s+</', '><', trim($xml));
    }

    public function generarTed(array $datosDte): string
    {
        // mb_substr y no substr: cortar a 40 bytes puede partir un carácter
        // multibyte y dejar UTF-8 inválido. Los datos entran en UTF-8.
        $item1Truncado = mb_substr($datosDte['nombre_item_1'], 0, 40, 'UTF-8');
        $item1Escapado = htmlspecialchars($item1Truncado, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        $rsrEscapado = htmlspecialchars($datosDte['razon_social_receptor'], ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');

        $dd = "<DD>" .
            "<RE>{$datosDte['rut_emisor']}</RE>" .
            "<TD>{$datosDte['tipo_dte']}</TD>" .
            "<F>{$datosDte['folio']}</F>" .
            "<FE>{$datosDte['fecha']}</FE>" .
            "<RR>{$datosDte['rut_receptor']}</RR>" .
            "<RSR>{$rsrEscapado}</RSR>" .
            "<MNT>{$datosDte['monto_total']}</MNT>" .
            "<IT1>{$item1Escapado}</IT1>" .
            $this->cafXml .
            "<TSTED>" . date('Y-m-d\TH:i:s') . "</TSTED>" .
            "</DD>";

        // El SII aplana los blancos entre tags ANTES de verificar el FRMT, así
        // que hay que firmar (y transmitir) el DD ya aplanado. Comprobado con
        // el folio 15650: enviando el <CAF> con sus saltos de línea originales
        // nuestra firma calzaba con los bytes transmitidos (629), pero el SII
        // verificaba sobre los 618 aplanados y respondía RPR con el reparo 510
        // "Firma Timbre Electrónico Incorrecta".
        $dd = $this->normalizarParaFirma($dd);

        // Hasta aquí $dd está en UTF-8 (el CAF se normalizó a UTF-8 al cargarlo).
        // El DTE viaja al SII en ISO-8859-1 y la firma del TED se
        // valida sobre esos bytes tal como llegan, así que hay que convertir
        // ANTES de firmar. Con razones sociales sin tilde daba igual; con "DÍAZ"
        // el SII rechazaba con RFR.
        $dd = mb_convert_encoding($dd, 'ISO-8859-1', 'UTF-8');

        $firmaCalculada = '';
        if (!openssl_sign($dd, $firmaCalculada, $this->rsaKey, OPENSSL_ALGO_SHA1)) {
            throw new \Exception("Fallo al firmar el TED: " . openssl_error_string());
        }
        $firmaBase64 = base64_encode($firmaCalculada);

        return "<TED version=\"1.0\">" .
            $dd .
            "<FRMT algoritmo=\"SHA1withRSA\">{$firmaBase64}</FRMT>" .
            "</TED>";
    }
}