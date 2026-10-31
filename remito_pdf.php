<?php
// Genera el PDF de un remito: remito_pdf.php?id=123
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fpdf.php';

session_start();
if (empty($_SESSION['usuario'])) { http_response_code(401); die('Sesión no válida.'); }

$id = intval($_GET['id'] ?? 0);
if (!$id) { die('Falta el ID del remito.'); }

$pdo = get_db();

$stmt = $pdo->prepare("SELECT * FROM remitos WHERE id = ?");
$stmt->execute([$id]);
$remito = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$remito) { die('Remito no encontrado.'); }

$stmtC = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmtC->execute([$remito['cliente_id']]);
$cliente = $stmtC->fetch(PDO::FETCH_ASSOC);

$stmtI = $pdo->prepare("SELECT * FROM remito_items WHERE remito_id = ? ORDER BY id");
$stmtI->execute([$id]);
$items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

$stmtB = $pdo->prepare("SELECT rb.barril_id, b.descripcion, b.litros FROM remito_barriles rb LEFT JOIN barriles b ON b.id = rb.barril_id WHERE rb.remito_id = ?");
$stmtB->execute([$id]);
$barriles = $stmtB->fetchAll(PDO::FETCH_ASSOC);

// Respaldo para remitos viejos que guardaron el barril en la columna singular (antes de remito_barriles)
if (count($barriles) === 0 && !empty($remito['barril_id'])) {
    $stmtLegacy = $pdo->prepare("SELECT id AS barril_id, descripcion, litros FROM barriles WHERE id = ?");
    $stmtLegacy->execute([$remito['barril_id']]);
    $legacy = $stmtLegacy->fetch(PDO::FETCH_ASSOC);
    if ($legacy) $barriles = [$legacy];
}

function money($n){ return "$ " . number_format($n, 2, ',', '.'); }
// FPDF (fuentes estándar) usa ISO-8859-1, nuestros datos vienen en UTF-8: convertimos todo texto dinámico.
function txt($s){ return @mb_convert_encoding((string)$s, 'ISO-8859-1', 'UTF-8'); }

class RemitoPDF extends FPDF {
    public $remitoNumero = '';
    public $remitoFecha = '';

    function Header(){
        // Logo a la izquierda
        if (file_exists(__DIR__.'/logo_pdf.png')) {
            $this->Image(__DIR__.'/logo_pdf.png', 15, 8, 26);
        }

        // Sello X centrado arriba
        $this->SetDrawColor(30,30,30);
        $this->SetLineWidth(0.6);
        $this->Rect(97, 8, 16, 16);
        $this->SetFont('Arial','B',20);
        $this->SetTextColor(30,30,30);
        $this->SetXY(97, 9.5);
        $this->Cell(16, 11, 'X', 0, 2, 'C');
        $this->SetFont('Arial','',6.5);
        $this->SetXY(90, 24);
        $this->Cell(30, 3, txt('Documento no fiscal'), 0, 0, 'C');

        // Dirección de fábrica a la derecha
        $this->SetFont('Arial','B',9.5);
        $this->SetTextColor(60,40,20);
        $this->SetXY(140, 9);
        $this->Cell(55, 5, 'EUSKERA', 0, 2, 'R');
        $this->SetFont('Arial','',8.5);
        $this->SetTextColor(90,90,90);
        $this->SetX(140);
        $this->Cell(55, 4.5, txt('Martin Miguel de Guemes 252'), 0, 2, 'R');
        $this->SetX(140);
        $this->Cell(55, 4.5, txt('Apostoles - Misiones'), 0, 2, 'R');
        $this->SetX(140);
        $this->Cell(55, 4.5, 'Argentina', 0, 2, 'R');

        // Línea divisoria
        $this->SetDrawColor(180,180,180);
        $this->SetLineWidth(0.2);
        $this->Line(15, 34, 195, 34);

        // Título REMITO y número, debajo de la línea
        $this->SetFont('Arial','B',14);
        $this->SetTextColor(30,30,30);
        $this->SetXY(15, 39);
        $this->Cell(95, 8, 'REMITO', 0, 0);
        $this->SetFont('Arial','B',12);
        $this->Cell(85, 8, 'N ' . $this->remitoNumero, 0, 1, 'R');
        $this->SetFont('Arial','',10);
        $this->SetTextColor(80,80,80);
        $this->SetX(110);
        $this->Cell(85, 6, 'Fecha: ' . $this->remitoFecha, 0, 1, 'R');

        $this->SetY(58);
    }
    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',7);
        $this->SetTextColor(140,140,140);
        $this->Cell(0,10,txt('Euskera Cerveza Artesanal - euskera.com.ar - Pagina ').$this->PageNo(),0,0,'C');
    }
}

$pdf = new RemitoPDF();
$pdf->remitoNumero = $remito['numero'];
$pdf->remitoFecha = date('d/m/Y', strtotime($remito['fecha']));
$pdf->AliasNbPages();
$pdf->AddPage();

// Datos del cliente
$pdf->SetFillColor(245,245,245);
$pdf->SetFont('Arial','B',10);
$pdf->SetTextColor(30,30,30);
$pdf->Cell(0, 7, 'Cliente', 0, 1, 'L', true);

$nombreCliente = $cliente ? $cliente['nombre'] : $remito['cliente_nombre'];
$pdf->SetFont('Arial','B',15);
$pdf->SetTextColor(20,20,20);
$pdf->Cell(0, 8, txt(mb_strtoupper($nombreCliente, 'UTF-8')), 0, 1);

$pdf->SetFont('Arial','',9.5);
$pdf->SetTextColor(60,60,60);
if ($cliente) {
    if (!empty($cliente['razon_social'])) $pdf->Cell(0,5.5,txt('Razon social: '.$cliente['razon_social']),0,1);
    if (!empty($cliente['cuit'])) $pdf->Cell(0,5.5,'CUIT: '.$cliente['cuit'],0,1);
    $dir = trim(($cliente['direccion']??'').' '.($cliente['localidad']??'').' '.($cliente['provincia']??''));
    if ($dir) $pdf->Cell(0,5.5,txt('Direccion: '.$dir),0,1);
}
$pdf->Ln(4);

// Tabla de items
$pdf->SetFillColor(60,45,30);
$pdf->SetTextColor(255,255,255);
$pdf->SetFont('Arial','B',9.5);
$pdf->Cell(90, 8, txt(' Descripcion'), 1, 0, 'L', true);
$pdf->Cell(25, 8, 'Cantidad', 1, 0, 'C', true);
$pdf->Cell(35, 8, txt('Precio unit.'), 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Subtotal', 1, 1, 'C', true);

$pdf->SetTextColor(30,30,30);
$pdf->SetFont('Arial','',9.5);
$fill = false;
foreach ($items as $it) {
    $pdf->SetFillColor(248,248,248);
    $pdf->Cell(90, 7, ' '.txt($it['descripcion']), 1, 0, 'L', $fill);
    $pdf->Cell(25, 7, $it['cantidad'], 1, 0, 'C', $fill);
    $pdf->Cell(35, 7, money($it['precio_unit']), 1, 0, 'R', $fill);
    $pdf->Cell(30, 7, money($it['subtotal']), 1, 1, 'R', $fill);
    $fill = !$fill;
}

$pdf->SetFont('Arial','B',10.5);
$pdf->Cell(150, 9, 'TOTAL', 1, 0, 'R');
$pdf->Cell(30, 9, money($remito['total']), 1, 1, 'R');

$pdf->Ln(4);
$pdf->SetFont('Arial','',9.5);
$pdf->Cell(0,6,txt('Medio de pago: '.$remito['medio_pago']),0,1);

// Barriles que se llevan
if (count($barriles) > 0) {
    $pdf->Ln(4);
    $pdf->SetFillColor(245,245,245);
    $pdf->SetFont('Arial','B',10);
    $pdf->SetTextColor(30,30,30);
    $pdf->Cell(0, 7, txt('Barriles que se lleva'), 0, 1, 'L', true);
    $pdf->SetFont('Arial','',9.5);
    foreach ($barriles as $b) {
        $desc = $b['descripcion'] ? ' - '.txt($b['descripcion']) : '';
        $pdf->Cell(0, 6, $b['barril_id'].$desc.' ('.$b['litros'].'L)', 0, 1);
    }

    // Tarjetas con QR: página aparte, grilla fija de 5 x 2 (10 por página)
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',10);
    $pdf->SetTextColor(30,30,30);
    $pdf->SetFillColor(245,245,245);
    $pdf->Cell(0, 7, txt('Etiquetas de barriles'), 0, 1, 'L', true);
    $pdf->Ln(4);

    $x0 = 15; $cardW = 32; $cardH = 44; $gap = 5; $perRow = 5; $perPage = 10;
    $y0 = $pdf->GetY();
    $i = 0;
    foreach ($barriles as $b) {
        $posEnPagina = $i % $perPage;
        if ($i > 0 && $posEnPagina === 0) {
            $pdf->AddPage();
            $y0 = $pdf->GetY();
        }
        $col = $posEnPagina % $perRow;
        $fila = intdiv($posEnPagina, $perRow);
        $cx = $x0 + $col * ($cardW + $gap);
        $cy = $y0 + $fila * ($cardH + $gap);

        $pdf->SetDrawColor(200,200,200);
        $pdf->Rect($cx, $cy, $cardW, $cardH);

        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode("https://panel.euskera.com.ar/?barril=".$b['barril_id']);
        $qrData = @file_get_contents($qrUrl);
        if ($qrData) {
            $tmpFile = sys_get_temp_dir().'/qr_'.md5($b['barril_id']).'.png';
            file_put_contents($tmpFile, $qrData);
            $pdf->Image($tmpFile, $cx+3, $cy+3, $cardW-6, $cardW-6);
            @unlink($tmpFile);
        }
        $pdf->SetFont('Arial','B',8);
        $pdf->SetTextColor(30,30,30);
        $pdf->SetXY($cx, $cy+$cardW-1);
        $pdf->Cell($cardW, 4, $b['barril_id'], 0, 2, 'C');
        if ($b['descripcion']) {
            $pdf->SetFont('Arial','',6.5);
            $pdf->SetX($cx);
            $pdf->Cell($cardW, 3.5, txt($b['descripcion']), 0, 0, 'C');
        }
        $i++;
    }
}

$pdf->Output('I', 'Remito_'.$remito['numero'].'.pdf');
