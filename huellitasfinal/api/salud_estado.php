<?php
/* =========================================================
   salud_estado.php  (archivo NUEVO — solo funciones, sin salida)
   Única fuente de verdad para calcular el estado de salud.
   Lo usan salud_albergue.php y salud_registro.php.

   Reglas (prioridad EN_TRATAMIENTO > EN_OBSERVACION > SALUDABLE):
   - EN_TRATAMIENTO : hay una Vacuna o Tratamiento con fecha_fin >= hoy.
                      (AUTOMÁTICO: al vencer la fecha deja de contar.)
   - EN_OBSERVACION : hay una observación activa (Operación, Castración,
                      Esterilización, Desparasitación) o todavía no se ha
                      dado el primer "alta" (observación inicial de ingreso).
                      (MANUAL: solo termina cuando el gestor da de alta.)
   - SALUDABLE      : ninguna de las anteriores.
   ========================================================= */

const SALUD_TIPOS_TRATAMIENTO = ['Vacuna', 'Tratamiento'];
const SALUD_TIPOS_OBSERVACION = ['Operación', 'Castración', 'Esterilización', 'Desparasitación'];

function saludHoy(): string {
    // Bolivia (La Paz). Ajusta si tu servidor usa otra zona.
    return (new DateTime('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
}

function saludParseRegistros($json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function saludTratamientoVigente(array $registros, string $hoy): bool {
    foreach ($registros as $r) {
        if (!in_array($r['tipo'] ?? '', SALUD_TIPOS_TRATAMIENTO, true)) continue;
        $fin = $r['fecha_fin'] ?? null;
        if ($fin && $fin >= $hoy) return true;
    }
    return false;
}

function saludObservacionActiva(array $registros): bool {
    $hayAlta = false;
    foreach ($registros as $r) {
        $tipo = $r['tipo'] ?? '';
        if ($tipo === 'Alta') $hayAlta = true;
        if (in_array($tipo, SALUD_TIPOS_OBSERVACION, true) && ($r['activa'] ?? true) !== false) {
            return true;
        }
    }
    // Sin ningún "alta" todavía = sigue en la observación inicial de ingreso.
    return !$hayAlta;
}

function calcularEstadoSalud(array $registros): string {
    if (saludTratamientoVigente($registros, saludHoy())) return 'EN_TRATAMIENTO';
    if (saludObservacionActiva($registros))              return 'EN_OBSERVACION';
    return 'SALUDABLE';
}
