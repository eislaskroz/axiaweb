<?php
declare(strict_types=1);

const DESTINATARIO_CV = 'rh@axiacomunicaciones.mx';
const REMITENTE_SITIO = 'no-reply@axiacomunicaciones.com';
const MAX_ARCHIVO_BYTES = 5 * 1024 * 1024;

function regresar(string $estado): never
{
    header('Location: bolsa-trabajo.html?estado=' . rawurlencode($estado));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') regresar('seguridad');
if (!empty($_POST['sitio_web'] ?? '')) regresar('enviado');

$inicio = filter_input(INPUT_POST, 'form_inicio', FILTER_VALIDATE_INT);
if (!$inicio || (time() - $inicio) < 3 || (time() - $inicio) > 86400) regresar('seguridad');

$nombre = trim((string)($_POST['nombre'] ?? ''));
$correo = trim((string)($_POST['correo'] ?? ''));
$telefono = trim((string)($_POST['telefono'] ?? ''));
$puesto = trim((string)($_POST['puesto'] ?? ''));
$area = trim((string)($_POST['area'] ?? ''));
$experiencia = trim((string)($_POST['experiencia'] ?? ''));
$aceptacion = isset($_POST['aceptacion_privacidad']);

if ($nombre === '' || $telefono === '' || $puesto === '' || $area === '' || $experiencia === '' || !$aceptacion || !filter_var($correo, FILTER_VALIDATE_EMAIL)) regresar('campos');
foreach ([$nombre, $correo] as $valor) if (preg_match('/[\r\n]/', $valor)) regresar('seguridad');

if (!isset($_FILES['curriculum']) || $_FILES['curriculum']['error'] !== UPLOAD_ERR_OK) regresar('archivo');
$archivo = $_FILES['curriculum'];
if ($archivo['size'] <= 0 || $archivo['size'] > MAX_ARCHIVO_BYTES || !is_uploaded_file($archivo['tmp_name'])) regresar('archivo');

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($archivo['tmp_name']);
$permitidos = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/zip' => 'docx'
];
if (!isset($permitidos[$mime])) regresar('archivo');

$extension = $permitidos[$mime];
$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: 'candidato';
$nombreSeguro = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $ascii), '_');
$nombreAdjunto = 'CV_' . ($nombreSeguro ?: 'candidato') . '.' . $extension;
$contenidoAdjunto = file_get_contents($archivo['tmp_name']);
if ($contenidoAdjunto === false) regresar('archivo');

$boundary = 'AXIA-' . bin2hex(random_bytes(16));
$asunto = 'Nueva candidatura - ' . $nombre;
$cuerpo = "Nueva candidatura recibida desde la Bolsa de Trabajo de AXIA.\n\n"
    . "Nombre: {$nombre}\nCorreo: {$correo}\nTeléfono: {$telefono}\nVacante de interés: {$puesto}\nÁrea de interés: {$area}\n\n"
    . "Experiencia o perfil profesional:\n{$experiencia}\n\n"
    . "El candidato aceptó el aviso de privacidad para fines de reclutamiento.\n";

$headers = [
    'MIME-Version: 1.0',
    'From: Bolsa de Trabajo AXIA <' . REMITENTE_SITIO . '>',
    'Reply-To: ' . $correo,
    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    'X-Mailer: PHP/' . PHP_VERSION
];

$mensaje = '--' . $boundary . "\r\n";
$mensaje .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
$mensaje .= $cuerpo . "\r\n";
$mensaje .= '--' . $boundary . "\r\n";
$mensaje .= 'Content-Type: ' . $mime . '; name="' . $nombreAdjunto . '"' . "\r\n";
$mensaje .= "Content-Transfer-Encoding: base64\r\n";
$mensaje .= 'Content-Disposition: attachment; filename="' . $nombreAdjunto . '"' . "\r\n\r\n";
$mensaje .= chunk_split(base64_encode($contenidoAdjunto)) . "\r\n";
$mensaje .= '--' . $boundary . '--' . "\r\n";

$enviado = mail(DESTINATARIO_CV, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $mensaje, implode("\r\n", $headers));
regresar($enviado ? 'enviado' : 'correo');
