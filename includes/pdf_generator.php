<?php
require_once '../vendor/fpdf/fpdf.php';

class SolicitudParoPDF extends FPDF {
    private $titulo = 'SOLICITUD DE PARO DE PRODUCCIÓN';

    function __construct() {
        parent::__construct('L', 'mm', [139.7, 215.9]);
        $this->SetAutoPageBreak(false);
        $this->SetMargins(10, 5, 10);
    }

    function Header() {
        $logo_path = '../public/logo.png';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, 10, 5, 20, 15);
        }

        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(8);
        $this->Cell(0, 8, utf8_decode($this->titulo), 0, 1, 'C');

        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 4, utf8_decode('Sistema de Control de Producción'), 0, 1, 'C');

        $this->SetDrawColor(100, 100, 100);
        $this->Line(15, $this->GetY() + 1, 194.7, $this->GetY() + 1);
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-6);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(
            0, 4,
            utf8_decode('Página ') . $this->PageNo() .
            utf8_decode(' | Generado el ') . date('d/m/Y H:i'),
            0, 0, 'C'
        );
    }

    function crearSolicitudParo($datos) {
        $this->AddPage();

        // ---- INFORMACIÓN GENERAL
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(40, 40, 40);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 6, utf8_decode('INFORMACIÓN GENERAL'), 1, 1, 'C', true);
        $this->Ln(1);

        $col1_x = 15;
        $col2_x = 110;
        $col_width = 90;
        $line_height = 5;
        $y_inicial = $this->GetY();

        $left_rows = [
            ['ID Solicitud:', $datos['id']],
            ['Empleado:', $datos['empleado']],
            ['Área:', $datos['area']],
            ['Equipo:', $datos['equipo']],
            ['Tipo de Paro:', $datos['tipo_paro_texto']],
        ];

        $right_rows = [
            ['Fecha Solicitud:', $datos['fecha_solicitud_formato']],
            ['Estado:', ucfirst($datos['estado'])],
        ];
        if (!empty($datos['nombre_tecnico'])) $right_rows[] = ['Técnico Asignado:', $datos['nombre_tecnico']];
        if (!empty($datos['fecha_inicio_formato'])) $right_rows[] = ['Fecha Inicio:', $datos['fecha_inicio_formato']];
        if (!empty($datos['fecha_fin_formato'])) $right_rows[] = ['Fecha Finalización:', $datos['fecha_fin_formato']];
        if (isset($datos['tiempo_respuesta'])) $right_rows[] = ['Tiempo Respuesta:', $datos['tiempo_respuesta'] . ' minutos'];
        if (isset($datos['duracion_paro'])) $right_rows[] = ['Duración del Paro:', $datos['duracion_paro'] . ' minutos'];

        $rows = max(count($left_rows), count($right_rows));
        $rect_height = ($rows * $line_height) + 6;

        $this->SetDrawColor(200, 200, 200);
        $this->SetFillColor(245, 245, 245);
        $this->Rect(10, $y_inicial - 1, 194.7, $rect_height, 'DF');

        for ($i = 0; $i < $rows; $i++) {
            $y_row = $y_inicial + ($i * $line_height);

            $labelL = $left_rows[$i][0] ?? '';
            $valueL = $left_rows[$i][1] ?? '';
            $this->SetXY($col1_x, $y_row);
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(40, $line_height, utf8_decode($labelL), 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(40, 40, 40);
            $this->Cell($col_width - 40, $line_height, utf8_decode($valueL), 0, 0, 'L');

            $labelR = $right_rows[$i][0] ?? '';
            $valueR = $right_rows[$i][1] ?? '';
            $this->SetXY($col2_x, $y_row);
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(40, $line_height, utf8_decode($labelR), 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(40, 40, 40);
            $this->Cell($col_width - 40, $line_height, utf8_decode($valueR), 0, 0, 'L');
        }

        $this->SetY($y_inicial + $rect_height + 4);

        // ---- MOTIVO
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(40, 40, 40);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 6, utf8_decode('DESCRIPCIÓN DEL MOTIVO'), 1, 1, 'C', true);
        $this->Ln(1);

        $this->SetFont('Arial', '', 9);
        $raw_motivo = utf8_decode($datos['motivo']);
        $motivo_height = $this->GetMultiCellHeight(190.7, 4, $raw_motivo) + 4;
        $motivo_height = min($motivo_height, 28);

        $y_motivo = $this->GetY();
        $this->SetDrawColor(180, 180, 180);
        $this->SetFillColor(255, 255, 255);
        $this->Rect(10, $y_motivo, 194.7, $motivo_height, 'DF');

        $this->SetTextColor(60, 60, 60);
        $this->SetXY(12, $y_motivo + 2);
        $max_lines_motivo = floor($motivo_height / 4);
        if ($max_lines_motivo > 0) {
            $this->MultiCell(190.7, 4, $this->limitTextLines($raw_motivo, $max_lines_motivo), 0, 'L');
        }
        $this->SetY($y_motivo + $motivo_height + 4);

        // ---- RECHAZO
        if (!empty($datos['motivo_rechazo'])) {
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(220, 53, 69);
            $this->SetFillColor(255, 235, 235);
            $this->Cell(0, 6, utf8_decode('MOTIVO DE RECHAZO'), 1, 1, 'C', true);
            $this->Ln(1);

            $raw_rechazo = utf8_decode($datos['motivo_rechazo']);
            $rechazo_height = $this->GetMultiCellHeight(190.7, 4, $raw_rechazo) + 4;
            $rechazo_height = min($rechazo_height, 18);

            $y_rechazo = $this->GetY();
            $this->SetDrawColor(220, 150, 150);
            $this->SetFillColor(255, 240, 240);
            $this->Rect(10, $y_rechazo, 194.7, $rechazo_height, 'DF');

            $this->SetTextColor(120, 50, 50);
            $this->SetXY(12, $y_rechazo + 2);
            $max_lines_rechazo = floor($rechazo_height / 4);
            if ($max_lines_rechazo > 0) {
                $this->MultiCell(190.7, 4, $this->limitTextLines($raw_rechazo, $max_lines_rechazo), 0, 'L');
            }
            $this->SetY($y_rechazo + $rechazo_height + 4);
        }

        $this->crear_seccion_firmas_espaciada($datos);
    }

    private function crear_seccion_firmas_espaciada($datos) {
        $this->SetY(95);
        $this->SetDrawColor(150, 150, 150);
        $this->Line(15, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);

        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(40, 40, 40);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 6, utf8_decode('FIRMAS Y AUTORIZACIONES'), 1, 1, 'C', true);
        $this->Ln(6);

        $col1_x = 25;
        $col2_x = 125;
        $firma_y = $this->GetY();
        $ancho_firma = 65;

        $this->crear_campo_firma_simple($col1_x, $firma_y, $ancho_firma, 'Firma del Empleado', $datos['empleado']);
        $this->crear_campo_firma_simple($col2_x, $firma_y, $ancho_firma, 'Firma del Técnico', $datos['nombre_tecnico'] ?: 'No asignado');

        $this->SetY($firma_y + 25);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 3, utf8_decode('Documento generado por el Sistema de Control de Producción'), 0, 1, 'C');
        $this->Cell(0, 3, utf8_decode('Válido sin firma física según normativa interna de la empresa'), 0, 1, 'C');
    }

    private function crear_campo_firma_simple($x, $y, $ancho, $titulo, $nombre) {
        $this->SetXY($x, $y);
        $this->SetDrawColor(0, 0, 0);
        $this->Line($x, $y + 15, $x + $ancho, $y + 15);

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($x, $y + 17);
        $this->Cell($ancho, 4, utf8_decode($titulo), 0, 1, 'C');
        $this->SetXY($x, $y + 22);
        $this->Cell($ancho, 4, utf8_decode($nombre), 0, 1, 'C');
    }

    private function GetMultiCellHeight($w, $h, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string) $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $ns = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") { $i++; $sep = -1; $j = $i; $l = 0; $ns = 0; $nl++; continue; }
            if ($c == ' ') { $sep = $i; $ls = $l; $ns++; }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) { if ($i == $j) $i++; } else $i = $sep + 1;
                $sep = -1; $j = $i; $l = 0; $ns = 0; $nl++;
            } else $i++;
        }
        return $nl * $h;
    }

    private function limitTextLines($text, $max_lines) {
        $lines = explode("\n", wordwrap($text, 120, "\n"));
        if (count($lines) <= $max_lines) return $text;
        $allowed = array_slice($lines, 0, $max_lines);
        $last = rtrim($allowed[count($allowed)-1]);
        $allowed[count($allowed)-1] = $last . '...';
        return implode("\n", $allowed);
    }
}

/* ==============================================================
   FUNCIÓN PARA DESCARGAR PDF (botón PDF)
   ============================================================== */
function generarPDFSolicitud($datos_solicitud) {
    $datos_pdf = prepararDatosPDF($datos_solicitud);

    $pdf = new SolicitudParoPDF();
    $pdf->crearSolicitudParo($datos_pdf);

    $nombre_limpio = preg_replace('/[^a-zA-Z0-9\s]/', '', $datos_solicitud['empleado']);
    $nombre_archivo = sprintf('Solicitud_Paro_%03d_%s_%s.pdf', $datos_solicitud['id'], str_replace(' ', '_', $nombre_limpio), date('Y-m-d'));
    $pdf->Output('D', $nombre_archivo);
    exit;
}

/* ==============================================================
   FUNCIÓN PARA OBTENER PDF COMO STRING (WhatsApp)
   ============================================================== */
function generarPDFSolicitudString($datos_solicitud) {
    $datos_pdf = prepararDatosPDF($datos_solicitud);

    $pdf = new SolicitudParoPDF();
    $pdf->crearSolicitudParo($datos_pdf);

    return $pdf->Output('S'); // 'S' = devuelve string
}

/* ==============================================================
   FUNCIÓN AUXILIAR (evita duplicar código)
   ============================================================== */
function prepararDatosPDF($datos_solicitud) {
    $TIPOS_PARO = [
        'preventivo' => 'Mantenimiento Preventivo',
        'correctivo' => 'Mantenimiento Correctivo'
    ];

    $fecha_solicitud = date('d-m-Y H:i', strtotime($datos_solicitud['fecha_solicitud']));
    $fecha_inicio = $datos_solicitud['fecha_inicio'] ? date('d-m-Y H:i', strtotime($datos_solicitud['fecha_inicio'])) : '';
    $fecha_fin    = $datos_solicitud['fecha_fin']    ? date('d-m-Y H:i', strtotime($datos_solicitud['fecha_fin']))    : '';

    $datos = [
        'id'                   => $datos_solicitud['id'],
        'empleado'             => $datos_solicitud['empleado'],
        'area'                 => $datos_solicitud['area'],
        'equipo'               => $datos_solicitud['equipo'],
        'tipo_paro_texto'      => $TIPOS_PARO[$datos_solicitud['tipo_paro']] ?? ($datos_solicitud['tipo_paro'] ?? 'No definido'),
        'motivo'               => $datos_solicitud['motivo'],
        'fecha_solicitud_formato' => $fecha_solicitud,
        'estado'               => $datos_solicitud['estado'],
        'motivo_rechazo'       => $datos_solicitud['motivo_rechazo'] ?? '',
        'nombre_tecnico'       => $datos_solicitud['nombre_tecnico'] ?? '',
        'fecha_inicio_formato' => $fecha_inicio,
        'fecha_fin_formato'    => $fecha_fin
    ];

    if ($datos_solicitud['fecha_inicio']) {
        $tiempo = (strtotime($datos_solicitud['fecha_inicio']) - strtotime($datos_solicitud['fecha_solicitud'])) / 60;
        $datos['tiempo_respuesta'] = round($tiempo, 1);
    }
    if ($datos_solicitud['fecha_inicio'] && $datos_solicitud['fecha_fin']) {
        $duracion = (strtotime($datos_solicitud['fecha_fin']) - strtotime($datos_solicitud['fecha_inicio'])) / 60;
        $datos['duracion_paro'] = round($duracion, 1);
    }

    return $datos;
}
?>