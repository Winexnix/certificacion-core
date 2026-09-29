<?php

namespace Winex\Certificacion\Core;

use DOMDocument;

class FirmaElectronica
{
    private $privateKey;
    private $publicKey;

    public function __construct(string $privateKey, string $publicKey)
    {
        $this->privateKey = $privateKey;

        // El SII exige el certificado limpio (sin los encabezados BEGIN/END ni saltos de línea)
        $certLimpio = str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n"],
            '',
            $publicKey
        );
        $this->publicKey = $certLimpio;
    }

    /**
     * Firma el XML <getToken><item><Semilla>...</Semilla></item></getToken> para el
     * canje de semilla por token (SOAP DTEWS y API REST de Boleta Electronica).
     *
     * A diferencia de firmarDte(), aca la <Signature> queda DENTRO del nodo firmado
     * (<getToken>) y la Reference apunta a URI="" (todo el documento), por lo que el
     * SII EXIGE el transform enveloped-signature. Usar el transform C14N como en
     * firmarDte() hace que el SII responda ESTADO 11 "elemento 'Certificate' no
     * existe, funcion getCertificado".
     */
    public function firmarSemilla(string $semilla): string
    {
        $doc = '<getToken><item><Semilla>' . $semilla . '</Semilla></item></getToken>';

        $dom = new DOMDocument();
        $dom->loadXML($doc);
        $digestValue = base64_encode(sha1($dom->documentElement->C14N(), true));

        $signedInfo = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<Reference URI="">'
            . '<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<DigestValue>' . $digestValue . '</DigestValue>'
            . '</Reference></SignedInfo>';

        $sigInfoDom = new DOMDocument();
        $sigInfoDom->loadXML($signedInfo);
        $firma = '';
        openssl_sign($sigInfoDom->documentElement->C14N(), $firma, $this->privateKey, OPENSSL_ALGO_SHA1);
        $signatureValue = base64_encode($firma);

        $certData = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey));
        $modulus = base64_encode($certData['rsa']['n']);
        $exponent = base64_encode($certData['rsa']['e']);

        return '<?xml version="1.0"?>'
            . '<getToken><item><Semilla>' . $semilla . '</Semilla></item>'
            . '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . $signedInfo
            . '<SignatureValue>' . $signatureValue . '</SignatureValue>'
            . '<KeyInfo><KeyValue><RSAKeyValue>'
            . '<Modulus>' . $modulus . '</Modulus><Exponent>' . $exponent . '</Exponent>'
            . '</RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $this->publicKey . '</X509Certificate></X509Data>'
            . '</KeyInfo></Signature></getToken>';
    }

    public function firmarDte(string $xmlString, string $referenciaUri = '#F1T33'): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xmlString);

        // Enrutador dinámico de nodos según el esquema del SII
        if (strpos($referenciaUri, 'SetDoc') !== false) {
            $tagName = 'SetDTE';
        } elseif (strpos($referenciaUri, 'Libro') !== false) {
            $tagName = 'EnvioLibro';
        } elseif (strpos($referenciaUri, 'RCOF') !== false) {
            $tagName = 'DocumentoConsumoFolios';
        } elseif (empty($referenciaUri)) {
            $tagName = 'getToken';
        } else {
            $tagName = 'Documento';
        }

        $nodoDocumento = $dom->getElementsByTagName($tagName)->item(0);

        if (!$nodoDocumento) {
            throw new \Exception("No se encontró el nodo {$tagName} para aplicar la firma.");
        }

        // [!] NUEVO: Forzar a PHP a reconocer el atributo ID para que C14N no genere un hash vacío
        if ($nodoDocumento->hasAttribute('ID')) {
            $nodoDocumento->setIdAttribute('ID', true);
        }

        $xmlCanonicalizado = $nodoDocumento->C14N();
        $digestValue = base64_encode(sha1($xmlCanonicalizado, true));

        $signedInfoParaFirma = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>
<Reference URI="' . $referenciaUri . '">
<Transforms>
<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
</Transforms>
<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>
<DigestValue>' . $digestValue . '</DigestValue>
</Reference>
</SignedInfo>';

        $sigDom = new DOMDocument();
        $sigDom->loadXML($signedInfoParaFirma);

        $firmaCalculada = '';
        openssl_sign($sigDom->documentElement->C14N(), $firmaCalculada, $this->privateKey, OPENSSL_ALGO_SHA1);
        $signatureValue = base64_encode($firmaCalculada);

        $certData = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey));
        $modulus = base64_encode($certData['rsa']['n']);
        $exponent = base64_encode($certData['rsa']['e']);

        $signatureCompleta = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">
<SignedInfo>
<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>
<Reference URI="' . $referenciaUri . '">
<Transforms>
<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>
</Transforms>
<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>
<DigestValue>' . $digestValue . '</DigestValue>
</Reference>
</SignedInfo>
<SignatureValue>' . $signatureValue . '</SignatureValue>
<KeyInfo>
<KeyValue>
<RSAKeyValue>
<Modulus>' . $modulus . '</Modulus>
<Exponent>' . $exponent . '</Exponent>
</RSAKeyValue>
</KeyValue>
<X509Data>
<X509Certificate>' . $this->publicKey . '</X509Certificate>
</X509Data>
</KeyInfo>
</Signature>';

        $firmaDom = new DOMDocument();
        $firmaDom->preserveWhiteSpace = true;
        $firmaDom->loadXML($signatureCompleta);

        $nodoFirmaImportado = $dom->importNode($firmaDom->documentElement, true);
        $dom->documentElement->appendChild($nodoFirmaImportado);

        // SII exige, al menos para boleta.electronica.token, que el archivo termine
        // exactamente en </getToken> sin salto de linea final (saveXML() agrega uno).
        return rtrim($dom->saveXML(), "\r\n");
    }
}