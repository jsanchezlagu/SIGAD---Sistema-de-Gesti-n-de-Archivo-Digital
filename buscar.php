<?php
require_once 'config/config.php';
requiere_login();
$db = db();

$q    = trim((string)($_GET['q'] ?? ''));
$pab  = trim((string)($_GET['pab'] ?? ''));
$anio = trim((string)($_GET['anio'] ?? ''));
$pag  = (int)($_GET['pag'] ?? 1);
$hayFiltro = ($q !== '' || $pab !== '' || $anio !== '');
$res = [];
$total = 0;
$porPagina = 25;

if ($hayFiltro) {
    [$res, $total] = buscar_expedientes($db, $q, $pab, $anio, es_consulta(), $pag, $porPagina);
    if ($q !== '' && !empty($res)) {
        $insB = $db->prepare("INSERT INTO busquedas (expediente_id, termino, usuario_id) VALUES (?,?,?)");
        $insB->execute([$res[0]['id'] ?? null, mb_substr($q, 0, 255), $_SESSION['uid'] ?? null]);
    }
}

$paginas = $porPagina > 0 ? (int)ceil($total / $porPagina) : 1;
$pabs = $db->query("SELECT codigo, nombre FROM pabellones ORDER BY codigo")->fetchAll();
$anios = $db->query("SELECT DISTINCT anio FROM expedientes ORDER BY anio DESC")->fetchAll();

$etiquetaOrigen = [
    'código'    => 'Coincidencia en CUI / N° expediente',
    'proyecto'  => 'Coincidencia en nombre del proyecto',
    'asunto'    => 'Coincidencia en asunto',
    'documento' => 'Coincidencia en el texto del PDF',
];

function buscar_qs(array $extra = []): string {
    $b = [
        'q'    => $_GET['q'] ?? '',
        'pab'  => $_GET['pab'] ?? '',
        'anio' => $_GET['anio'] ?? '',
    ];
    return http_build_query(array_merge($b, $extra));
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Buscar</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Buscar') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Buscar expediente</h1></div>
  <div class="card">
    <p class="ayuda">Busque por <b>nombre del proyecto</b>, <b>CUI</b>, número de expediente, asunto o una palabra que aparezca dentro del PDF digitalizado.</p>
    <form method="get">
    <div class="row">
      <div class="grow"><label>Proyecto, CUI, expediente o texto del documento</label>
        <input name="q" placeholder="Ej. Plaza de Armas · CUI 2445678 · EXP-2024-000123"
               value="<?= htmlspecialchars($q) ?>" autofocus></div>
      <div><label>Pabellón</label>
        <select name="pab"><option value="">Todos</option>
          <?php foreach ($pabs as $p): ?>
            <option value="<?= htmlspecialchars($p['codigo']) ?>" <?= $pab===$p['codigo']?'selected':'' ?>><?= htmlspecialchars($p['codigo']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Año</label>
        <select name="anio"><option value="">Todos</option>
          <?php foreach ($anios as $a): ?><option value="<?= $a['anio'] ?>" <?= $anio==$a['anio']?'selected':'' ?>><?= $a['anio'] ?></option><?php endforeach; ?>
        </select></div>
      <div style="flex:0"><button class="btn-sm" type="submit">Buscar</button></div>
    </div></form>
  </div>
  <div class="card">
    <?php if (!$hayFiltro): ?>
      <p class="muted">Escriba un término o aplique un filtro para ver expedientes.</p>
    <?php elseif (empty($res)): ?>
      <p class="muted">Sin resultados para «<?= htmlspecialchars($q !== '' ? $q : 'filtros seleccionados') ?>».</p>
    <?php else: ?>
    <p class="muted" style="margin:0 0 10px"><?= number_format($total) ?> resultado<?= $total===1?'':'s' ?></p>
    <table>
      <tr>
        <th>N° Expediente</th><th>CUI</th><th>Proyecto</th><th>Año</th>
        <th>Área</th><th>Ubicación</th><th>Docs</th><th>Estado</th><th>Acción</th>
      </tr>
      <?php foreach ($res as $e): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($e['nro_expediente']) ?></td>
        <td class="mono"><?= htmlspecialchars($e['cui'] ?: '—') ?></td>
        <td>
          <b><?= htmlspecialchars($e['nombre_proyecto'] ?: $e['asunto']) ?></b>
          <?php if ($e['nombre_proyecto'] && $e['asunto'] && $e['nombre_proyecto'] !== $e['asunto']): ?>
            <div class="muted"><?= htmlspecialchars($e['asunto']) ?></div>
          <?php endif; ?>
          <?php if (!empty($e['origen'])): ?>
            <div><span class="tag t-info"><?= htmlspecialchars($etiquetaOrigen[$e['origen']] ?? $e['origen']) ?></span></div>
          <?php endif; ?>
          <?php if (!empty($e['snippet'])): ?>
            <div class="snippet"><?= $e['snippet'] ?></div>
          <?php endif; ?>
        </td>
        <td><?= $e['anio'] ?></td>
        <td><?= htmlspecialchars($e['area_origen']) ?></td>
        <td class="mono"><?= htmlspecialchars($e['pab']) ?></td>
        <td><?= $e['docs'] ?></td>
        <td><span class="tag <?= $e['estado']==='APROBADO'?'t-ok':'t-warn' ?>"><?= $e['estado']==='APROBADO'?'Aprobado':'En proceso' ?></span></td>
        <td>
          <a class="btn-sm" href="documentos.php?exp=<?= (int)$e['id'] ?>">Docs</a>
          <?php if (es_admin() && $e['estado']!=='APROBADO'): ?>
            <form method="post" action="aprobar.php" style="display:inline" onsubmit="return confirm('¿Aprobar este expediente?')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
              <button class="btn-sm" type="submit">Aprobar</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($paginas > 1): ?>
      <div class="paginacion">
        <?php if ($pag > 1): ?>
          <a class="btn-sm btn-gray" href="buscar.php?<?= htmlspecialchars(buscar_qs(['pag'=>$pag-1])) ?>">← Anterior</a>
        <?php endif; ?>
        <span class="muted">Página <?= $pag ?> de <?= $paginas ?></span>
        <?php if ($pag < $paginas): ?>
          <a class="btn-sm btn-gray" href="buscar.php?<?= htmlspecialchars(buscar_qs(['pag'=>$pag+1])) ?>">Siguiente →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</main></body></html>
