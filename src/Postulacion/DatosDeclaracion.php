<?php

namespace Winex\Certificacion\Postulacion;

/**
 * Lo que se informa en la declaracion de cumplimiento de boletas (www4). El SII
 * exige los tres datos del proveedor de software: es la empresa que desarrolla o
 * vende el sistema (para clientes, la SpA que vende el software; para ella misma,
 * ella misma).
 */
final class DatosDeclaracion
{
    public readonly Rut $empresa;

    public readonly Rut $proveedor;

    /** @throws PostulacionException */
    public function __construct(
        string $rutEmpresa,
        public readonly string $linkConsulta,
        string $rutProveedor,
        public readonly string $nombreProveedor,
        public readonly string $correoProveedor,
    ) {
        $this->empresa = Rut::de($rutEmpresa, 'RUT de la empresa');
        $this->proveedor = Rut::de($rutProveedor, 'RUT del proveedor de software');

        if (trim($linkConsulta) === '' || mb_strlen($linkConsulta) > 100) {
            throw new PostulacionException('El link de consulta de boletas es obligatorio y tiene como maximo 100 caracteres.');
        }
        if (trim($nombreProveedor) === '' || mb_strlen($nombreProveedor) > 30) {
            throw new PostulacionException('El nombre del proveedor de software es obligatorio y tiene como maximo 30 caracteres.');
        }

        DatosPostulacion::correo($correoProveedor, 'correo del proveedor de software');
    }
}
