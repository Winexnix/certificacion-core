<?php

namespace Winex\Certificacion\Core;

use DOMDocument;

class GeneradorBoleta
{
    /**
     * @param array|null $descuento Descuento global: ['tipo' => 'porcentaje'|'monto', 'valor' => float, 'glosa' => ?string].
     *                              Se aplica SOLO sobre el monto afecto (los ítems exentos no se tocan).
     */
    public function generarBoleta(array $emisor, array $receptor, string $folio, array $detalles, string $tipoDte = '39', ?array $referencia = null, ?array $descuento = null): string
    {
        $dom = new DOMDocument('1.0', 'ISO-8859-1');
        $dom->formatOutput = true;

        $dte = $dom->createElement('DTE');
        $dte->setAttribute('version', '1.0');
        $dte->setAttribute('xmlns', 'http://www.sii.cl/SiiDte');
        $dte->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $dom->appendChild($dte);

        $idUnico = 'F' . $folio . 'T' . $tipoDte;

        $documento = $dom->createElement('Documento');
        $documento->setAttribute('ID', $idUnico);
        $dte->appendChild($documento);

        $encabezado = $dom->createElement('Encabezado');
        $documento->appendChild($encabezado);

        // 1. Identificación del Documento
        $idDoc = $dom->createElement('IdDoc');
        $idDoc->appendChild($dom->createElement('TipoDTE', $tipoDte));
        $idDoc->appendChild($dom->createElement('Folio', $folio));
        $idDoc->appendChild($dom->createElement('FchEmis', date('Y-m-d')));
        // IndServicio = 3 (Boleta de Ventas y Servicios)
        $idDoc->appendChild($dom->createElement('IndServicio', '3'));
        $encabezado->appendChild($idDoc);

        // 2. Emisor (Sin abreviaturas según normativa)
        $nodoEmisor = $dom->createElement('Emisor');
        $nodoEmisor->appendChild($dom->createElement('RUTEmisor', $emisor['rut']));
        $nodoEmisor->appendChild($dom->createElement('RznSocEmisor', htmlspecialchars($emisor['razon_social'], ENT_XML1, 'ISO-8859-1')));
        $nodoEmisor->appendChild($dom->createElement('GiroEmisor', htmlspecialchars($emisor['giro'], ENT_XML1, 'ISO-8859-1')));
        $nodoEmisor->appendChild($dom->createElement('DirOrigen', htmlspecialchars($emisor['direccion'], ENT_XML1, 'ISO-8859-1')));
        $nodoEmisor->appendChild($dom->createElement('CmnaOrigen', htmlspecialchars($emisor['comuna'], ENT_XML1, 'ISO-8859-1')));
        $encabezado->appendChild($nodoEmisor);

        // 3. Receptor (En boletas puede ser el RUT genérico 66666666-6)
        $nodoReceptor = $dom->createElement('Receptor');
        $nodoReceptor->appendChild($dom->createElement('RUTRecep', $receptor['rut']));
        if (isset($receptor['razon_social'])) {
            $nodoReceptor->appendChild($dom->createElement('RznSocRecep', htmlspecialchars($receptor['razon_social'], ENT_XML1, 'ISO-8859-1')));
        }
        $encabezado->appendChild($nodoReceptor);

        // Lógica de Totales. En boleta tipo 39 el precio del detalle va BRUTO
        // (IVA incluido), pero el XML igual debe desglosar MntNeto + IVA sobre la
        // parte afecta; si no, el SII registra la venta con neto e IVA en cero.
        $tasaIva = 19;
        $mntExe = 0;
        $mntAfectoBruto = 0;
        $detallesProcesados = [];

        foreach ($detalles as $item) {
            $montoItemFinal = round($item['cantidad'] * $item['precio']);

            if (isset($item['exento']) && $item['exento'] === true) {
                $mntExe += $montoItemFinal;
            } else {
                $mntAfectoBruto += $montoItemFinal;
            }

            $item['monto_final'] = $montoItemFinal;
            $detallesProcesados[] = $item;
        }

        // Descuento global. Solo afecta al monto afecto: los ítems exentos quedan
        // intactos.
        //
        // Se reparte entre las líneas afectas como <DescuentoMonto> en lugar de
        // declararse como <DscRcgGlobal>: el SII valida <MntTotal> contra la SUMA
        // DE LOS <MontoItem> y NO resta el descuento global de esa cuenta. Con
        // <DscRcgGlobal> respondía RPR con el reparo 260 "Monto Total No Cuadra
        // con Parciales" ("Total -> [100] <> [200] <- suma de detalles").
        $descuentoBruto = $this->calcularDescuentoBruto($descuento, $mntAfectoBruto);
        $repartoDescuento = $this->repartirDescuento($detallesProcesados, $descuentoBruto, $mntAfectoBruto);
        $mntAfectoBruto -= $descuentoBruto;

        $mntNeto = 0;
        $iva = 0;
        if ($mntAfectoBruto > 0) {
            // neto = bruto / 1.19 e IVA = bruto - neto, para que neto + IVA cuadre
            // exacto con el bruto (mismo criterio que usa el SII).
            $mntNeto = (int) round($mntAfectoBruto / (1 + $tasaIva / 100));
            $iva = $mntAfectoBruto - $mntNeto;
        }
        $mntTotal = $mntNeto + $iva + $mntExe;

        // 4. Totales. Orden exigido por el esquema: MntNeto, MntExe, IVA, MntTotal.
        $totales = $dom->createElement('Totales');
        if ($mntNeto > 0) {
            $totales->appendChild($dom->createElement('MntNeto', (string) $mntNeto));
        }
        if ($mntExe > 0) {
            $totales->appendChild($dom->createElement('MntExe', (string) $mntExe));
        }
        if ($iva > 0) {
            $totales->appendChild($dom->createElement('IVA', (string) $iva));
        }
        $totales->appendChild($dom->createElement('MntTotal', (string) $mntTotal));
        $encabezado->appendChild($totales);

        // 5. Detalles
        $nroLinDet = 1;
        foreach ($detallesProcesados as $idx => $item) {
            $detalle = $dom->createElement('Detalle');
            $detalle->appendChild($dom->createElement('NroLinDet', (string) $nroLinDet));

            if (isset($item['exento']) && $item['exento'] === true) {
                $detalle->appendChild($dom->createElement('IndExe', '1'));
            }

            $detalle->appendChild($dom->createElement('NmbItem', htmlspecialchars($item['nombre'], ENT_XML1, 'ISO-8859-1')));
            $detalle->appendChild($dom->createElement('QtyItem', (string) $item['cantidad']));

            if (isset($item['unidad'])) {
                $detalle->appendChild($dom->createElement('UnmdItem', htmlspecialchars($item['unidad'], ENT_XML1, 'ISO-8859-1')));
            }

            $detalle->appendChild($dom->createElement('PrcItem', (string) $item['precio']));

            // El esquema exige <DescuentoMonto> entre <PrcItem> y <MontoItem>, y
            // MontoItem tiene que venir ya neto del descuento: es la cifra que el
            // SII suma para contrastar contra <MntTotal>.
            $descuentoLinea = $repartoDescuento[$idx] ?? 0;
            if ($descuentoLinea > 0) {
                $detalle->appendChild($dom->createElement('DescuentoMonto', (string) $descuentoLinea));
            }

            $detalle->appendChild($dom->createElement('MontoItem', (string) ($item['monto_final'] - $descuentoLinea)));

            $documento->appendChild($detalle);
            $nroLinDet++;
        }

        // 6. Referencia exigida por el Set de Pruebas
        if ($referencia) {
            $ref = $dom->createElement('Referencia');
            $ref->appendChild($dom->createElement('NroLinRef', '1'));
            $ref->appendChild($dom->createElement('CodRef', htmlspecialchars($referencia['codigo'], ENT_XML1, 'ISO-8859-1')));
            $ref->appendChild($dom->createElement('RazonRef', htmlspecialchars($referencia['razon'], ENT_XML1, 'ISO-8859-1')));
            $documento->appendChild($ref);
        }

        return $dom->saveXML();
    }

    /**
     * Reparte el descuento global entre las líneas afectas, a prorrata de su
     * monto. Devuelve el descuento por índice de línea.
     *
     * El SII contrasta <MntTotal> contra la suma de los <MontoItem> y no resta
     * el <DscRcgGlobal>, así que el descuento tiene que bajar las líneas. El
     * reparto suma EXACTAMENTE el descuento pedido: se usa floor por línea y el
     * resto (a lo sumo un peso por línea) se reparte de a uno entre las que
     * todavía tienen margen.
     *
     * @param  array<int, array<string, mixed>>  $detalles
     * @return array<int, int>
     */
    private function repartirDescuento(array $detalles, int $descuentoBruto, int $mntAfectoBruto): array
    {
        if ($descuentoBruto <= 0 || $mntAfectoBruto <= 0) {
            return [];
        }

        $afectas = [];
        foreach ($detalles as $i => $d) {
            if (empty($d['exento']) && $d['monto_final'] > 0) {
                $afectas[$i] = (int) $d['monto_final'];
            }
        }

        $reparto = [];
        $asignado = 0;

        foreach ($afectas as $i => $monto) {
            $reparto[$i] = (int) floor($descuentoBruto * $monto / $mntAfectoBruto);
            $asignado += $reparto[$i];
        }

        $resto = $descuentoBruto - $asignado;

        while ($resto > 0) {
            $movio = false;

            foreach ($afectas as $i => $monto) {
                if ($resto <= 0) {
                    break;
                }
                if ($reparto[$i] < $monto) {
                    $reparto[$i]++;
                    $resto--;
                    $movio = true;
                }
            }

            if (! $movio) {
                break;   // ya no queda margen (el descuento consumió todo el afecto)
            }
        }

        return $reparto;
    }

    /**
     * Monto bruto a descontar, acotado al afecto disponible: el descuento nunca
     * puede dejar el afecto en negativo (el SII rechaza montos negativos).
     */
    private function calcularDescuentoBruto(?array $descuento, int $mntAfectoBruto): int
    {
        if (! $descuento || $mntAfectoBruto <= 0) {
            return 0;
        }

        $valor = (float) ($descuento['valor'] ?? 0);

        if ($valor <= 0) {
            return 0;
        }

        $bruto = ($descuento['tipo'] ?? 'monto') === 'porcentaje'
            ? (int) round($mntAfectoBruto * min($valor, 100) / 100)
            : (int) round($valor);

        return max(0, min($bruto, $mntAfectoBruto));
    }
}