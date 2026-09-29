<?php

namespace Winex\Certificacion\Certificacion;

use DOMDocument;

class GeneradorConsumoFolios
{
    public function generar(array $caratula, array $resumenes): string
    {
        $dom = new DOMDocument('1.0', 'ISO-8859-1');
        $dom->formatOutput = true;

        $consumoFolios = $dom->createElement('ConsumoFolios');
        $consumoFolios->setAttribute('xmlns', 'http://www.sii.cl/SiiDte');
        $consumoFolios->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $consumoFolios->setAttribute('xsi:schemaLocation', 'http://www.sii.cl/SiiDte ConsumoFolio_v10.xsd');
        $consumoFolios->setAttribute('version', '1.0');
        $dom->appendChild($consumoFolios);

        $documento = $dom->createElement('DocumentoConsumoFolios');
        $documento->setAttribute('ID', 'RCOF');
        $consumoFolios->appendChild($documento);

        $caratulaNodo = $dom->createElement('Caratula');
        $caratulaNodo->setAttribute('version', '1.0');
        $caratulaNodo->appendChild($dom->createElement('RutEmisor', $caratula['rut_emisor']));
        $caratulaNodo->appendChild($dom->createElement('RutEnvia', $caratula['rut_envia']));
        $caratulaNodo->appendChild($dom->createElement('FchResol', $caratula['fch_resol']));
        $caratulaNodo->appendChild($dom->createElement('NroResol', (string) $caratula['nro_resol']));
        $caratulaNodo->appendChild($dom->createElement('FchInicio', $caratula['fch_inicio']));
        $caratulaNodo->appendChild($dom->createElement('FchFinal', $caratula['fch_final']));
        $caratulaNodo->appendChild($dom->createElement('SecEnvio', (string) $caratula['sec_envio']));
        $caratulaNodo->appendChild($dom->createElement('TmstFirmaEnv', date('Y-m-d\TH:i:s')));
        $documento->appendChild($caratulaNodo);

        foreach ($resumenes as $r) {
            $resumen = $dom->createElement('Resumen');
            $resumen->appendChild($dom->createElement('TipoDocumento', (string) $r['tipo']));

            if ($r['neto'] > 0) {
                $resumen->appendChild($dom->createElement('MntNeto', (string) $r['neto']));
            }
            if ($r['iva'] > 0) {
                $resumen->appendChild($dom->createElement('MntIva', (string) $r['iva']));
                $resumen->appendChild($dom->createElement('TasaIVA', $r['tasa_iva']));
            }
            if ($r['exento'] > 0) {
                $resumen->appendChild($dom->createElement('MntExento', (string) $r['exento']));
            }

            $resumen->appendChild($dom->createElement('MntTotal', (string) $r['total']));
            $resumen->appendChild($dom->createElement('FoliosEmitidos', (string) $r['folios_emitidos']));
            $resumen->appendChild($dom->createElement('FoliosAnulados', (string) $r['folios_anulados']));
            $resumen->appendChild($dom->createElement('FoliosUtilizados', (string) $r['folios_utilizados']));

            foreach ($r['rangos_utilizados'] as $rango) {
                $rangoNodo = $dom->createElement('RangoUtilizados');
                $rangoNodo->appendChild($dom->createElement('Inicial', (string) $rango['inicial']));
                $rangoNodo->appendChild($dom->createElement('Final', (string) $rango['final']));
                $resumen->appendChild($rangoNodo);
            }

            foreach ($r['rangos_anulados'] ?? [] as $rango) {
                $rangoNodo = $dom->createElement('RangoAnulados');
                $rangoNodo->appendChild($dom->createElement('Inicial', (string) $rango['inicial']));
                $rangoNodo->appendChild($dom->createElement('Final', (string) $rango['final']));
                $resumen->appendChild($rangoNodo);
            }

            $documento->appendChild($resumen);
        }

        return $dom->saveXML();
    }
}
