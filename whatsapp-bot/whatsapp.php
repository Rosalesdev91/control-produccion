<?php
// whatsapp-bot/whatsapp.php

/**
 * Envía solo texto a varios números
 */
function enviarWhatsAppATodos($mensaje)
{
    $numeros = [
        "50672360749",
        "50670142325",
        "50670569370",
        "50661373084",
        "50672416796",
        "50660496788",
        "50664327713"
    ];

    $url = "http://localhost:3000/send";

    foreach ($numeros as $numero) {
        $payload = [
            "numero"  => $numero,
            "mensaje" => $mensaje
        ];

        $options = [
            "http" => [
                "header"  => "Content-Type: application/json\r\n",
                "method"  => "POST",
                "content" => json_encode($payload, JSON_UNESCAPED_UNICODE),
                "timeout" => 8,
                "ignore_errors" => true
            ]
        ];

        $context = stream_context_create($options);
        @file_get_contents($url, false, $context);
    }

    return true;
}

/**
 * NUEVA FUNCIÓN: Envía PDF + mensaje con caption a UN número
 * (la que necesitas en solicitudes_paro.php)
 */
function enviarWhatsAppPDF($numero_destino, $mensaje_texto, $ruta_pdf)
{
    // Limpiar número (solo dígitos)
    $numero = preg_replace('/[^0-9]/', '', $numero_destino);
    if (strlen($numero) === 8) {
        $numero = "506" . $numero;
    }

    // Verificar que el PDF exista
    if (!file_exists($ruta_pdf)) {
        error_log("WhatsApp PDF: Archivo no encontrado → $ruta_pdf");
        return false;
    }

    // Leer y codificar en base64
    $pdf_base64 = base64_encode(file_get_contents($ruta_pdf));
    $nombre_pdf = basename($ruta_pdf);

    $url = "http://localhost:3000/send-media";   // <-- ¡IMPORTANTE! Tu bot debe tener este endpoint

    $payload = [
        "numero"   => $numero,
        "caption"  => $mensaje_texto,
        "base64"   => $pdf_base64,
        "filename" => $nombre_pdf
    ];

    $options = [
        "http" => [
            "header"  => "Content-Type: application/json\r\n",
            "method"  => "POST",
            "content" => json_encode($payload, JSON_UNESCAPED_UNICODE),
            "timeout" => 15,
            "ignore_errors" => true
        ]
    ];

    $context = stream_context_create($options);
    $result  = @file_get_contents($url, false, $context);

    // Log sencillo para ver si funcionó
    $log = $result ? "OK" : "FALLÓ";
    error_log("WhatsApp PDF → $numero | $nombre_pdf | $log");

    return $result !== false;
}
?>