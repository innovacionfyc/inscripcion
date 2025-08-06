<?php
require_once '../db/conexion.php';

$mensaje = '';
$link_generado = '';

function generarSlug($texto) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $texto)));
    return rtrim($slug, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $slug = generarSlug($nombre);

    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === 0) {
        $nombreImagen = uniqid() . '_' . basename($_FILES['imagen']['name']);
        $rutaDestino = '../uploads/eventos/' . $nombreImagen;

        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $rutaDestino)) {
            $stmt = $conn->prepare("INSERT INTO eventos (nombre, slug, imagen) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $nombre, $slug, $nombreImagen);
            if ($stmt->execute()) {
                $mensaje = "✅ Evento creado exitosamente.";
                $link_generado = "../public/registro.php?e=" . urlencode($slug);
            } else {
                $mensaje = "❌ Error al guardar en la base de datos.";
            }
        } else {
            $mensaje = "❌ Error al subir la imagen.";
        }
    } else {
        $mensaje = "❌ Por favor selecciona una imagen válida.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Crear Evento</title>
  <link rel="stylesheet" href="../assets/css/output.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-6">
  <div class="bg-white p-8 rounded-2xl shadow-2xl w-full max-w-xl">
    <h1 class="text-3xl font-bold mb-6 text-[#942934]">🎉 Crear nuevo evento</h1>

    <?php if ($mensaje): ?>
      <div class="mb-4 p-4 rounded-xl bg-[#f39322] text-white shadow text-sm">
        <?= $mensaje ?>
        <?php if ($link_generado): ?>
          <br><a href="<?= $link_generado ?>" class="underline text-white font-semibold" target="_blank">
            👉 Ver formulario del evento
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="space-y-5">
      <div>
        <label class="block text-sm font-bold text-gray-700">Nombre del evento:</label>
        <input type="text" name="nombre" required
          class="w-full mt-1 p-3 border border-gray-300 rounded-xl placeholder:text-gray-500 focus:ring-2 focus:ring-[#d32f57] transition-all" />
      </div>

      <div>
        <label class="block text-sm font-bold text-gray-700">Imagen del evento:</label>
        <input type="file" name="imagen" accept="image/*" required
          class="w-full mt-1 border border-gray-300 rounded-xl p-2 bg-white
          file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm
          file:bg-[#d32f57] file:text-white hover:file:bg-[#942934] transition-all" />
      </div>

      <div class="text-right">
        <button type="submit"
          class="bg-[#d32f57] hover:bg-[#942934] text-white font-bold py-2 px-6 rounded-xl transition-all">
          Crear evento
        </button>
      </div>
    </form>
  </div>
</body>
</html>
