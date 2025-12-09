(function () {
  if (!window.MProPortalManager) return;

  const { restBase, nonce, canManage } = window.MProPortalManager;
  const root = document.getElementById('mpro-portal-manager');
  if (!root || !canManage) return;

  // ------- DOM refs (guarded) -------
  const form           = root.querySelector('#mpro-portal-form');
  const list           = root.querySelector('#mpro-boxes-list');         // <-- list is defined here
  const reset          = root.querySelector('#mpro-reset');
  const clientsSelect  = root.querySelector('#mpro-clients');
  const saveOrderBtn   = root.querySelector('#mpro-save-order');         // <-- and here
  const collectionsFld = form ? form.querySelector('input[name="collections"]') : null;

  // ------- API helper -------
  const api = async (path, opts = {}) => {
	const url = `${restBase}${path}`;
	const res = await fetch(
	  url + (url.includes('?') ? '&' : '?') + `_wpnonce=${encodeURIComponent(nonce)}`,
	  { credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, ...opts }
	);
	if (!res.ok) {
	  let msg = `${res.status} ${res.statusText}`;
	  try { const t = await res.text(); if (t) msg += ` — ${t.slice(0, 400)}`; } catch(_) {}
	  throw new Error(msg);
	}
	return res.json();
  };

  // ------- State -------
  let state = { boxes: [], clients: [], roles: [] };

  // ------- UI helpers -------
  const toOption = ([value, label]) => {
	const opt = document.createElement('option');
	opt.value = value; opt.textContent = label;
	return opt;
  };

  const populateClients = () => {
	if (!clientsSelect) return;
	clientsSelect.innerHTML = '';
	(state.clients || []).forEach(c => clientsSelect.appendChild(toOption(c)));
  };

  const checkRoles = (roles = []) => {
	if (!form) return;
	form.querySelectorAll('input[name="roles[]"]').forEach(cb => {
	  cb.checked = roles.includes(cb.value);
	});
  };

  const setForm = (box = null) => {
	if (!form) return;
	form.id.value          = box?.id || '';
	form.image.value       = box?.image || '';
	form.title.value       = box?.title || '';
	form.description.value = box?.description || '';
	form.link.value        = box?.link || '';
	if (collectionsFld) collectionsFld.value = (box?.collections || []).join(', ');
	checkRoles(box?.roles || []);
	if (clientsSelect) {
	  [...clientsSelect.options].forEach(o => o.selected = (box?.clients || []).includes(o.value));
	}
	form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  // ------- Drag & drop -------
  const makeDraggable = () => {
	if (!list) return;

	// Set draggable and handlers on each row
	list.querySelectorAll('.mpro-box-row').forEach(row => {
	  row.setAttribute('draggable', 'true');
	  row.addEventListener('dragstart', e => {
		row.classList.add('dragging');
		e.dataTransfer.effectAllowed = 'move';
	  });
	  row.addEventListener('dragend', () => row.classList.remove('dragging'));
	});

	// Container dragover to reorder
	// IMPORTANT: must be on the list container itself
	list.addEventListener('dragover', e => {
	  e.preventDefault();
	  const dragging = list.querySelector('.dragging');
	  if (!dragging) return;
	  const after = getDragAfterElement(list, e.clientY);
	  if (after == null) list.appendChild(dragging);
	  else list.insertBefore(dragging, after);
	}, { passive: false });
  };

  function getDragAfterElement(container, y) {
	const els = [...container.querySelectorAll('.mpro-box-row:not(.dragging)')];
	return els.reduce((closest, child) => {
	  const box = child.getBoundingClientRect();
	  const offset = y - box.top - box.height / 2;
	  if (offset < 0 && offset > closest.offset) {
		return { offset, element: child };
	  } else {
		return closest;
	  }
	}, { offset: Number.NEGATIVE_INFINITY }).element;
  }

  const serializeOrder = () => {
	if (!list) return [];
	const rows = [...list.querySelectorAll('.mpro-box-row')];
	return rows.map((row, idx) => ({ id: row.dataset.id, order: idx }));
  };

  // ------- Render -------
  const renderBoxes = () => {
	if (!list) return;
	list.innerHTML = '';
	const boxes = Array.isArray(state.boxes) ? state.boxes.slice() : [];

	if (!boxes.length) {
	  list.innerHTML = '<p>No boxes yet. Add one above.</p>';
	  return;
	}

	// Admin view A→Z (display-only)
	//boxes.sort((a, b) =>
	  //String(a.title || '').localeCompare(String(b.title || ''), undefined, { numeric: true, sensitivity: 'base' })
	//);

	boxes.forEach(box => {
	  const el = document.createElement('div');
	  el.className = 'mpro-box-row';
	  el.dataset.id = box.id; // needed for reorder payload
	  const orderBadge = (typeof box.order === 'number')
		? `<span class="mpro-order-badge" title="Front-end order">${box.order}</span>`
		: '';
	  el.innerHTML = `
		<div class="mpro-box-meta">
		  <span class="handle" aria-hidden="true">↕</span>
		  ${box.image ? `<img src="${box.image}" alt="" />` : ''}
		  <div>
			<strong>${box.title || '(No title)'}</strong> ${orderBadge}
			<div class="muted">${(box.roles || []).join(', ') || 'All roles'}</div>
			<div class="muted">Clients: ${(box.clients || []).join(', ') || 'All'}</div>
			<div class="muted">Collections: ${(box.collections || []).join(', ') || '—'}</div>
			<div class="muted"><a href="${box.link || '#'}">${box.link || ''}</a></div>
		  </div>
		</div>
		<div class="mpro-box-actions">
		  <button class="button" data-action="edit" data-id="${box.id}">Edit</button>
		  <button class="button button-danger" data-action="delete" data-id="${box.id}">Delete</button>
		</div>
	  `;
	  list.appendChild(el);
	});

	makeDraggable();
  };

  // ------- Load -------
  const load = async () => {
	try {
	  const data = await api('/boxes', { method: 'GET' });
	  state = { ...state, ...data };
	  populateClients();
	  renderBoxes();
	} catch (e) {
	  if (list) list.innerHTML = `<p>Error loading boxes: ${e.message}</p>`;
	  console.error('Client Portal Manager load error:', e);
	}
  };

  // ------- Events -------
  if (form) {
	form.addEventListener('submit', async (e) => {
	  e.preventDefault();
	  const roles = [...form.querySelectorAll('input[name="roles[]"]:checked')].map(cb => cb.value);
	  const clients = clientsSelect ? [...clientsSelect.selectedOptions].map(o => o.value) : [];
	  const collections = collectionsFld ? collectionsFld.value.split(',').map(s => s.trim()).filter(Boolean) : [];
	  const payload = {
		id: form.id.value || undefined,
		image: form.image.value.trim(),
		title: form.title.value.trim(),
		description: form.description.value,
		link: form.link.value.trim(),
		roles, clients, collections
	  };
	  try {
		const data = await api('/boxes', { method: 'POST', body: JSON.stringify(payload) });
		state.boxes = data.boxes || [];
		setForm(null);
		renderBoxes();
	  } catch (e2) {
		alert('Save failed: ' + e2.message);
		console.error('Save failed:', e2);
	  }
	});
  }

  if (reset) reset.addEventListener('click', () => setForm(null));

  if (list) {
	list.addEventListener('click', async (e) => {
	  const btn = e.target.closest('button[data-action]');
	  if (!btn) return;
	  const id = btn.dataset.id;
	  if (btn.dataset.action === 'edit') {
		const box = (state.boxes || []).find(b => b.id === id);
		setForm(box || null);
	  } else if (btn.dataset.action === 'delete') {
		if (!confirm('Delete this box?')) return;
		try {
		  const data = await api(`/boxes/${encodeURIComponent(id)}`, { method: 'DELETE' });
		  state.boxes = data.boxes || [];
		  renderBoxes();
		} catch (e2) {
		  alert('Delete failed: ' + e2.message);
		  console.error('Delete failed:', e2);
		}
	  }
	});
  }

  if (saveOrderBtn) {
	saveOrderBtn.addEventListener('click', async () => {
	  try {
		const order = serializeOrder();    // <-- uses the 'list' we defined above
		await api('/boxes/reorder', { method: 'POST', body: JSON.stringify({ order }) });
		alert('Order saved for front-end display.');
	  } catch (e) {
		alert('Failed to save order: ' + e.message);
		console.error('Save order failed:', e);
	  }
	});
  }

  // Kick off
  load();
})();
