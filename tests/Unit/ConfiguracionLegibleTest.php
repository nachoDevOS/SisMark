<?php

use App\Models\Configuracion;

test('muestra cada valor guardado como se lee en pantalla', function (string $clave, ?string $valor, string $esperado): void {
    expect(Configuracion::legible($clave, $valor))->toBe($esperado);
})->with([
    'tope de 1 h 30' => [Configuracion::TOPE_PERMISO_MENSUAL, '90', '1h 30m'],
    'tope en cero' => [Configuracion::TOPE_PERMISO_MENSUAL, '0', 'Sin tope'],
    'tope sin valor' => [Configuracion::TOPE_PERMISO_MENSUAL, null, 'Sin tope'],
    'por contrato' => [Configuracion::TOPE_PERMISO_ALCANCE, Configuracion::TOPE_POR_CONTRATO, 'Por contrato'],
    'por mes' => [Configuracion::TOPE_PERMISO_ALCANCE, Configuracion::TOPE_POR_MES, 'Por mes'],
]);

test('cada parámetro tiene nombre corto y una sección que existe', function (): void {
    foreach (Configuracion::PARAMETROS as $parametro) {
        expect($parametro['corta'])->not->toBeEmpty()
            ->and(Configuracion::GRUPOS)->toHaveKey($parametro['grupo']);
    }
});

test('el nombre del campo no lleva puntos', function (): void {
    expect(Configuracion::campo('licencias.tope_permiso_mensual'))->toBe('licencias_tope_permiso_mensual');
});
