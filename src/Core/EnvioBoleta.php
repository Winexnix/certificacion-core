<?php

namespace Winex\Certificacion\Core;

class EnvioBoleta
{
    private $firmador;
    private $rutEmisor;
    private $rutEnvia;
    private $nroResol;
    private $fchResol;

    public function __construct($firmador, $rutEmisor, $rutEnvia, $nroResol, $fchResol)
    {
        $this->firmador = $firmador;
        $this->rutEmisor = $rutEmisor;
        $this->rutEnvia = $rutEnvia;
        $this->nroResol = $nroResol;
        $this->fchResol = $fchResol;
    }

    public function empaquetar(string $dtesFirmados): string
    {
        $dtesFirmados = preg_replace('/<\?xml.*?\?>/i', '', $dtesFirmados);

        $tipos = [];
        preg_match_all('/<TipoDTE>(\d+)<\/TipoDTE>/', $dtesFirmados, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $tipo) {
                if (!isset($tipos[$tipo])) $tipos[$tipo] = 0;
                $tipos[$tipo]++;
            }
        }

        $subTotalesXml = '';
        foreach ($tipos as $tipo => $cantidad) {
            $subTotalesXml .= "<SubTotDTE>\n<TpoDTE>{$tipo}</TpoDTE>\n<NroDTE>{$cantidad}</NroDTE>\n</SubTotDTE>\n";
        }

        $tmstFirmaEnv = date('Y-m-d\TH:i:s');

        // El sobre cambia a EnvioBOLETA y la Caratula a versión 2.0
        $xmlSobre = '<?xml version="1.0" encoding="ISO-8859-1"?>
<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd" version="1.0">
<SetDTE ID="SetDoc">
<Caratula version="1.0">
<RutEmisor>' . $this->rutEmisor . '</RutEmisor>
<RutEnvia>' . $this->rutEnvia . '</RutEnvia>
<RutReceptor>60803000-K</RutReceptor>
<FchResol>' . $this->fchResol . '</FchResol>
<NroResol>' . $this->nroResol . '</NroResol>
<TmstFirmaEnv>' . $tmstFirmaEnv . '</TmstFirmaEnv>
' . $subTotalesXml . '</Caratula>
' . $dtesFirmados . '</SetDTE>
</EnvioBOLETA>';

        return $this->firmador->firmarDte($xmlSobre, '#SetDoc');
    }
}