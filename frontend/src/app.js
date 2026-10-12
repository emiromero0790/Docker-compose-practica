const API = '/api';
const $ = (sel) => document.querySelector(sel);
const ESTADOS = ['abierta', 'en_proceso', 'resuelta'];

function escapar(texto) {
  const div = document.createElement('div');
  div.textContent = texto ?? '';
  return div.innerHTML;
}

function mensaje(texto, tipo = 'ok') {
  const m = $('#mensaje');
  m.textContent = texto;
  m.className = `mensaje ${tipo}`;
  clearTimeout(mensaje.timer);
  mensaje.timer = setTimeout(() => (m.className = 'mensaje oculto'), 4000);
}

async function api(ruta, opciones = {}) {
  const resp = await fetch(API + ruta, {
    headers: { 'Content-Type': 'application/json' },
    ...opciones,
  });
  const datos = await resp.json().catch(() => ({}));
  if (!resp.ok) throw new Error(datos.error || `Error HTTP ${resp.status}`);
  return datos;
}

async function cargar() {
  try {
    const r = await api('/incidencias');
    $('#contador').textContent = r.contador ?? 'N/D';
    $('#origen').textContent = r.origen === 'cache' ? `Redis (caché, TTL ${r.ttl}s)` : 'MariaDB';
    const tbody = $('#tabla tbody');
    if (r.datos.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6">No hay incidencias registradas</td></tr>';
      return;
    }
    tbody.innerHTML = r.datos.map((i) => `
      <tr>
        <td>${i.id}</td>
        <td><strong>${escapar(i.titulo)}</strong><br><small>${escapar(i.descripcion)}</small></td>
        <td>${escapar(i.area)}</td>
        <td><span class="badge ${i.prioridad}">${i.prioridad}</span></td>
        <td>
          <select class="estado" data-id="${i.id}">
            ${ESTADOS.map((e) => `<option value="${e}" ${e === i.estado ? 'selected' : ''}>${e.replace('_', ' ')}</option>`).join('')}
          </select>
        </td>
        <td><button class="eliminar" data-id="${i.id}">Eliminar</button></td>
      </tr>`).join('');
  } catch (e) {
    mensaje(`No se pudo cargar la lista: ${e.message}`, 'error');
  }
}

$('#formulario').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const datos = Object.fromEntries(new FormData(ev.target));
  try {
    const r = await api('/incidencias', { method: 'POST', body: JSON.stringify(datos) });
    mensaje(`Incidencia #${r.id} creada. Correo: ${r.correo}`);
    ev.target.reset();
    cargar();
  } catch (e) {
    mensaje(e.message, 'error');
  }
});

$('#tabla').addEventListener('change', async (ev) => {
  if (!ev.target.classList.contains('estado')) return;
  try {
    await api(`/incidencias/${ev.target.dataset.id}`, {
      method: 'PUT',
      body: JSON.stringify({ estado: ev.target.value }),
    });
    mensaje('Estado actualizado');
    cargar();
  } catch (e) {
    mensaje(e.message, 'error');
  }
});

$('#tabla').addEventListener('click', async (ev) => {
  if (!ev.target.classList.contains('eliminar')) return;
  if (!confirm('¿Seguro que deseas eliminar esta incidencia?')) return;
  try {
    await api(`/incidencias/${ev.target.dataset.id}`, { method: 'DELETE' });
    mensaje('Incidencia eliminada');
    cargar();
  } catch (e) {
    mensaje(e.message, 'error');
  }
});

$('#recargar').addEventListener('click', cargar);
cargar();