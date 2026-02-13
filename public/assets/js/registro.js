function validarFormulario() {
  var correoEl = document.querySelector('input[name="email_corporativo"]');
  var correo = (correoEl ? correoEl.value : '').trim();
  var cedulaEl = document.querySelector('input[name="cedula"]');
  var celularEl = document.querySelector('input[name="celular"]');

  var cedula = (cedulaEl ? cedulaEl.value : '').trim();
  var celular = (celularEl ? celularEl.value : '').trim();

  if (!/^\d+$/.test(cedula)) {
    alert("La cédula debe contener solo números.");
    return false;
  }
  if (!/^\d{7,15}$/.test(celular)) {
    alert("El celular debe contener entre 7 y 15 dígitos.");
    return false;
  }
  if (!/^\S+@\S+\.\S+$/.test(correo)) {
    alert("Correo corporativo inválido.");
    return false;
  }
  return true;
}

function preEnviar(evt) {
  if (typeof validarFormulario === 'function') {
    if (!validarFormulario()) {
      if (evt && evt.preventDefault) evt.preventDefault();
      return false;
    }
  }

  var btn = document.querySelector('button[type="submit"]');
  if (btn) {
    btn.disabled = true;
    btn.className += ' opacity-70 cursor-not-allowed';
    btn.innerHTML = '<img src="../assets/img/loader-buho.gif" alt="" style="height:20px;width:auto;vertical-align:middle;margin-right:8px;"> Enviando…';
  }

  var overlay = document.getElementById('loaderOverlay');
  if (overlay) overlay.style.display = 'flex';

  return true;
}

function cerrarModalGracias() {
  var modal = document.getElementById('modalGracias');
  if (modal) modal.classList.add('hidden');
  window.location.href = "https://fycconsultores.com/inicio";
}

function otraInscripcion() {
  var slug = (window.__SLUG_ACTUAL || '').trim();

  if (!slug) {
    var m = location.search.match(/[?&]e=([^&]+)/);
    if (m) {
      try { slug = decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) {}
    }
  }
  window.location.href = "registro.php?e=" + encodeURIComponent(slug);
}

(function () {
  var entidad = document.getElementById('entidad');
  if (entidad) {
    entidad.addEventListener('input', function () {
      var start = this.selectionStart, end = this.selectionEnd;
      var v = this.value || '';
      this.value = v.toUpperCase();
      if (typeof start === 'number' && typeof end === 'number') {
        this.setSelectionRange(start, end);
      }
    });
  }

  function toTitleCase(str) {
    str = (str || '').toLowerCase();
    var parts = str.split(/(\s+|-)/);
    for (var i = 0; i < parts.length; i++) {
      var s = parts[i];
      if (s && !/^\s+$/.test(s) && s !== '-') {
        parts[i] = s.charAt(0).toUpperCase() + s.slice(1);
      }
    }
    return parts.join('');
  }

  function bindTitleCase(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      var pos = this.selectionStart;
      var val = this.value || '';
      var nuevo = toTitleCase(val);
      if (nuevo !== val) {
        this.value = nuevo;
        if (typeof pos === 'number') this.setSelectionRange(pos, pos);
      }
    });
  }
  bindTitleCase('nombres');
  bindTitleCase('apellidos');

  var radios = document.getElementsByName('asistencia_tipo');
  var wrap = document.getElementById('wrap_modulos');

  if (wrap && radios && radios.length) {
    function toggleModulos() {
      var tipo = 'COMPLETO';
      for (var i = 0; i < radios.length; i++) { if (radios[i].checked) tipo = radios[i].value; }
      wrap.className = (tipo === 'MODULOS')
        ? wrap.className.replace(' hidden', '')
        : (wrap.className.indexOf('hidden') >= 0 ? wrap.className : wrap.className + ' hidden');
    }

    for (var j = 0; j < radios.length; j++) {
      radios[j].addEventListener('change', toggleModulos);
    }
    toggleModulos();

    var _oldPreEnviar = window.preEnviar;
    window.preEnviar = function (evt) {
      if (typeof _oldPreEnviar === 'function') {
        if (_oldPreEnviar(evt) === false) return false;
      }

      var tipo = 'COMPLETO';
      for (var i = 0; i < radios.length; i++) { if (radios[i].checked) tipo = radios[i].value; }

      if (tipo === 'MODULOS') {
        var checks = wrap.querySelectorAll('input[type="checkbox"]:checked');
        if (!checks || checks.length === 0) {
          alert('Por favor selecciona al menos un día.');
          if (evt && evt.preventDefault) evt.preventDefault();
          return false;
        }
      }
      return true;
    };
  }

  var sel = document.getElementById('medio_opcion');
  var otro = document.getElementById('medio_otro');
  if (sel && otro) {
    function toggleOtro() {
      if (sel.value === 'OTRO') {
        otro.classList.remove('hidden');
        otro.required = true;
      } else {
        otro.classList.add('hidden');
        otro.required = false;
        otro.value = '';
      }
    }
    sel.addEventListener('change', toggleOtro);
    toggleOtro();
  }

  // iFrame resize (WP)
  if (window.top !== window.self) {
    function alturaDoc() {
      var b = document.body, e = document.documentElement;
      return Math.max(b.scrollHeight, b.offsetHeight, e.clientHeight, e.scrollHeight, e.offsetHeight);
    }
    function enviarAltura() {
      parent.postMessage({ type: 'registro:resize', height: alturaDoc() }, '*');
    }

    window.addEventListener('load', function () {
      enviarAltura();
      setTimeout(enviarAltura, 300);
      setTimeout(enviarAltura, 1200);
    });

    window.addEventListener('resize', enviarAltura);

    new MutationObserver(enviarAltura).observe(document.body, {
      childList: true, subtree: true, attributes: true
    });

    setInterval(enviarAltura, 1000);
  }
})();
