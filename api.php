<?php
// ------------------------------------------------------------------
// API del panel Euskera — modelo relacional con remitos.
// ------------------------------------------------------------------
require_once __DIR__ . '/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

function respond($arr){ echo json_encode($arr); exit; }
function rows($pdo, $sql, $params = []){
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$action = $input['action'] ?? '';

try {
    $pdo = get_db();

    // ---------- auth ----------
    if ($action === 'login') {
        $usuario = strtolower(trim($input['usuario'] ?? ''));
        $pass = $input['pass'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['usuario'] = $row['usuario'];
            $_SESSION['nombre'] = $row['nombre'];
            respond(['ok' => true, 'auth' => ['key' => $row['usuario'], 'nombre' => $row['nombre']]]);
        }
        respond(['ok' => false, 'error' => 'Usuario o contraseña incorrectos.']);
    }
    if ($action === 'check_session') {
        if (!empty($_SESSION['usuario'])) {
            respond(['ok' => true, 'auth' => ['key' => $_SESSION['usuario'], 'nombre' => $_SESSION['nombre']]]);
        }
        respond(['ok' => true, 'auth' => null]);
    }
    if ($action === 'logout') { session_destroy(); respond(['ok' => true]); }

    if (empty($_SESSION['usuario'])) {
        http_response_code(401);
        respond(['ok' => false, 'error' => 'Sesión no válida. Iniciá sesión de nuevo.']);
    }
    $usr = $_SESSION['usuario'];

    // ---------- funciones internas reutilizables ----------
    function consumir_insumo_fifo($pdo, $insumoId, $cantidadNecesaria, $loteProduccionId, $usr, $referencia){
        $restante = floatval($cantidadNecesaria);
        $lotes = $pdo->prepare("SELECT * FROM lotes_insumo WHERE insumo_id=? AND cantidad_disponible > 0 ORDER BY fecha_ingreso ASC, id ASC");
        $lotes->execute([$insumoId]);
        foreach ($lotes->fetchAll(PDO::FETCH_ASSOC) as $lote) {
            if ($restante <= 0) break;
            $tomar = min($restante, floatval($lote['cantidad_disponible']));
            $pdo->prepare("UPDATE lotes_insumo SET cantidad_disponible = cantidad_disponible - ? WHERE id=?")->execute([$tomar, $lote['id']]);
            $costo = $tomar * floatval($lote['precio_unitario']);
            $pdo->prepare("INSERT INTO produccion_consumos (lote_produccion_id, insumo_id, lote_insumo_id, cantidad, costo) VALUES (?,?,?,?,?)")
                ->execute([$loteProduccionId, $insumoId, $lote['id'], $tomar, $costo]);
            $restante -= $tomar;
        }
        // si no alcanzó (o no había ningún lote de compra cargado), igual queda registrado el consumo,
        // usando el precio de referencia del insumo como costo (no hay compra real de la que tomarlo).
        if ($restante > 0.0001) {
            $ref = $pdo->prepare("SELECT precio_referencia FROM insumos WHERE id=?");
            $ref->execute([$insumoId]);
            $precioRef = floatval($ref->fetchColumn());
            $pdo->prepare("INSERT INTO produccion_consumos (lote_produccion_id, insumo_id, lote_insumo_id, cantidad, costo) VALUES (?,?,NULL,?,?)")
                ->execute([$loteProduccionId, $insumoId, $restante, $restante * $precioRef]);
        }
        $pdo->prepare("UPDATE insumos SET stock = stock - ? WHERE id=?")->execute([$cantidadNecesaria, $insumoId]);
        registrar_movimiento($pdo, 'insumo', $insumoId, 'produccion_consumo', -floatval($cantidadNecesaria), $referencia, $usr);
        return $restante; // >0 si faltó stock real (no cubierto por ningún lote)
    }

    function crear_ajuste($pdo, $concepto, $fecha, $usr){
        $ultimo = $pdo->query("SELECT MAX(numero_secuencial) FROM ajustes")->fetchColumn();
        $secuencial = ($ultimo ? intval($ultimo) : 0) + 1;
        $numero = str_pad($secuencial, 8, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO ajustes (numero_secuencial, numero, fecha, concepto, usuario) VALUES (?,?,?,?,?)")
            ->execute([$secuencial, $numero, $fecha, $concepto, $usr]);
        return $pdo->lastInsertId();
    }
    function agregar_item_ajuste($pdo, $ajusteId, $itemTipo, $itemId, $itemNombre, $cantidad, $saldoResultante){
        $pdo->prepare("INSERT INTO ajuste_items (ajuste_id, item_tipo, item_id, item_nombre, cantidad, saldo_resultante) VALUES (?,?,?,?,?,?)")
            ->execute([$ajusteId, $itemTipo, $itemId, $itemNombre, $cantidad, $saldoResultante]);
    }

    function registrar_movimiento($pdo, $itemTipo, $itemId, $tipoMovimiento, $cantidad, $referencia, $usr){
        $tabla = $itemTipo === 'producto' ? 'productos' : 'insumos';
        $stock = $pdo->prepare("SELECT stock FROM $tabla WHERE id=?");
        $stock->execute([$itemId]);
        $saldo = $stock->fetchColumn();
        $pdo->prepare("INSERT INTO stock_movimientos (item_tipo, item_id, fecha, tipo_movimiento, cantidad, saldo_resultante, referencia, usuario)
                       VALUES (?,?,CURDATE(),?,?,?,?,?)")
            ->execute([$itemTipo, $itemId, $tipoMovimiento, $cantidad, $saldo, $referencia, $usr]);
        return $saldo;
    }

    function entregar_barril_interno($pdo, $barrilId, $clienteId, $usr){
        $cliente = $pdo->prepare("SELECT nombre FROM clientes WHERE id=?");
        $cliente->execute([$clienteId]);
        $clienteNombre = $cliente->fetchColumn();
        $pdo->prepare("UPDATE barriles SET estado='entregado', cliente_id=?, fecha_entrega=CURDATE() WHERE id=?")
            ->execute([$clienteId, $barrilId]);
        $pdo->prepare("INSERT INTO barril_movimientos (barril_id, accion, cliente_id, cliente_nombre, fecha, usuario) VALUES (?, 'entregado', ?, ?, CURDATE(), ?)")
            ->execute([$barrilId, $clienteId, $clienteNombre, $usr]);
    }

    // ---------- lectura general ----------
    if ($action === 'get_data') {
        $clientes = rows($pdo, "SELECT id, nombre, razon_social AS razonSocial, cuit, direccion, localidad, provincia, cp, telefono, email FROM clientes ORDER BY nombre");
        $clienteContactos = rows($pdo, "SELECT id, cliente_id AS clienteId, nombre, telefono, mail FROM cliente_contactos ORDER BY id");
        foreach ($clientes as &$cl) { $cl['contactos'] = array_values(array_filter($clienteContactos, fn($c) => $c['clienteId'] === $cl['id'])); }
        unset($cl);

        $proveedores = rows($pdo, "SELECT id, nombre, cuit, contacto, telefono, email, direccion, localidad, provincia, cp FROM proveedores ORDER BY nombre");
        $proveedorContactos = rows($pdo, "SELECT id, proveedor_id AS proveedorId, nombre, telefono, mail FROM proveedor_contactos ORDER BY id");
        foreach ($proveedores as &$pv) { $pv['contactos'] = array_values(array_filter($proveedorContactos, fn($c) => $c['proveedorId'] === $pv['id'])); }
        unset($pv);

        $tiposInsumo = rows($pdo, "SELECT id, nombre FROM tipos_insumo ORDER BY nombre");
        $insumos = rows($pdo, "SELECT i.id, i.nombre, i.tipo_id AS tipoId, t.nombre AS tipoNombre, i.unidad, i.stock, i.stock_minimo AS stockMinimo, i.stock_seguridad AS stockSeguridad, i.precio_referencia AS precioReferencia
                                FROM insumos i LEFT JOIN tipos_insumo t ON t.id = i.tipo_id ORDER BY i.nombre");
        // valorización: último precio de compra de cada insumo, o el precio de referencia si nunca se compró
        foreach ($insumos as &$ins) {
            $ult = $pdo->prepare("SELECT ci.precio_unitario FROM compra_items ci WHERE ci.tipo='insumo' AND ci.insumo_id=? ORDER BY ci.id DESC LIMIT 1");
            $ult->execute([$ins['id']]);
            $ultimoPrecio = $ult->fetchColumn();
            $precioUsado = $ultimoPrecio ?: $ins['precioReferencia'];
            $ins['ultimoPrecio'] = $precioUsado ?: 0;
            $ins['valorStock'] = $precioUsado ? round($precioUsado * $ins['stock'], 2) : 0;
        }
        unset($ins);
        $productos = rows($pdo, "SELECT id, cod_prod AS codProd, nombre, tipo, unidad_medida AS unidadMedida, precio, stock, stock_minimo AS stockMinimo, stock_seguridad AS stockSeguridad FROM productos ORDER BY nombre");

        $barriles = rows($pdo, "SELECT b.id, b.descripcion, b.litros, b.estado, b.cliente_id AS clienteId, c.nombre AS clienteNombre, b.fecha_entrega AS fechaEntrega
                                 FROM barriles b LEFT JOIN clientes c ON c.id = b.cliente_id ORDER BY b.id");
        $movs = rows($pdo, "SELECT barril_id AS barrilId, accion, cliente_nombre AS clienteNombre, fecha FROM barril_movimientos ORDER BY id");
        foreach ($barriles as &$b) {
            $b['movimientos'] = array_values(array_filter($movs, fn($m) => $m['barrilId'] === $b['id']));
        }
        unset($b);

        $remitos = rows($pdo, "SELECT id, numero, fecha, cliente_id AS clienteId, cliente_nombre AS clienteNombre, tipo_venta AS tipoVenta, total, medio_pago AS medioPago, pagado, medio_cobro AS medioCobro, fecha_cobro AS fechaCobro
                                FROM remitos ORDER BY numero_secuencial DESC");
        $remBarriles = rows($pdo, "SELECT rb.remito_id AS remitoId, rb.barril_id AS barrilId, b.descripcion FROM remito_barriles rb LEFT JOIN barriles b ON b.id = rb.barril_id ORDER BY rb.id");
        foreach ($remitos as &$r) {
            $r['barriles'] = array_values(array_filter($remBarriles, fn($rb) => $rb['remitoId'] === $r['id']));
        }
        unset($r);
        $remItems = rows($pdo, "SELECT id, remito_id AS remitoId, tipo, producto_id AS productoId, barril_id AS barrilId, descripcion, cantidad, precio_unit AS precioUnit, subtotal FROM remito_items ORDER BY id");
        foreach ($remitos as &$r) {
            $r['items'] = array_values(array_filter($remItems, fn($it) => $it['remitoId'] === $r['id']));
        }
        unset($r);

        $compras = rows($pdo, "SELECT id, fecha, proveedor_id AS proveedorId, proveedor_nombre AS proveedorNombre, numero_documento AS numeroDocumento, total, medio_pago AS medioPago
                                 FROM compras ORDER BY fecha DESC, id DESC");
        $compraItems = rows($pdo, "SELECT id, compra_id AS compraId, tipo, insumo_id AS insumoId, producto_id AS productoId, descripcion, cantidad, unidad, precio_unitario AS precioUnitario, subtotal FROM compra_items ORDER BY id");
        foreach ($compras as &$cp) {
            $cp['items'] = array_values(array_filter($compraItems, fn($it) => $it['compraId'] === $cp['id']));
        }
        unset($cp);

        $mediosPago = rows($pdo, "SELECT id, nombre FROM medios_pago ORDER BY nombre");

        $ccPagos = rows($pdo, "SELECT id, cliente_id AS clienteId, fecha, monto, medio_pago AS medioPago FROM cc_pagos ORDER BY id DESC");

        $recetas = rows($pdo, "SELECT id, producto_id AS productoId, nombre, litros_base AS litrosBase FROM recetas ORDER BY nombre");
        $recetaItems = rows($pdo, "SELECT id, receta_id AS recetaId, insumo_id AS insumoId, cantidad FROM receta_items ORDER BY id");
        foreach ($recetas as &$rc) { $rc['items'] = array_values(array_filter($recetaItems, fn($it) => $it['recetaId'] === $rc['id'])); }
        unset($rc);

        $lotesProduccion = rows($pdo, "SELECT lp.id, lp.numero, lp.producto_id AS productoId, p.nombre AS productoNombre, lp.litros_objetivo AS litrosObjetivo,
                                        lp.litros_reales AS litrosReales, lp.fecha_coccion AS fechaCoccion, lp.fecha_fermentacion AS fechaFermentacion,
                                        lp.fecha_maduracion AS fechaMaduracion, lp.fecha_terminado AS fechaTerminado, lp.estado, lp.notas, lp.usuario
                                        FROM lotes_produccion lp LEFT JOIN productos p ON p.id = lp.producto_id ORDER BY lp.id DESC");
        $consumosProd = rows($pdo, "SELECT pc.lote_produccion_id AS loteProduccionId, pc.insumo_id AS insumoId, i.nombre AS insumoNombre, pc.cantidad, pc.costo
                                     FROM produccion_consumos pc LEFT JOIN insumos i ON i.id = pc.insumo_id ORDER BY pc.id");
        foreach ($lotesProduccion as &$lp) {
            $lp['consumos'] = array_values(array_filter($consumosProd, fn($c) => $c['loteProduccionId'] === $lp['id']));
            $lp['costoTotal'] = array_sum(array_column($lp['consumos'], 'costo'));
        }
        unset($lp);

        $categoriasGasto = rows($pdo, "SELECT id, nombre FROM categorias_gasto ORDER BY nombre");
        $gastos = rows($pdo, "SELECT g.id, g.fecha, g.categoria_id AS categoriaId, cg.nombre AS categoriaNombre, g.concepto, g.proveedor_id AS proveedorId, g.proveedor_nombre AS proveedorNombre, g.monto, g.medio_pago AS medioPago
                               FROM gastos g LEFT JOIN categorias_gasto cg ON cg.id = g.categoria_id ORDER BY g.fecha DESC, g.id DESC");

        respond(['ok' => true, 'data' => [
            'clientes' => $clientes, 'proveedores' => $proveedores, 'insumos' => $insumos, 'tiposInsumo' => $tiposInsumo,
            'productos' => $productos, 'barriles' => $barriles, 'remitos' => $remitos, 'compras' => $compras,
            'mediosPago' => $mediosPago, 'ccPagos' => $ccPagos, 'recetas' => $recetas, 'lotesProduccion' => $lotesProduccion,
            'categoriasGasto' => $categoriasGasto, 'gastos' => $gastos
        ]]);
    }

    // ---------- medios de pago ----------
    if ($action === 'add_medio_pago') {
        $nombre = trim($input['nombre'] ?? '');
        if ($nombre === '') respond(['ok' => false, 'error' => 'Ingresá un nombre.']);
        try {
            $pdo->prepare("INSERT INTO medios_pago (nombre) VALUES (?)")->execute([$nombre]);
        } catch (Exception $e) {
            respond(['ok' => false, 'error' => 'Ese medio de pago ya existe.']);
        }
        respond(['ok' => true]);
    }

    // ---------- clientes ----------
    if ($action === 'add_cliente') {
        $pdo->prepare("INSERT INTO clientes (nombre, razon_social, cuit, direccion, localidad, provincia, cp, telefono, email) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$input['nombre'], $input['razonSocial'] ?? '', $input['cuit'] ?? '', $input['direccion'] ?? '', $input['localidad'] ?? '', $input['provincia'] ?? '', $input['cp'] ?? '', $input['telefono'] ?? '', $input['email'] ?? '']);
        respond(['ok' => true, 'id' => $pdo->lastInsertId()]);
    }
    if ($action === 'editar_cliente') {
        $pdo->prepare("UPDATE clientes SET nombre=?, razon_social=?, cuit=?, direccion=?, localidad=?, provincia=?, cp=?, telefono=?, email=? WHERE id=?")
            ->execute([$input['nombre'], $input['razonSocial'] ?? '', $input['cuit'] ?? '', $input['direccion'] ?? '', $input['localidad'] ?? '', $input['provincia'] ?? '', $input['cp'] ?? '', $input['telefono'] ?? '', $input['email'] ?? '', $input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'add_contacto_cliente') {
        $pdo->prepare("INSERT INTO cliente_contactos (cliente_id, nombre, telefono, mail) VALUES (?,?,?,?)")
            ->execute([$input['clienteId'], $input['nombre'] ?? '', $input['telefono'] ?? '', $input['mail'] ?? '']);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_contacto_cliente') {
        $pdo->prepare("DELETE FROM cliente_contactos WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }

    // ---------- proveedores ----------
    if ($action === 'add_proveedor') {
        $pdo->prepare("INSERT INTO proveedores (nombre, cuit, contacto, telefono, email, direccion, localidad, provincia, cp) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$input['nombre'], $input['cuit'] ?? '', $input['contacto'] ?? '', $input['telefono'] ?? '', $input['email'] ?? '', $input['direccion'] ?? '', $input['localidad'] ?? '', $input['provincia'] ?? '', $input['cp'] ?? '']);
        respond(['ok' => true, 'id' => $pdo->lastInsertId()]);
    }
    if ($action === 'editar_proveedor') {
        $pdo->prepare("UPDATE proveedores SET nombre=?, cuit=?, contacto=?, telefono=?, email=?, direccion=?, localidad=?, provincia=?, cp=? WHERE id=?")
            ->execute([$input['nombre'], $input['cuit'] ?? '', $input['contacto'] ?? '', $input['telefono'] ?? '', $input['email'] ?? '', $input['direccion'] ?? '', $input['localidad'] ?? '', $input['provincia'] ?? '', $input['cp'] ?? '', $input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'add_contacto_proveedor') {
        $pdo->prepare("INSERT INTO proveedor_contactos (proveedor_id, nombre, telefono, mail) VALUES (?,?,?,?)")
            ->execute([$input['proveedorId'], $input['nombre'] ?? '', $input['telefono'] ?? '', $input['mail'] ?? '']);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_contacto_proveedor') {
        $pdo->prepare("DELETE FROM proveedor_contactos WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }

    // ---------- insumos ----------
    if ($action === 'add_insumo') {
        $pdo->prepare("INSERT INTO insumos (nombre, tipo_id, unidad, stock, stock_minimo, stock_seguridad, precio_referencia) VALUES (?,?,?,?,?,?,?)")
            ->execute([$input['nombre'], $input['tipoId'] ?: null, $input['unidad'] ?? 'unidad', $input['stock'] ?? 0, $input['stockMinimo'] ?? 0, $input['stockSeguridad'] ?? 0, $input['precioReferencia'] ?? 0]);
        respond(['ok' => true, 'id' => $pdo->lastInsertId()]);
    }
    if ($action === 'editar_insumo') {
        // el stock NO se edita nunca por acá — solo por Ajustes → Cargar ajuste manual, para que quede rastro
        $pdo->prepare("UPDATE insumos SET nombre=?, tipo_id=?, unidad=?, stock_minimo=?, stock_seguridad=?, precio_referencia=? WHERE id=?")
            ->execute([$input['nombre'], $input['tipoId'] ?: null, $input['unidad'], $input['stockMinimo'], $input['stockSeguridad'] ?? 0, $input['precioReferencia'] ?? 0, $input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'add_tipo_insumo') {
        $nombre = trim($input['nombre'] ?? '');
        if ($nombre === '') respond(['ok' => false, 'error' => 'Ingresá un nombre.']);
        try {
            $pdo->prepare("INSERT INTO tipos_insumo (nombre) VALUES (?)")->execute([$nombre]);
        } catch (Exception $e) {
            respond(['ok' => false, 'error' => 'Ese tipo de insumo ya existe.']);
        }
        respond(['ok' => true]);
    }

    // ---------- productos ----------
    if ($action === 'add_producto') {
        $pdo->prepare("INSERT INTO productos (cod_prod, nombre, tipo, unidad_medida, precio, stock, stock_minimo, stock_seguridad) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$input['codProd'] ?? '', $input['nombre'], $input['tipo'], $input['unidadMedida'] ?? 'unidades', $input['precio'], $input['stock'] ?? 0, $input['stockMinimo'] ?? 0, $input['stockSeguridad'] ?? 0]);
        respond(['ok' => true, 'id' => $pdo->lastInsertId()]);
    }
    if ($action === 'editar_producto') {
        $pdo->prepare("UPDATE productos SET cod_prod=?, nombre=?, tipo=?, unidad_medida=?, precio=?, stock=?, stock_minimo=?, stock_seguridad=? WHERE id=?")
            ->execute([$input['codProd'] ?? '', $input['nombre'], $input['tipo'], $input['unidadMedida'] ?? 'unidades', $input['precio'], $input['stock'], $input['stockMinimo'] ?? 0, $input['stockSeguridad'] ?? 0, $input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'ajustar_stock_producto') {
        $pdo->prepare("UPDATE productos SET stock = GREATEST(0, stock + ?) WHERE id=?")
            ->execute([$input['delta'], $input['id']]);
        $saldo = registrar_movimiento($pdo, 'producto', $input['id'], 'ajuste', $input['delta'], 'Ajuste manual (+1/-1)', $usr);
        $nombreProd = $pdo->prepare("SELECT nombre FROM productos WHERE id=?");
        $nombreProd->execute([$input['id']]);
        $ajusteId = crear_ajuste($pdo, 'Ajuste manual de stock (+1/-1)', date('Y-m-d'), $usr);
        agregar_item_ajuste($pdo, $ajusteId, 'producto', $input['id'], $nombreProd->fetchColumn(), floatval($input['delta']), $saldo);
        respond(['ok' => true]);
    }

    // ---------- barriles ----------
    if ($action === 'add_barril') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM barriles WHERE id=?");
        $chk->execute([$input['id']]);
        if ($chk->fetchColumn() > 0) respond(['ok' => false, 'error' => 'Ya existe un barril con ese ID.']);
        $pdo->prepare("INSERT INTO barriles (id, descripcion, litros, estado) VALUES (?,?,?, 'planta')")
            ->execute([$input['id'], $input['descripcion'] ?? '', $input['litros']]);
        respond(['ok' => true]);
    }
    if ($action === 'editar_barril') {
        $oldId = $input['id'];
        $nuevoId = trim($input['nuevoId'] ?? $oldId);
        if ($nuevoId !== $oldId) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM barriles WHERE id=?");
            $chk->execute([$nuevoId]);
            if ($chk->fetchColumn() > 0) respond(['ok' => false, 'error' => 'Ya existe un barril con ese ID.']);
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO barriles (id, descripcion, litros, estado, cliente_id, fecha_entrega)
                               SELECT ?, ?, ?, estado, cliente_id, fecha_entrega FROM barriles WHERE id=?")
                    ->execute([$nuevoId, $input['descripcion'] ?? '', $input['litros'], $oldId]);
                $pdo->prepare("UPDATE barril_movimientos SET barril_id=? WHERE barril_id=?")->execute([$nuevoId, $oldId]);
                $pdo->prepare("UPDATE remito_items SET barril_id=? WHERE barril_id=?")->execute([$nuevoId, $oldId]);
                $pdo->prepare("UPDATE remitos SET barril_id=? WHERE barril_id=?")->execute([$nuevoId, $oldId]);
                $pdo->prepare("DELETE FROM barriles WHERE id=?")->execute([$oldId]);
                $pdo->commit();
                respond(['ok' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'No se pudo renombrar: ' . $e->getMessage()]);
            }
        }
        $pdo->prepare("UPDATE barriles SET descripcion=?, litros=? WHERE id=?")
            ->execute([$input['descripcion'] ?? '', $input['litros'], $oldId]);
        respond(['ok' => true]);
    }
    if ($action === 'entregar_barril') {
        entregar_barril_interno($pdo, $input['id'], $input['clienteId'], $usr);
        respond(['ok' => true]);
    }
    if ($action === 'devolver_barril') {
        $b = $pdo->prepare("SELECT cliente_id, (SELECT nombre FROM clientes WHERE id = barriles.cliente_id) AS cliente_nombre FROM barriles WHERE id=?");
        $b->execute([$input['id']]);
        $row = $b->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO barril_movimientos (barril_id, accion, cliente_id, cliente_nombre, fecha, usuario) VALUES (?, 'devuelto', ?, ?, CURDATE(), ?)")
            ->execute([$input['id'], $row['cliente_id'] ?? null, $row['cliente_nombre'] ?? '', $usr]);
        $pdo->prepare("UPDATE barriles SET estado='planta', cliente_id=NULL, fecha_entrega=NULL WHERE id=?")
            ->execute([$input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_barril') {
        $pdo->prepare("DELETE FROM barriles WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }

    // ---------- remitos ----------
    if ($action === 'crear_remito') {
        $items = $input['items'] ?? [];
        if (empty($items)) respond(['ok' => false, 'error' => 'El remito necesita al menos un renglón.']);

        $clienteId = $input['clienteId'] ?? null;
        if (empty($clienteId)) respond(['ok' => false, 'error' => 'Elegí un cliente.']);

        $tipoVenta = $input['tipoVenta'] ?? '';
        if (!in_array($tipoVenta, ['granel','recarga','varios'])) respond(['ok' => false, 'error' => 'Elegí el tipo de venta.']);

        $barrilIds = $input['barrilIds'] ?? [];
        if ($tipoVenta === 'granel' && empty($barrilIds)) respond(['ok' => false, 'error' => 'La venta a granel necesita que asignes al menos un barril.']);
        if ($tipoVenta !== 'granel') $barrilIds = [];

        $c = $pdo->prepare("SELECT nombre FROM clientes WHERE id=?");
        $c->execute([$clienteId]);
        $clienteNombre = $c->fetchColumn() ?: 'Consumidor final';

        $pdo->beginTransaction();
        try {
            // validar stock disponible antes de descontar nada
            foreach ($items as $it) {
                if (!empty($it['productoId'])) {
                    $chk = $pdo->prepare("SELECT nombre, stock FROM productos WHERE id=?");
                    $chk->execute([$it['productoId']]);
                    $prodRow = $chk->fetch(PDO::FETCH_ASSOC);
                    if ($prodRow && floatval($prodRow['stock']) < floatval($it['cantidad'])) {
                        $pdo->rollBack();
                        respond(['ok' => false, 'error' => 'No hay stock suficiente de "'.$prodRow['nombre'].'" (disponible: '.$prodRow['stock'].', pedido: '.$it['cantidad'].').']);
                    }
                }
            }

            $puntoVenta = 1;
            $ultimo = $pdo->query("SELECT MAX(numero_secuencial) FROM remitos")->fetchColumn();
            $secuencial = ($ultimo ? intval($ultimo) : 0) + 1;
            $numero = sprintf('%04d-%08d', $puntoVenta, $secuencial);

            $total = 0;
            foreach ($items as $it) { $total += floatval($it['cantidad']) * floatval($it['precioUnit']); }

            $pdo->prepare("INSERT INTO remitos (numero, punto_venta, numero_secuencial, fecha, cliente_id, cliente_nombre, tipo_venta, barril_id, total, medio_pago, pagado, usuario)
                           VALUES (?,?,?,?,?,?,?,NULL,?,?,?,?)")
                ->execute([$numero, $puntoVenta, $secuencial, $input['fecha'] ?: date('Y-m-d'), $clienteId, $clienteNombre, $tipoVenta, $total, $input['medioPago'], ($input['medioPago']==='Cuenta Corriente'?0:1), $usr]);
            $remitoId = $pdo->lastInsertId();

            foreach ($items as $it) {
                $subtotal = floatval($it['cantidad']) * floatval($it['precioUnit']);
                $pdo->prepare("INSERT INTO remito_items (remito_id, tipo, producto_id, barril_id, descripcion, cantidad, precio_unit, subtotal)
                               VALUES (?, 'producto', ?, NULL, ?, ?, ?, ?)")
                    ->execute([$remitoId, $it['productoId'] ?? null, $it['descripcion'], $it['cantidad'], $it['precioUnit'], $subtotal]);

                if (!empty($it['productoId'])) {
                    $pdo->prepare("UPDATE productos SET stock = GREATEST(0, stock - ?) WHERE id=?")
                        ->execute([$it['cantidad'], $it['productoId']]);
                    registrar_movimiento($pdo, 'producto', $it['productoId'], 'venta', -floatval($it['cantidad']), 'Remito '.$numero, $usr);
                }
            }

            foreach ($barrilIds as $bId) {
                $pdo->prepare("INSERT INTO remito_barriles (remito_id, barril_id) VALUES (?,?)")->execute([$remitoId, $bId]);
                entregar_barril_interno($pdo, $bId, $clienteId, $usr);
            }

            $pdo->commit();
            respond(['ok' => true, 'remitoId' => $remitoId, 'numero' => $numero]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo generar el remito: ' . $e->getMessage()]);
        }
    }

    // ---------- compras ----------
    if ($action === 'crear_compra') {
        $items = $input['items'] ?? [];
        if (empty($items)) respond(['ok' => false, 'error' => 'La compra necesita al menos un renglón.']);

        $proveedorId = $input['proveedorId'] ?? null;
        $proveedorNombre = '';
        if ($proveedorId) {
            $p = $pdo->prepare("SELECT nombre FROM proveedores WHERE id=?");
            $p->execute([$proveedorId]);
            $proveedorNombre = $p->fetchColumn() ?: '';
        }

        $pdo->beginTransaction();
        try {
            $total = 0;
            foreach ($items as $it) { $total += floatval($it['cantidad']) * floatval($it['precioUnitario']); }

            $pdo->prepare("INSERT INTO compras (fecha, proveedor_id, proveedor_nombre, numero_documento, total, medio_pago, usuario)
                           VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$input['fecha'] ?: date('Y-m-d'), $proveedorId, $proveedorNombre, $input['numeroDocumento'] ?? '', $total, $input['medioPago'], $usr]);
            $compraId = $pdo->lastInsertId();

            foreach ($items as $it) {
                $subtotal = floatval($it['cantidad']) * floatval($it['precioUnitario']);
                $pdo->prepare("INSERT INTO compra_items (compra_id, tipo, insumo_id, producto_id, descripcion, cantidad, unidad, precio_unitario, subtotal)
                               VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$compraId, $it['tipo'], $it['tipo']==='insumo' ? ($it['itemId'] ?? null) : null, $it['tipo']==='producto' ? ($it['itemId'] ?? null) : null,
                               $it['descripcion'], $it['cantidad'], $it['unidad'] ?? '', $it['precioUnitario'], $subtotal]);
                $compraItemId = $pdo->lastInsertId();

                $refCompra = 'Compra #'.$compraId.(!empty($input['numeroDocumento']) ? ' ('.$input['numeroDocumento'].')' : '');
                if ($it['tipo'] === 'insumo' && !empty($it['itemId'])) {
                    $pdo->prepare("UPDATE insumos SET stock = stock + ? WHERE id=?")->execute([$it['cantidad'], $it['itemId']]);
                    $pdo->prepare("INSERT INTO lotes_insumo (insumo_id, compra_item_id, fecha_ingreso, cantidad_inicial, cantidad_disponible, precio_unitario, origen) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$it['itemId'], $compraItemId, $input['fecha'] ?: date('Y-m-d'), $it['cantidad'], $it['cantidad'], $it['precioUnitario'], $refCompra]);
                    registrar_movimiento($pdo, 'insumo', $it['itemId'], 'compra', floatval($it['cantidad']), $refCompra, $usr);
                }
                if ($it['tipo'] === 'producto' && !empty($it['itemId'])) {
                    $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE id=?")->execute([$it['cantidad'], $it['itemId']]);
                    registrar_movimiento($pdo, 'producto', $it['itemId'], 'compra', floatval($it['cantidad']), $refCompra, $usr);
                }
            }

            $pdo->commit();
            respond(['ok' => true, 'compraId' => $compraId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo registrar la compra: ' . $e->getMessage()]);
        }
    }

    if ($action === 'editar_compra') {
        $proveedorId = $input['proveedorId'] ?? null;
        $proveedorNombre = '';
        if ($proveedorId) {
            $p = $pdo->prepare("SELECT nombre FROM proveedores WHERE id=?");
            $p->execute([$proveedorId]);
            $proveedorNombre = $p->fetchColumn() ?: '';
        }

        $pdo->beginTransaction();
        try {
            $items = $input['items'] ?? [];
            foreach ($items as $it) {
                $old = $pdo->prepare("SELECT * FROM compra_items WHERE id=?");
                $old->execute([$it['id']]);
                $row = $old->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;

                $nuevaCantidad = floatval($it['cantidad']);
                $nuevoPrecio = floatval($it['precioUnitario']);
                $nuevoSubtotal = $nuevaCantidad * $nuevoPrecio;
                $deltaCantidad = $nuevaCantidad - floatval($row['cantidad']);

                if ($deltaCantidad != 0) {
                    if ($row['tipo'] === 'insumo' && $row['insumo_id']) {
                        $stockAct = $pdo->prepare("SELECT nombre, stock FROM insumos WHERE id=?");
                        $stockAct->execute([$row['insumo_id']]);
                        $insRow = $stockAct->fetch(PDO::FETCH_ASSOC);
                        if ($insRow && (floatval($insRow['stock']) + $deltaCantidad) < 0) {
                            $pdo->rollBack();
                            respond(['ok' => false, 'error' => 'Ese cambio dejaría el stock de "'.$insRow['nombre'].'" en negativo. No se puede aplicar.']);
                        }
                        $pdo->prepare("UPDATE insumos SET stock = stock + ? WHERE id=?")->execute([$deltaCantidad, $row['insumo_id']]);
                        registrar_movimiento($pdo, 'insumo', $row['insumo_id'], 'edicion', $deltaCantidad, 'Edición de compra #'.$input['id'], $usr);
                    }
                    if ($row['tipo'] === 'producto' && $row['producto_id']) {
                        $stockAct = $pdo->prepare("SELECT nombre, stock FROM productos WHERE id=?");
                        $stockAct->execute([$row['producto_id']]);
                        $prodRow = $stockAct->fetch(PDO::FETCH_ASSOC);
                        if ($prodRow && (floatval($prodRow['stock']) + $deltaCantidad) < 0) {
                            $pdo->rollBack();
                            respond(['ok' => false, 'error' => 'Ese cambio dejaría el stock de "'.$prodRow['nombre'].'" en negativo. No se puede aplicar.']);
                        }
                        $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE id=?")->execute([$deltaCantidad, $row['producto_id']]);
                        registrar_movimiento($pdo, 'producto', $row['producto_id'], 'edicion', $deltaCantidad, 'Edición de compra #'.$input['id'], $usr);
                    }
                }

                $pdo->prepare("UPDATE compra_items SET cantidad=?, precio_unitario=?, subtotal=? WHERE id=?")
                    ->execute([$nuevaCantidad, $nuevoPrecio, $nuevoSubtotal, $it['id']]);
            }

            $sumaTotal = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM compra_items WHERE compra_id=?");
            $sumaTotal->execute([$input['id']]);
            $nuevoTotal = $sumaTotal->fetchColumn();

            $pdo->prepare("UPDATE compras SET fecha=?, proveedor_id=?, proveedor_nombre=?, numero_documento=?, medio_pago=?, total=? WHERE id=?")
                ->execute([$input['fecha'], $proveedorId, $proveedorNombre, $input['numeroDocumento'] ?? '', $input['medioPago'], $nuevoTotal, $input['id']]);

            $pdo->commit();
            respond(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo actualizar: ' . $e->getMessage()]);
        }
    }

    if ($action === 'eliminar_remito') {
        $rid = $input['id'];
        $pdo->beginTransaction();
        try {
            $numRow = $pdo->prepare("SELECT numero FROM remitos WHERE id=?");
            $numRow->execute([$rid]);
            $numeroRemito = $numRow->fetchColumn() ?: ('#'.$rid);

            $items = rows($pdo, "SELECT * FROM remito_items WHERE remito_id=?", [$rid]);
            $refRemito = 'Anulación remito '.$numeroRemito;
            $huboItems = false;
            $ajusteId = null;
            foreach ($items as $it) {
                if ($it['producto_id']) {
                    if (!$ajusteId) $ajusteId = crear_ajuste($pdo, $refRemito, date('Y-m-d'), $usr);
                    $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE id=?")->execute([$it['cantidad'], $it['producto_id']]);
                    $saldo = registrar_movimiento($pdo, 'producto', $it['producto_id'], 'anulacion_venta', floatval($it['cantidad']), $refRemito, $usr);
                    agregar_item_ajuste($pdo, $ajusteId, 'producto', $it['producto_id'], $it['descripcion'], floatval($it['cantidad']), $saldo);
                }
            }
            $barriles = rows($pdo, "SELECT barril_id FROM remito_barriles WHERE remito_id=?", [$rid]);
            foreach ($barriles as $b) {
                $pdo->prepare("UPDATE barriles SET estado='planta', cliente_id=NULL, fecha_entrega=NULL WHERE id=?")->execute([$b['barril_id']]);
            }
            $pdo->prepare("DELETE FROM remitos WHERE id=?")->execute([$rid]);
            $pdo->commit();
            respond(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo eliminar: ' . $e->getMessage()]);
        }
    }

    if ($action === 'editar_remito') {
        $tipoVenta = $input['tipoVenta'] ?? '';
        if (!in_array($tipoVenta, ['granel','recarga','varios'])) respond(['ok' => false, 'error' => 'Elegí el tipo de venta.']);
        $pdo->prepare("UPDATE remitos SET tipo_venta=?, fecha=?, medio_pago=? WHERE id=?")
            ->execute([$tipoVenta, $input['fecha'], $input['medioPago'], $input['id']]);
        respond(['ok' => true]);
    }

    if ($action === 'registrar_pago_cc') {
        $clienteId = $input['clienteId'] ?? null;
        if (empty($clienteId)) respond(['ok' => false, 'error' => 'Falta el cliente.']);
        $monto = floatval($input['monto'] ?? 0);
        if ($monto <= 0) respond(['ok' => false, 'error' => 'El monto tiene que ser mayor a cero.']);
        $pdo->prepare("INSERT INTO cc_pagos (cliente_id, fecha, monto, medio_pago, usuario) VALUES (?,?,?,?,?)")
            ->execute([$clienteId, $input['fecha'] ?: date('Y-m-d'), $monto, $input['medioPago'], $usr]);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_pago_cc') {
        $pdo->prepare("DELETE FROM cc_pagos WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }

    if ($action === 'get_kardex') {
        $movs = rows($pdo, "SELECT fecha, tipo_movimiento AS tipoMovimiento, cantidad, saldo_resultante AS saldoResultante, referencia, usuario
                             FROM stock_movimientos WHERE item_tipo=? AND item_id=? ORDER BY id DESC",
                     [$input['itemTipo'], $input['itemId']]);
        respond(['ok' => true, 'movimientos' => $movs]);
    }

    // ---------- recetas ----------
    if ($action === 'add_receta') {
        $items = $input['items'] ?? [];
        if (empty($items)) respond(['ok' => false, 'error' => 'Agregá al menos un insumo a la receta.']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO recetas (producto_id, nombre, litros_base) VALUES (?,?,?)")
                ->execute([$input['productoId'], $input['nombre'], $input['litrosBase']]);
            $recetaId = $pdo->lastInsertId();
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO receta_items (receta_id, insumo_id, cantidad) VALUES (?,?,?)")
                    ->execute([$recetaId, $it['insumoId'], $it['cantidad']]);
            }
            $pdo->commit();
            respond(['ok' => true, 'id' => $recetaId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo guardar la receta: ' . $e->getMessage()]);
        }
    }
    if ($action === 'eliminar_receta') {
        $pdo->prepare("DELETE FROM recetas WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'editar_receta') {
        $items = $input['items'] ?? [];
        if (empty($items)) respond(['ok' => false, 'error' => 'Agregá al menos un insumo a la receta.']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE recetas SET producto_id=?, nombre=?, litros_base=? WHERE id=?")
                ->execute([$input['productoId'], $input['nombre'], $input['litrosBase'], $input['id']]);
            $pdo->prepare("DELETE FROM receta_items WHERE receta_id=?")->execute([$input['id']]);
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO receta_items (receta_id, insumo_id, cantidad) VALUES (?,?,?)")
                    ->execute([$input['id'], $it['insumoId'], $it['cantidad']]);
            }
            $pdo->commit();
            respond(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo actualizar la receta: ' . $e->getMessage()]);
        }
    }

    // ---------- producción ----------
    if ($action === 'crear_lote_produccion') {
        $items = $input['consumos'] ?? [];
        $productoId = $input['productoId'] ?? null;
        $litrosObjetivo = floatval($input['litrosObjetivo'] ?? 0);
        if (!$productoId || $litrosObjetivo <= 0) respond(['ok' => false, 'error' => 'Elegí la cerveza y los litros objetivo.']);

        // validar stock suficiente de cada insumo ANTES de tocar nada — nunca se permite quedar en negativo
        foreach ($items as $it) {
            $chk = $pdo->prepare("SELECT nombre, stock FROM insumos WHERE id=?");
            $chk->execute([$it['insumoId']]);
            $insRow = $chk->fetch(PDO::FETCH_ASSOC);
            if ($insRow && floatval($insRow['stock']) < floatval($it['cantidad'])) {
                respond(['ok' => false, 'error' => 'No hay stock suficiente de "'.$insRow['nombre'].'" (disponible: '.$insRow['stock'].', necesario: '.$it['cantidad'].').']);
            }
        }

        $pdo->beginTransaction();
        try {
            $ultimo = $pdo->query("SELECT COUNT(*) FROM lotes_produccion")->fetchColumn();
            $numero = 'LP-' . str_pad($ultimo + 1, 4, '0', STR_PAD_LEFT);
            $fecha = $input['fecha'] ?: date('Y-m-d');

            $pdo->prepare("INSERT INTO lotes_produccion (numero, producto_id, litros_objetivo, fecha_coccion, estado, notas, usuario)
                           VALUES (?,?,?,?,'en_coccion',?,?)")
                ->execute([$numero, $productoId, $litrosObjetivo, $fecha, $input['notas'] ?? '', $usr]);
            $loteId = $pdo->lastInsertId();

            foreach ($items as $it) {
                consumir_insumo_fifo($pdo, $it['insumoId'], $it['cantidad'], $loteId, $usr, 'Lote de producción '.$numero);
            }

            $pdo->commit();
            respond(['ok' => true, 'id' => $loteId, 'numero' => $numero]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo crear el lote: ' . $e->getMessage()]);
        }
    }
    if ($action === 'pasar_a_fermentacion') {
        $pdo->prepare("UPDATE lotes_produccion SET estado='fermentando', fecha_fermentacion=? WHERE id=?")
            ->execute([$input['fecha'] ?: date('Y-m-d'), $input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'pasar_a_maduracion') {
        $pdo->prepare("UPDATE lotes_produccion SET estado='madurando', fecha_maduracion=? WHERE id=?")
            ->execute([$input['fecha'] ?: date('Y-m-d'), $input['id']]);
        respond(['ok' => true]);
    }
    if ($action === 'marcar_listo') {
        $litrosReales = floatval($input['litrosReales']);
        $lote = $pdo->prepare("SELECT * FROM lotes_produccion WHERE id=?");
        $lote->execute([$input['id']]);
        $loteRow = $lote->fetch(PDO::FETCH_ASSOC);
        if (!$loteRow) respond(['ok' => false, 'error' => 'Lote no encontrado.']);

        $pdo->prepare("UPDATE lotes_produccion SET estado='terminado', fecha_terminado=?, litros_reales=? WHERE id=?")
            ->execute([$input['fecha'] ?: date('Y-m-d'), $litrosReales, $input['id']]);
        $pdo->prepare("UPDATE productos SET stock = stock + ? WHERE id=?")->execute([$litrosReales, $loteRow['producto_id']]);
        registrar_movimiento($pdo, 'producto', $loteRow['producto_id'], 'produccion', $litrosReales, 'Lote de producción '.$loteRow['numero'], $usr);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_lote_produccion') {
        // solo se permite eliminar lotes que todavía no llegaron a stock (en_coccion o fermentando)
        $lote = $pdo->prepare("SELECT * FROM lotes_produccion WHERE id=?");
        $lote->execute([$input['id']]);
        $loteRow = $lote->fetch(PDO::FETCH_ASSOC);
        if (!$loteRow) respond(['ok' => false, 'error' => 'Lote no encontrado.']);
        if ($loteRow['estado'] === 'terminado') respond(['ok' => false, 'error' => 'No se puede eliminar un lote que ya pasó a stock.']);

        $pdo->beginTransaction();
        try {
            $consumos = rows($pdo, "SELECT * FROM produccion_consumos WHERE lote_produccion_id=?", [$input['id']]);
            $refLote = 'Anulación lote '.$loteRow['numero'];
            $ajusteId = crear_ajuste($pdo, $refLote, date('Y-m-d'), $usr);
            foreach ($consumos as $c) {
                if ($c['lote_insumo_id']) {
                    $pdo->prepare("UPDATE lotes_insumo SET cantidad_disponible = cantidad_disponible + ? WHERE id=?")->execute([$c['cantidad'], $c['lote_insumo_id']]);
                }
                $pdo->prepare("UPDATE insumos SET stock = stock + ? WHERE id=?")->execute([$c['cantidad'], $c['insumo_id']]);
                $saldo = registrar_movimiento($pdo, 'insumo', $c['insumo_id'], 'produccion_consumo', floatval($c['cantidad']), $refLote, $usr);
                $nombreIns = $pdo->prepare("SELECT nombre FROM insumos WHERE id=?");
                $nombreIns->execute([$c['insumo_id']]);
                agregar_item_ajuste($pdo, $ajusteId, 'insumo', $c['insumo_id'], $nombreIns->fetchColumn(), floatval($c['cantidad']), $saldo);
            }
            $pdo->prepare("DELETE FROM lotes_produccion WHERE id=?")->execute([$input['id']]);
            $pdo->commit();
            respond(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo eliminar: ' . $e->getMessage()]);
        }
    }

    if ($action === 'eliminar_compra') {
        $cid = $input['id'];
        $pdo->beginTransaction();
        try {
            $numRow = $pdo->prepare("SELECT numero_documento FROM compras WHERE id=?");
            $numRow->execute([$cid]);
            $numDoc = $numRow->fetchColumn();
            $refCompra = 'Anulación compra #'.$cid.($numDoc ? ' ('.$numDoc.')' : '');

            $items = rows($pdo, "SELECT * FROM compra_items WHERE compra_id=?", [$cid]);
            $ajusteId = crear_ajuste($pdo, $refCompra, date('Y-m-d'), $usr);
            foreach ($items as $it) {
                if ($it['tipo'] === 'insumo' && $it['insumo_id']) {
                    $pdo->prepare("UPDATE insumos SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$it['cantidad'], $it['insumo_id']]);
                    $pdo->prepare("DELETE FROM lotes_insumo WHERE compra_item_id=?")->execute([$it['id']]);
                    $saldo = registrar_movimiento($pdo, 'insumo', $it['insumo_id'], 'anulacion_compra', -floatval($it['cantidad']), $refCompra, $usr);
                    agregar_item_ajuste($pdo, $ajusteId, 'insumo', $it['insumo_id'], $it['descripcion'], -floatval($it['cantidad']), $saldo);
                }
                if ($it['tipo'] === 'producto' && $it['producto_id']) {
                    $pdo->prepare("UPDATE productos SET stock = GREATEST(0, stock - ?) WHERE id=?")->execute([$it['cantidad'], $it['producto_id']]);
                    $saldo = registrar_movimiento($pdo, 'producto', $it['producto_id'], 'anulacion_compra', -floatval($it['cantidad']), $refCompra, $usr);
                    agregar_item_ajuste($pdo, $ajusteId, 'producto', $it['producto_id'], $it['descripcion'], -floatval($it['cantidad']), $saldo);
                }
            }
            $pdo->prepare("DELETE FROM compras WHERE id=?")->execute([$cid]);
            $pdo->commit();
            respond(['ok' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo eliminar: ' . $e->getMessage()]);
        }
    }

    if ($action === 'get_ajustes') {
        $ajustes = rows($pdo, "SELECT id, numero, fecha, concepto, usuario FROM ajustes ORDER BY numero_secuencial DESC");
        $items = rows($pdo, "SELECT ajuste_id AS ajusteId, item_tipo AS itemTipo, item_id AS itemId, item_nombre AS itemNombre, cantidad, saldo_resultante AS saldoResultante FROM ajuste_items ORDER BY id");
        foreach ($ajustes as &$aj) {
            $aj['items'] = array_values(array_filter($items, fn($it) => $it['ajusteId'] === $aj['id']));
        }
        unset($aj);
        respond(['ok' => true, 'ajustes' => $ajustes]);
    }

    if ($action === 'crear_ajuste_manual') {
        $items = $input['items'] ?? [];
        if (empty($items)) respond(['ok' => false, 'error' => 'Agregá al menos un ítem.']);
        foreach ($items as $it) {
            if (floatval($it['stockCorrecto']) < 0) respond(['ok' => false, 'error' => 'El stock correcto no puede ser negativo.']);
        }

        $pdo->beginTransaction();
        try {
            $fecha = $input['fecha'] ?: date('Y-m-d');
            $concepto = $input['concepto'] ?: 'Ajuste manual de stock';
            $ajusteId = crear_ajuste($pdo, $concepto, $fecha, $usr);

            foreach ($items as $it) {
                $tabla = $it['itemTipo'] === 'producto' ? 'productos' : 'insumos';
                $actual = $pdo->prepare("SELECT nombre, stock FROM $tabla WHERE id=?");
                $actual->execute([$it['itemId']]);
                $row = $actual->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;

                $nuevoStock = floatval($it['stockCorrecto']);
                $delta = $nuevoStock - floatval($row['stock']);

                $pdo->prepare("UPDATE $tabla SET stock=? WHERE id=?")->execute([$nuevoStock, $it['itemId']]);
                registrar_movimiento($pdo, $it['itemTipo'], $it['itemId'], 'ajuste', $delta, $concepto, $usr);
                agregar_item_ajuste($pdo, $ajusteId, $it['itemTipo'], $it['itemId'], $row['nombre'], $delta, $nuevoStock);

                if ($it['itemTipo'] === 'insumo') {
                    $pdo->prepare("DELETE FROM lotes_insumo WHERE insumo_id=?")->execute([$it['itemId']]);
                    if ($nuevoStock > 0) {
                        $precio = !empty($it['precio']) ? floatval($it['precio']) : 0;
                        if (!$precio) {
                            $ref = $pdo->prepare("SELECT precio_referencia FROM insumos WHERE id=?");
                            $ref->execute([$it['itemId']]);
                            $precio = floatval($ref->fetchColumn());
                        }
                        $pdo->prepare("INSERT INTO lotes_insumo (insumo_id, fecha_ingreso, cantidad_inicial, cantidad_disponible, precio_unitario, origen) VALUES (?,?,?,?,?,?)")
                            ->execute([$it['itemId'], $fecha, $nuevoStock, $nuevoStock, $precio, $concepto]);
                    }
                }
            }

            $pdo->commit();
            respond(['ok' => true, 'numero' => $pdo->query("SELECT numero FROM ajustes WHERE id=".intval($ajusteId))->fetchColumn()]);
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['ok' => false, 'error' => 'No se pudo registrar el ajuste: ' . $e->getMessage()]);
        }
    }

    if ($action === 'add_categoria_gasto') {
        $nombre = trim($input['nombre'] ?? '');
        if ($nombre === '') respond(['ok' => false, 'error' => 'Ingresá un nombre.']);
        try {
            $pdo->prepare("INSERT INTO categorias_gasto (nombre) VALUES (?)")->execute([$nombre]);
        } catch (Exception $e) {
            respond(['ok' => false, 'error' => 'Esa categoría ya existe.']);
        }
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_categoria_gasto') {
        $pdo->prepare("DELETE FROM categorias_gasto WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }

    if ($action === 'add_gasto') {
        $proveedorId = $input['proveedorId'] ?? null;
        $proveedorNombre = '';
        if ($proveedorId) {
            $p = $pdo->prepare("SELECT nombre FROM proveedores WHERE id=?");
            $p->execute([$proveedorId]);
            $proveedorNombre = $p->fetchColumn() ?: '';
        }
        $pdo->prepare("INSERT INTO gastos (fecha, categoria_id, concepto, proveedor_id, proveedor_nombre, monto, medio_pago, usuario) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$input['fecha'] ?: date('Y-m-d'), $input['categoriaId'] ?: null, $input['concepto'], $proveedorId, $proveedorNombre, $input['monto'], $input['medioPago'], $usr]);
        respond(['ok' => true]);
    }
    if ($action === 'eliminar_gasto') {
        $pdo->prepare("DELETE FROM gastos WHERE id=?")->execute([$input['id']]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Acción desconocida.']);

} catch (Exception $e) {
    http_response_code(500);
    respond(['ok' => false, 'error' => 'Error de servidor: ' . $e->getMessage()]);
}
