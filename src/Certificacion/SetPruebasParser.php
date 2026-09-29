<?php

namespace Winex\Certificacion\Certificacion;

class SetPruebasParser
{
    /**
     * Lee el archivo .txt de Set de Pruebas de Boletas que entrega el SII y arma
     * el arreglo $casos que espera GeneradorBoleta (mismo formato que antes se
     * escribia a mano en run_boletas.php).
     */
    public function parsear(string $rutaArchivo, string $tipoDte = '39'): array
    {
        if (!file_exists($rutaArchivo)) {
            throw new \Exception("No se encontro el archivo de Set de Pruebas en: {$rutaArchivo}");
        }

        $contenido = str_replace("\r\n", "\n", file_get_contents($rutaArchivo));

        // Divide el archivo en bloques por cada "CASO-N" hasta el siguiente "CASO-N" (o el fin)
        preg_match_all('/CASO-(\d+)\s*\n=+\n(.*?)(?=\nCASO-\d+\s*\n=+|\z)/s', $contenido, $bloques, PREG_SET_ORDER);

        if (empty($bloques)) {
            throw new \Exception("No se encontraron casos (CASO-N) en el archivo: {$rutaArchivo}");
        }

        $casos = [];

        foreach ($bloques as $bloque) {
            $numCaso = $bloque[1];
            $cuerpo = $bloque[2];

            $detalles = $this->parsearDetalles($cuerpo);

            if (empty($detalles)) {
                throw new \Exception("CASO-{$numCaso}: no se pudo extraer ningun item (nombre/cantidad/precio).");
            }

            $this->aplicarObservaciones($cuerpo, $detalles);

            $casos[] = [
                'tipo' => $tipoDte,
                'det' => $detalles,
                'ref' => ['codigo' => 'SET', 'razon' => "CASO-{$numCaso}"],
            ];
        }

        return $casos;
    }

    private function parsearDetalles(string $cuerpo): array
    {
        $detalles = [];

        foreach (explode("\n", $cuerpo) as $linea) {
            $linea = trim($linea);

            if ($linea === '' || stripos($linea, 'OBSERVACION') === 0) {
                continue;
            }

            // Encabezado de la tabla ("Item ... Cantidad ... Precio Unitario con IVA")
            if (stripos($linea, 'Item') === 0 && stripos($linea, 'Cantidad') !== false) {
                continue;
            }

            // Nombre + Cantidad + Precio, separados por 2 o mas espacios/tabs
            $columnas = preg_split('/\s{2,}/', $linea);
            $columnas = array_values(array_filter($columnas, fn($c) => $c !== ''));

            if (count($columnas) !== 3) {
                continue;
            }

            [$nombre, $cantidadRaw, $precioRaw] = $columnas;

            if (!$this->esNumero($cantidadRaw) || !$this->esNumero($precioRaw)) {
                continue;
            }

            $detalles[] = [
                'nombre' => trim($nombre),
                'cantidad' => $this->limpiarNumero($cantidadRaw),
                'precio' => $this->limpiarNumero($precioRaw),
            ];
        }

        return $detalles;
    }

    private function esNumero(string $valor): bool
    {
        return (bool) preg_match('/^\d{1,3}([.,]\d{3})*([.,]\d+)?$|^\d+([.,]\d+)?$/', trim($valor));
    }

    /**
     * Convierte "1.000" o "1,000" (miles) a 1000; conserva decimales genuinos si los hay.
     * @return int|float
     */
    private function limpiarNumero(string $valor)
    {
        $valor = trim($valor);

        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $valor)) {
            $numero = (int) preg_replace('/[.,]/', '', $valor);
            return $numero;
        }

        $normalizado = str_replace(',', '.', $valor);
        return strpos($normalizado, '.') !== false ? (float) $normalizado : (int) $normalizado;
    }

    /**
     * Aplica ajustes descritos en la linea "OBSERVACION: ..." del caso (exento, unidad de medida).
     * Heuristica: si menciona "item N", se aplica solo a ese item; si no, se aplica a todos.
     */
    private function aplicarObservaciones(string $cuerpo, array &$detalles): void
    {
        if (!preg_match('/OBSERVACION\s*:\s*"([^"]+)"/i', $cuerpo, $obsMatch)) {
            return;
        }

        $observacion = $obsMatch[1];

        // Exento: "El item 1 es ... afecto. El item 2 es ... exento."
        if (preg_match_all('/item\s*(\d+)[^.]*?\bexento\b/i', $observacion, $exentos)) {
            foreach ($exentos[1] as $indice) {
                $i = ((int) $indice) - 1;
                if (isset($detalles[$i])) {
                    $detalles[$i]['exento'] = true;
                }
            }
        } elseif (stripos($observacion, 'exento') !== false && count($detalles) === 1) {
            $detalles[0]['exento'] = true;
        }

        // Unidad de medida: "Se debe informar en el XML Unidad de medida en Kg."
        if (preg_match('/unidad de medida en\s+([A-Za-z]+)/i', $observacion, $unidadMatch)) {
            $unidad = rtrim($unidadMatch[1], '.,;');

            if (preg_match('/item\s*(\d+)/i', $observacion, $itemMatch) && count($detalles) > 1) {
                $i = ((int) $itemMatch[1]) - 1;
                if (isset($detalles[$i])) {
                    $detalles[$i]['unidad'] = $unidad;
                }
            } else {
                foreach ($detalles as &$item) {
                    $item['unidad'] = $unidad;
                }
                unset($item);
            }
        }
    }
}
