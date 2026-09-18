// =====================================================================
// LMS Kelasbahasa.id — frontend SPA (vanilla JS, tanpa build step)
// =====================================================================

let CURRENT_USER = null;
let ACTIVE_TAB = null;
let LEVEL_CACHE = []; // dipakai berulang di banyak form (pilih Level)
let PROGRAM_CACHE = [];

// ---------------------------------------------------------------------
// Helper: panggil api.php
// ---------------------------------------------------------------------
async function api(action, { method = 'GET', body = null, params = null } = {}) {
  let url = 'api.php?action=' + encodeURIComponent(action);
  if (params) {
    const qs = new URLSearchParams(params).toString();
    url += '&' + qs;
  }
  const opts = { method, headers: {}, credentials: 'same-origin' };
  if (body) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(url, opts);
  let data;
  try { data = await res.json(); }
  catch (e) { throw new Error('Server tidak mengembalikan JSON (cek console/network).'); }
  if (!data.ok) throw new Error(data.error || 'Terjadi kesalahan.');
  return data;
}

function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstChild;
}
function $(sel, root = document) { return root.querySelector(sel); }
function esc(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmtDate(s) {
  if (!s) return '-';
  return s.substring(0, 10);
}

// ---------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------
$('#loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const errBox = $('#loginError');
  errBox.hidden = true;
  try {
    const data = await api('login', {
      method: 'POST',
      body: {
        username: $('#loginUsername').value.trim(),
        password: $('#loginPassword').value,
      },
    });
    onLoggedIn(data.user);
  } catch (e2) {
    errBox.textContent = e2.message;
    errBox.hidden = false;
  }
});

function onLoggedIn(user) {
  CURRENT_USER = user;
  $('#loginScreen').hidden = true;
  $('#app').hidden = false;
  $('#userName').textContent = user.nama;
  $('#userRole').textContent = roleLabel(user.role);
  buildTabs(user.role);
  loadNotifCount();
  if (user.must_change_password) openPwModal(true);
}

function roleLabel(role) {
  return {
    member: 'Member', cs: 'CS', admin: 'Admin (PEC)',
    manager: 'Manager', tutor: 'Tutor', superadmin: 'Superadmin',
  }[role] || role;
}

$('#logoutBtn').addEventListener('click', async () => {
  try { await api('logout', { method: 'POST' }); } catch (e) {}
  location.reload();
});

// ---------------------------------------------------------------------
// Ganti password
// ---------------------------------------------------------------------
function openPwModal(forced) {
  $('#pwModal').hidden = false;
  $('#pwModalNote').textContent = forced
    ? 'Ini login pertamamu — ganti password default sebelum lanjut.'
    : 'Masukkan password lama, lalu password baru.';
  $('#pwOldWrap').hidden = !!forced;
  $('#pwError').hidden = true;
  $('#pwOld').value = ''; $('#pwNew').value = '';
}
$('#changePwBtn').addEventListener('click', () => openPwModal(false));
$('#pwSubmit').addEventListener('click', async () => {
  const errBox = $('#pwError');
  errBox.hidden = true;
  try {
    const body = { password_baru: $('#pwNew').value };
    if (!$('#pwOldWrap').hidden) body.password_lama = $('#pwOld').value;
    await api('change_password', { method: 'POST', body });
    $('#pwModal').hidden = true;
    CURRENT_USER.must_change_password = false;
  } catch (e) {
    errBox.textContent = e.message;
    errBox.hidden = false;
  }
});

// ---------------------------------------------------------------------
// Notifikasi
// ---------------------------------------------------------------------
async function loadNotifCount() {
  try {
    const data = await api('notifikasi_list');
    const unread = data.notifikasi.filter(n => !n.dibaca).length;
    const badge = $('#notifCount');
    if (unread > 0) { badge.hidden = false; badge.textContent = unread; }
    else badge.hidden = true;
    window._notifCache = data.notifikasi;
  } catch (e) {}
}
$('#notifBtn').addEventListener('click', async () => {
  const panel = $('#notifPanel');
  if (!panel.hidden) { panel.hidden = true; return; }
  const data = await api('notifikasi_list');
  panel.innerHTML = data.notifikasi.length
    ? data.notifikasi.map(n => `
        <div class="notif-item ${n.dibaca ? '' : 'unread'}" data-id="${n.id}">
          <b>${esc(n.judul)}</b>${esc(n.pesan || '')}
          <div class="muted">${n.created_at}</div>
        </div>`).join('')
    : '<div class="notif-item muted">Belum ada notifikasi.</div>';
  panel.hidden = false;
  panel.querySelectorAll('.notif-item[data-id]').forEach(item => {
    item.addEventListener('click', async () => {
      await api('notifikasi_read', { method: 'POST', body: { id: item.dataset.id } });
      item.classList.remove('unread');
      loadNotifCount();
    });
  });
});

// ---------------------------------------------------------------------
// Tab & routing per role
// ---------------------------------------------------------------------
const TABS_BY_ROLE = {
  member:     [['dashboard', 'Dashboard'], ['test', 'Test'], ['sertifikat', 'Sertifikat'], ['chat', 'Percakapan']],
  cs:         [['cs', 'Cari Member']],
  admin:      [['kelas', 'Kelola Kelas'], ['akun', 'Akun Tutor & Member'], ['sertifikat_admin', 'Sertifikat'], ['pindah', 'Pindah Kelas']],
  manager:    [['katalog', 'Program/Level/Paket'], ['materi', 'Materi & Test'], ['sop', 'SOP'], ['monitoring', 'Monitoring']],
  tutor:      [['kelas_tutor', 'Kelas Saya']],
  superadmin: [['akun_super', 'Kelola Akun'], ['kelas', 'Kelola Kelas'], ['katalog', 'Program/Level/Paket'], ['materi', 'Materi & Test'], ['sop', 'SOP'], ['monitoring', 'Monitoring'], ['sertifikat_admin', 'Sertifikat'], ['cs', 'Cari Member']],
};

function buildTabs(role) {
  const nav = $('#tabNav');
  nav.innerHTML = '';
  const tabs = TABS_BY_ROLE[role] || [];
  tabs.forEach(([key, label], i) => {
    const b = el(`<button data-tab="${key}">${esc(label)}</button>`);
    b.addEventListener('click', () => switchTab(key));
    nav.appendChild(b);
  });
  if (tabs.length) switchTab(tabs[0][0]);
}

function switchTab(key) {
  ACTIVE_TAB = key;
  document.querySelectorAll('.tabnav button').forEach(b => b.classList.toggle('active', b.dataset.tab === key));
  const renderers = {
    dashboard: renderMemberDashboard,
    test: renderMemberTest,
    sertifikat: renderMemberSertifikat,
    chat: renderMemberChat,
    cs: renderCsCari,
    kelas: renderAdminKelas,
    akun: renderAdminAkun,
    sertifikat_admin: renderAdminSertifikat,
    pindah: renderAdminPindah,
    katalog: renderManagerKatalog,
    materi: renderManagerMateri,
    sop: renderManagerSop,
    monitoring: renderManagerMonitoring,
    kelas_tutor: renderTutorKelas,
    akun_super: renderSuperadminAkun,
  };
  const fn = renderers[key];
  $('#content').innerHTML = '<div class="empty-state">Memuat…</div>';
  if (fn) fn().catch(showContentError);
}

function showContentError(e) {
  $('#content').innerHTML = `<div class="msg err">${esc(e.message)}</div>`;
}

// ---------------------------------------------------------------------
// Util: cache Program & Level (dipakai di banyak dropdown)
// ---------------------------------------------------------------------
async function ensureCatalogCache() {
  const [p, l] = await Promise.all([api('program_list'), api('level_list')]);
  PROGRAM_CACHE = p.program;
  LEVEL_CACHE = l.level;
}
function levelOptionsHtml(selectedId) {
  return LEVEL_CACHE.map(l =>
    `<option value="${l.id}" ${String(l.id) === String(selectedId) ? 'selected' : ''}>${esc(l.program_nama)} — ${esc(l.nama)}</option>`
  ).join('');
}

// =====================================================================
// ================== MEMBER ==================
// =====================================================================

async function renderMemberDashboard() {
  const data = await api('member_dashboard');
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Kelas Saya</h2></div>`));

  if (!data.kelas.length) {
    c.appendChild(el(`<div class="empty-state">Belum terdaftar di kelas manapun.</div>`));
  }
  data.kelas.forEach(k => {
    const zoom = k.zoom_terdekat
      ? `<a class="btn small yellow" href="${esc(k.zoom_terdekat.link || '#')}" target="_blank">Link Zoom (${fmtDate(k.zoom_terdekat.tanggal_sesi)})</a>`
      : `<span class="muted">Belum ada link Zoom sesi berikutnya</span>`;
    c.appendChild(el(`
      <div class="card">
        <h3>${esc(k.program_nama)} — ${esc(k.level_nama)}</h3>
        <p class="muted">Tutor: ${esc(k.tutor_nama || '-')} · Jadwal: ${esc(k.jadwal || '-')} · Status kelas: <span class="pill neutral">${esc(k.kelas_status)}</span></p>
        <p>${zoom}</p>
      </div>
    `));
  });

  c.appendChild(el(`<div class="section-title"><h2>Notifikasi</h2></div>`));
  const notifWrap = el('<div class="card"></div>');
  notifWrap.innerHTML = data.notifikasi.length
    ? data.notifikasi.map(n => `<div class="notif-item ${n.dibaca ? '' : 'unread'}"><b>${esc(n.judul)}</b>${esc(n.pesan || '')}<div class="muted">${n.created_at}</div></div>`).join('')
    : '<div class="empty-state">Belum ada notifikasi.</div>';
  c.appendChild(notifWrap);
}

async function renderMemberTest() {
  const dash = await api('member_dashboard');
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Pre / Mid / Final Test</h2></div>`));

  if (!dash.kelas.length) { c.appendChild(el(`<div class="empty-state">Belum ada kelas.</div>`)); return; }

  const sel = el(`<select class="field-select">${dash.kelas.map(k => `<option value="${k.enrollment_id}">${esc(k.level_nama)}</option>`).join('')}</select>`);
  const wrap = el(`<div class="card"><label>Pilih Kelas</label></div>`);
  wrap.appendChild(sel);
  c.appendChild(wrap);

  const listBox = el('<div id="testListBox"></div>');
  c.appendChild(listBox);

  const statusLabel = { terkunci: ['Terkunci', 'neutral'], tersedia: ['Bisa Dikerjakan', 'ok'], dikerjakan: ['Menunggu Nilai', 'warn'], dinilai: ['Sudah Dinilai', 'ok'] };

  async function loadTest() {
    const data = await api('member_test_list', { params: { enrollment_id: sel.value } });
    listBox.innerHTML = '';
    data.test.forEach(t => {
      const [label, cls] = statusLabel[t.status] || ['-', 'neutral'];
      const card = el(`
        <div class="card">
          <h3>${t.jenis.toUpperCase()} — ${esc(t.judul)}</h3>
          <p><span class="pill ${cls}">${label}</span> ${t.skor !== null ? '· Nilai: <b>' + t.skor + '</b>' : ''}</p>
        </div>
      `);
      if (t.status === 'tersedia') {
        const btn = el(`<button class="btn">Kerjakan / Submit Test</button>`);
        btn.addEventListener('click', async () => {
          if (!confirm('Kerjakan test ini sekarang? Setelah submit, tutor akan menilai.')) return;
          try {
            await api('member_test_submit', { method: 'POST', body: { enrollment_id: sel.value, test_id: t.id } });
            loadTest();
          } catch (e) { alert(e.message); }
        });
        card.appendChild(btn);
      }
      listBox.appendChild(card);
    });
  }
  sel.addEventListener('change', loadTest);
  loadTest();
}

async function renderMemberSertifikat() {
  const data = await api('member_sertifikat_list');
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Sertifikat</h2></div>`));
  if (!data.sertifikat.length) { c.appendChild(el(`<div class="empty-state">Belum ada sertifikat.</div>`)); return; }

  data.sertifikat.forEach(s => {
    const card = el(`
      <div class="card">
        <h3>${esc(s.level_nama)}</h3>
        <p><span class="pill ${s.status === 'terkunci' ? 'neutral' : 'ok'}">${esc(s.status)}</span></p>
      </div>
    `);
    if (s.status !== 'terkunci') {
      const btn = el(`<button class="btn yellow">Unduh / Cetak Sertifikat</button>`);
      btn.addEventListener('click', async () => {
        await api('member_sertifikat_unduh', { method: 'POST', body: { sertifikat_id: s.id } });
        window.print();
      });
      card.appendChild(btn);
    } else {
      card.appendChild(el(`<p class="muted">Menunggu dibuka Admin setelah kelas selesai.</p>`));
    }
    c.appendChild(card);
  });
}

async function renderMemberChat() {
  const dash = await api('member_dashboard');
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Percakapan</h2></div>`));
  if (!dash.kelas.length) { c.appendChild(el(`<div class="empty-state">Belum ada kelas.</div>`)); return; }

  const kelasSel = el(`<select>${dash.kelas.map(k => `<option value="${k.kelas_id}">${esc(k.level_nama)}</option>`).join('')}</select>`);
  const targetSel = el(`<select><option value="kelas">Grup Kelas</option><option value="tutor">Ke Tutor (1:1)</option></select>`);
  const toolbar = el(`<div class="toolbar"></div>`);
  toolbar.appendChild(kelasSel);
  toolbar.appendChild(targetSel);
  c.appendChild(toolbar);

  const chatBox = el('<div class="chat-box"></div>');
  c.appendChild(chatBox);
  const inputRow = el(`
    <div class="toolbar">
      <input type="text" id="chatInput" placeholder="Tulis pesan…" style="flex:1;">
      <button class="btn">Kirim</button>
    </div>
  `);
  c.appendChild(inputRow);

  async function loadChat() {
    const data = await api('percakapan_list', { params: { kelas_id: kelasSel.value, target: targetSel.value } });
    chatBox.innerHTML = data.pesan.length
      ? data.pesan.map(p => `<div class="chat-msg"><b>${esc(p.pengirim)}</b>${esc(p.pesan)}<span class="time">${p.created_at}</span></div>`).join('')
      : '<div class="muted">Belum ada pesan.</div>';
    chatBox.scrollTop = chatBox.scrollHeight;
  }
  kelasSel.addEventListener('change', loadChat);
  targetSel.addEventListener('change', loadChat);
  inputRow.querySelector('button').addEventListener('click', async () => {
    const input = $('#chatInput', inputRow);
    if (!input.value.trim()) return;
    await api('percakapan_kirim', { method: 'POST', body: { kelas_id: kelasSel.value, target: targetSel.value, pesan: input.value.trim() } });
    input.value = '';
    loadChat();
  });
  loadChat();
}

// =====================================================================
// ================== CS ==================
// =====================================================================

async function renderCsCari() {
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Cari Member (read-only)</h2></div>`));
  const toolbar = el(`
    <div class="toolbar">
      <input type="text" id="csNoId" placeholder="Masukkan No ID member…" style="flex:1;">
      <button class="btn">Cari</button>
    </div>
  `);
  c.appendChild(toolbar);
  const result = el('<div id="csResult"></div>');
  c.appendChild(result);

  async function doSearch() {
    const noId = $('#csNoId', toolbar).value.trim();
    if (!noId) return;
    result.innerHTML = '<div class="empty-state">Mencari…</div>';
    try {
      const data = await api('cs_cari', { params: { no_id: noId } });
      renderCsResult(data);
    } catch (e) {
      result.innerHTML = `<div class="msg err">${esc(e.message)}</div>`;
    }
  }
  toolbar.querySelector('button').addEventListener('click', doSearch);
  $('#csNoId', toolbar).addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });

  function renderCsResult(data) {
    const m = data.member;
    result.innerHTML = '';
    const printBtn = el(`<button class="btn secondary" style="float:right;">🖨️ Cetak / PDF</button>`);
    printBtn.addEventListener('click', () => window.print());
    const head = el(`
      <div class="card">
        ${printBtn.outerHTML}
        <h3>${esc(m.nama)}</h3>
        <p class="muted">No ID: ${esc(m.no_id)} · ${esc(m.email || '-')} · ${esc(m.whatsapp || '-')}</p>
      </div>
    `);
    result.appendChild(head);

    if (!data.riwayat_kelas.length) {
      result.appendChild(el(`<div class="empty-state">Belum pernah ikut kelas.</div>`));
      return;
    }
    data.riwayat_kelas.forEach(k => {
      const nilaiHtml = k.nilai.length
        ? k.nilai.map(n => `${n.jenis}: <b>${n.skor ?? '-'}</b>`).join(' · ')
        : 'Belum ada nilai';
      result.appendChild(el(`
        <div class="card">
          <h3>${esc(k.program_nama)} — ${esc(k.level_nama)}</h3>
          <p class="muted">Tutor: ${esc(k.tutor_nama || '-')} · Status: <span class="pill neutral">${esc(k.status)}</span></p>
          <p>Kehadiran: ${k.hadir}/${k.total_sesi_tercatat} sesi tercatat</p>
          <p>Nilai: ${nilaiHtml}</p>
          <p>Sertifikat: <span class="pill ${k.sertifikat_status === 'terkunci' ? 'neutral' : 'ok'}">${esc(k.sertifikat_status || '-')}</span></p>
        </div>
      `));
    });
  }
}

// =====================================================================
// ================== ADMIN ==================
// =====================================================================

async function renderAdminKelas() {
  await ensureCatalogCache();
  const tutors = await api('users_list', { params: { role: 'tutor' } });
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Buka Kelas Baru</h2></div>`));

  const form = el(`
    <div class="card">
      <div class="grid2">
        <div class="field"><label>Level</label><select id="fLevel">${levelOptionsHtml()}</select></div>
        <div class="field"><label>Tipe Kelas</label>
          <select id="fTipe">
            <option value="regular">Regular (kuota 5–10, BR-1)</option>
            <option value="private">Private (kuota tetap 1, BR-17)</option>
            <option value="private_couple">Private Couple (kuota tetap 2, BR-17)</option>
          </select>
        </div>
        <div class="field"><label>Tutor</label>
          <select id="fTutor"><option value="">- belum ditentukan -</option>${tutors.users.map(t => `<option value="${t.id}">${esc(t.nama)}</option>`).join('')}</select>
        </div>
        <div class="field"><label>Jadwal</label><input type="text" id="fJadwal" placeholder="mis. Senin-Jumat 18.30 WIB"></div>
        <div class="field"><label>Tanggal Mulai</label><input type="date" id="fTglMulai"></div>
      </div>
      <button class="btn" id="btnBukaKelas">Buka Kelas</button>
      <p class="muted" style="margin-top:8px;">🔸 Kelas Regular tetap bisa jalan meski belum 5 member (BR-2). Untuk Paket Bundling, Member bebas pilih sendiri Level yang diambil duluan (BR-17).</p>
    </div>
  `);
  c.appendChild(form);
  $('#btnBukaKelas', form).addEventListener('click', async () => {
    try {
      await api('kelas_create', {
        method: 'POST',
        body: {
          level_id: $('#fLevel', form).value,
          tipe: $('#fTipe', form).value,
          tutor_id: $('#fTutor', form).value || null,
          jadwal: $('#fJadwal', form).value,
          tanggal_mulai: $('#fTglMulai', form).value || null,
        },
      });
      renderAdminKelas();
    } catch (e) { alert(e.message); }
  });

  c.appendChild(el(`<div class="section-title"><h2>Daftar Kelas</h2></div>`));
  const data = await api('kelas_list');
  const tableWrap = el('<div class="table-wrap card"></div>');
  tableWrap.innerHTML = `
    <table>
      <thead><tr><th>Level</th><th>Tipe</th><th>Tutor</th><th>Jadwal</th><th>Kuota</th><th>Member</th><th>Status</th><th></th></tr></thead>
      <tbody>
        ${data.kelas.map(k => `
          <tr>
            <td>${esc(k.level_nama)}</td>
            <td>${esc(k.tipe)}</td>
            <td>${esc(k.tutor_nama || '-')}</td>
            <td>${esc(k.jadwal || '-')}</td>
            <td>${k.kuota_min}–${k.kuota_maks}</td>
            <td>${k.jumlah_member}</td>
            <td><span class="pill neutral">${esc(k.status)}</span></td>
            <td>
              <select data-id="${k.id}" class="statusSel">
                ${['draft','dibuka','berjalan','selesai','arsip'].map(s => `<option value="${s}" ${s===k.status?'selected':''}>${s}</option>`).join('')}
              </select>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
  c.appendChild(tableWrap);
  tableWrap.querySelectorAll('.statusSel').forEach(sel => {
    sel.addEventListener('change', async () => {
      try {
        await api('kelas_update_status', { method: 'POST', body: { id: sel.dataset.id, status: sel.value } });
      } catch (e) { alert(e.message); renderAdminKelas(); }
    });
  });
}

async function renderAdminAkun() {
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Buat Akun Tutor</h2></div>`));
  const form = el(`
    <div class="card">
      <div class="grid2">
        <div class="field"><label>Nama</label><input type="text" id="tNama"></div>
        <div class="field"><label>Username</label><input type="text" id="tUsername"></div>
      </div>
      <button class="btn" id="btnBuatTutor">Buat Akun Tutor</button>
      <p class="muted">BR-9: Admin hanya bisa membuat akun Tutor. Password awal dibuat acak & wajib diganti saat login pertama.</p>
    </div>
  `);
  c.appendChild(form);
  $('#btnBuatTutor', form).addEventListener('click', async () => {
    try {
      const res = await api('users_create', { method: 'POST', body: { nama: $('#tNama', form).value, username: $('#tUsername', form).value, role: 'tutor' } });
      alert('Akun Tutor dibuat. Password awal: ' + res.password_awal);
      renderAdminAkun();
    } catch (e) { alert(e.message); }
  });

  c.appendChild(el(`<div class="section-title"><h2>Reset Password Member</h2></div>`));
  const rp = el(`
    <div class="card">
      <div class="toolbar">
        <input type="text" id="rpNoId" placeholder="No ID member…">
        <button class="btn secondary" id="btnCariMember">Cari</button>
      </div>
      <div id="rpResult"></div>
    </div>
  `);
  c.appendChild(rp);
  $('#btnCariMember', rp).addEventListener('click', async () => {
    const noId = $('#rpNoId', rp).value.trim();
    if (!noId) return;
    try {
      const data = await api('cs_cari', { params: { no_id: noId } });
      $('#rpResult', rp).innerHTML = `<p>${esc(data.member.nama)} — <button class="btn small" id="btnResetNow">Reset Password (BR-11)</button></p>`;
      $('#btnResetNow', rp).addEventListener('click', async () => {
        const res = await api('member_reset_password', { method: 'POST', body: { user_id: data.member.id } });
        alert('Password baru: ' + res.password_baru);
      });
    } catch (e) { $('#rpResult', rp).innerHTML = `<div class="msg err">${esc(e.message)}</div>`; }
  });
}

async function renderAdminSertifikat() {
  const data = await api('sertifikat_kandidat');
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Kandidat Sertifikat (kelas selesai)</h2></div>`));
  c.appendChild(el(`<p class="muted">BR-4/Q11: kelayakan lulus dicek manual satu per satu, bukan otomatis sistem.</p>`));

  if (!data.kandidat.length) { c.appendChild(el(`<div class="empty-state">Tidak ada kandidat saat ini.</div>`)); return; }

  const tableWrap = el('<div class="table-wrap card"></div>');
  tableWrap.innerHTML = `
    <table>
      <thead><tr><th>Member</th><th>No ID</th><th>Level</th><th>Kehadiran</th><th>Aksi</th></tr></thead>
      <tbody>
        ${data.kandidat.map(k => `
          <tr data-enr="${k.enrollment_id}">
            <td>${esc(k.member_nama)}</td>
            <td>${esc(k.no_id)}</td>
            <td>${esc(k.level_nama)}</td>
            <td>${k.hadir}/${k.total_sesi_tercatat}</td>
            <td>
              <button class="btn small" data-lulus="1">Lulus → Buka Sertifikat</button>
              <button class="btn small danger" data-lulus="0">Tidak Lulus</button>
            </td>
          </tr>
        `).join('')}
      </tbody>
    </table>
  `;
  c.appendChild(tableWrap);
  tableWrap.querySelectorAll('button[data-lulus]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tr = btn.closest('tr');
      try {
        await api('sertifikat_putuskan', { method: 'POST', body: { enrollment_id: tr.dataset.enr, lulus: btn.dataset.lulus === '1' } });
        renderAdminSertifikat();
      } catch (e) { alert(e.message); }
    });
  });
}

async function renderAdminPindah() {
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Pindah Kelas</h2></div>`));
  c.appendChild(el(`<p class="muted">BR-12: maksimal H+2 dari tanggal mulai kelas asal, dan kelas tujuan harus sudah dibuka.</p>`));

  const form = el(`
    <div class="card">
      <div class="field"><label>No ID Member</label><input type="text" id="pkNoId"></div>
      <div id="pkKelasList"></div>
      <div class="field"><label>ID Kelas Tujuan</label><input type="text" id="pkTujuan" placeholder="Lihat daftar kelas di tab Kelola Kelas"></div>
      <button class="btn" id="btnPindah">Proses Pindah Kelas</button>
    </div>
  `);
  c.appendChild(form);

  let enrollmentTerpilih = null;
  $('#pkNoId', form).addEventListener('change', async () => {
    const noId = $('#pkNoId', form).value.trim();
    if (!noId) return;
    const data = await api('cs_cari', { params: { no_id: noId } });
    $('#pkKelasList', form).innerHTML = data.riwayat_kelas.map(k =>
      `<label style="display:block;margin:4px 0;"><input type="radio" name="pkEnr" value="${k.enrollment_id}"> ${esc(k.level_nama)} (enrollment #${k.enrollment_id})</label>`
    ).join('') || '<p class="muted">Belum ikut kelas apapun.</p>';
  });

  $('#btnPindah', form).addEventListener('click', async () => {
    const checked = form.querySelector('input[name="pkEnr"]:checked');
    if (!checked) { alert('Pilih kelas asal dulu.'); return; }
    try {
      await api('enrollment_pindah_kelas', {
        method: 'POST',
        body: { enrollment_id: checked.value, kelas_tujuan_id: $('#pkTujuan', form).value },
      });
      alert('Berhasil dipindah.');
    } catch (e) { alert(e.message); }
  });
}

// =====================================================================
// ================== MANAGER (+ dipakai Superadmin) ==================
// =====================================================================

async function renderManagerKatalog() {
  await ensureCatalogCache();
  const c = $('#content');
  c.innerHTML = '';

  // --- Program ---
  c.appendChild(el(`<div class="section-title"><h2>Program</h2></div>`));
  const pForm = el(`
    <div class="card">
      <div class="grid2">
        <div class="field"><label>Nama Program</label><input type="text" id="pNama"></div>
        <div class="field"><label>Deskripsi</label><input type="text" id="pDesk"></div>
      </div>
      <button class="btn" id="btnAddProgram">Tambah Program</button>
    </div>
  `);
  c.appendChild(pForm);
  $('#btnAddProgram', pForm).addEventListener('click', async () => {
    try {
      await api('program_create', { method: 'POST', body: { nama: $('#pNama', pForm).value, deskripsi: $('#pDesk', pForm).value } });
      renderManagerKatalog();
    } catch (e) { alert(e.message); }
  });

  const pTable = el('<div class="table-wrap card"></div>');
  pTable.innerHTML = `<table><thead><tr><th>Nama</th><th>Deskripsi</th><th></th></tr></thead><tbody>
    ${PROGRAM_CACHE.map(p => `<tr><td>${esc(p.nama)}</td><td>${esc(p.deskripsi || '-')}</td>
      <td><button class="btn small danger" data-del-program="${p.id}">Hapus</button></td></tr>`).join('')}
  </tbody></table>`;
  c.appendChild(pTable);
  pTable.querySelectorAll('[data-del-program]').forEach(btn => btn.addEventListener('click', async () => {
    if (!confirm('Hapus program ini? Semua Level di dalamnya ikut terhapus.')) return;
    await api('program_delete', { method: 'POST', body: { id: btn.dataset.delProgram } });
    renderManagerKatalog();
  }));

  // --- Level ---
  c.appendChild(el(`<div class="section-title"><h2>Level (BR-16: Manager bisa tambah kapan saja)</h2></div>`));
  const lForm = el(`
    <div class="card">
      <div class="grid2">
        <div class="field"><label>Program</label><select id="lProgram">${PROGRAM_CACHE.map(p => `<option value="${p.id}">${esc(p.nama)}</option>`).join('')}</select></div>
        <div class="field"><label>Nama Level</label><input type="text" id="lNama" placeholder="mis. Speaking 1"></div>
        <div class="field"><label>Rekomendasi untuk</label><input type="text" id="lRekom"></div>
        <div class="field"><label>Durasi Belajar</label><input type="text" id="lDurasi" placeholder="mis. 20x Pertemuan"></div>
      </div>
      <button class="btn" id="btnAddLevel">Tambah Level</button>
    </div>
  `);
  c.appendChild(lForm);
  $('#btnAddLevel', lForm).addEventListener('click', async () => {
    try {
      await api('level_create', {
        method: 'POST',
        body: { program_id: $('#lProgram', lForm).value, nama: $('#lNama', lForm).value, rekomendasi: $('#lRekom', lForm).value, durasi_belajar: $('#lDurasi', lForm).value },
      });
      renderManagerKatalog();
    } catch (e) { alert(e.message); }
  });

  const lTable = el('<div class="table-wrap card"></div>');
  lTable.innerHTML = `<table><thead><tr><th>Program</th><th>Level</th><th>Durasi</th><th></th></tr></thead><tbody>
    ${LEVEL_CACHE.map(l => `<tr><td>${esc(l.program_nama)}</td><td>${esc(l.nama)}</td><td>${esc(l.durasi_belajar || '-')}</td>
      <td><button class="btn small danger" data-del-level="${l.id}">Hapus</button></td></tr>`).join('')}
  </tbody></table>`;
  c.appendChild(lTable);
  lTable.querySelectorAll('[data-del-level]').forEach(btn => btn.addEventListener('click', async () => {
    if (!confirm('Hapus Level ini?')) return;
    await api('level_delete', { method: 'POST', body: { id: btn.dataset.delLevel } });
    renderManagerKatalog();
  }));

  // --- Paket ---
  c.appendChild(el(`<div class="section-title"><h2>Paket</h2></div>`));
  const paketData = await api('paket_list');
  const pkForm = el(`
    <div class="card">
      <div class="grid2">
        <div class="field"><label>Nama Paket</label><input type="text" id="pkNama"></div>
        <div class="field"><label>Tipe</label>
          <select id="pkTipe">
            <option value="regular">Regular</option><option value="private">Private</option>
            <option value="private_couple">Private Couple</option><option value="bundling">Bundling</option>
            <option value="elearning">E-Learning</option><option value="test_only">Test-only</option>
          </select>
        </div>
        <div class="field"><label>Harga (ribuan)</label><input type="number" id="pkHarga"></div>
        <div class="field"><label>Durasi</label><input type="text" id="pkDurasi"></div>
        <div class="field"><label>Link Pembayaran</label><input type="text" id="pkLink"></div>
      </div>
      <div class="field"><label>Level yang termasuk (bisa lebih dari 1 — Bundling)</label>
        <select id="pkLevels" multiple size="5">${levelOptionsHtml()}</select>
      </div>
      <button class="btn" id="btnAddPaket">Tambah Paket</button>
    </div>
  `);
  c.appendChild(pkForm);
  $('#btnAddPaket', pkForm).addEventListener('click', async () => {
    const levelIds = Array.from($('#pkLevels', pkForm).selectedOptions).map(o => o.value);
    try {
      await api('paket_create', {
        method: 'POST',
        body: {
          nama: $('#pkNama', pkForm).value, tipe: $('#pkTipe', pkForm).value,
          harga: $('#pkHarga', pkForm).value, durasi: $('#pkDurasi', pkForm).value,
          link_pembayaran: $('#pkLink', pkForm).value, level_ids: levelIds,
        },
      });
      renderManagerKatalog();
    } catch (e) { alert(e.message); }
  });

  const pkTable = el('<div class="table-wrap card"></div>');
  pkTable.innerHTML = `<table><thead><tr><th>Nama</th><th>Tipe</th><th>Harga</th><th>Level</th><th></th></tr></thead><tbody>
    ${paketData.paket.map(p => `<tr><td>${esc(p.nama)}</td><td>${esc(p.tipe)}</td><td>Rp${esc(p.harga)}rb</td>
      <td>${p.level_pilihan.map(l => esc(l.nama)).join(', ') || '-'}</td>
      <td><button class="btn small danger" data-del-paket="${p.id}">Nonaktifkan</button></td></tr>`).join('')}
  </tbody></table>`;
  c.appendChild(pkTable);
  pkTable.querySelectorAll('[data-del-paket]').forEach(btn => btn.addEventListener('click', async () => {
    await api('paket_delete', { method: 'POST', body: { id: btn.dataset.delPaket } });
    renderManagerKatalog();
  }));
}

async function renderManagerMateri() {
  await ensureCatalogCache();
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Materi & Test per Level</h2></div>`));

  const levelSel = el(`<select id="mLevel">${levelOptionsHtml()}</select>`);
  const toolbar = el('<div class="card"></div>');
  toolbar.appendChild(el('<label>Pilih Level</label>'));
  toolbar.appendChild(levelSel);
  c.appendChild(toolbar);

  const box = el('<div id="materiTestBox"></div>');
  c.appendChild(box);

  async function loadForLevel() {
    const levelId = levelSel.value;
    const [materi, tests] = await Promise.all([
      api('materi_list', { params: { level_id: levelId } }),
      api('test_list', { params: { level_id: levelId } }),
    ]);
    box.innerHTML = '';

    // Materi
    const materiCard = el(`
      <div class="card">
        <h3>Materi (PPT/PDF)</h3>
        <form id="uploadForm">
          <div class="field"><label>Judul</label><input type="text" id="matJudul" required></div>
          <div class="field"><label>File (PDF/PPT/PPTX)</label><input type="file" id="matFile" accept=".pdf,.ppt,.pptx" required></div>
          <button class="btn" type="submit">Upload</button>
        </form>
        <div class="table-wrap" style="margin-top:12px;">
          <table><thead><tr><th>Judul</th><th>File</th><th></th></tr></thead><tbody>
            ${materi.materi.map(m => `<tr><td>${esc(m.judul)}</td><td><a href="${esc(m.file_path)}" target="_blank">Buka</a></td>
              <td><button class="btn small danger" data-del-materi="${m.id}">Hapus</button></td></tr>`).join('') || '<tr><td colspan="3" class="muted">Belum ada materi.</td></tr>'}
          </tbody></table>
        </div>
      </div>
    `);
    box.appendChild(materiCard);
    $('#uploadForm', materiCard).addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData();
      fd.append('action', 'materi_upload');
      fd.append('level_id', levelId);
      fd.append('judul', $('#matJudul', materiCard).value);
      fd.append('file', $('#matFile', materiCard).files[0]);
      const res = await fetch('api.php?action=materi_upload', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.ok) { alert(data.error); return; }
      loadForLevel();
    });
    materiCard.querySelectorAll('[data-del-materi]').forEach(btn => btn.addEventListener('click', async () => {
      await api('materi_delete', { method: 'POST', body: { id: btn.dataset.delMateri } });
      loadForLevel();
    }));

    // Test
    const testCard = el(`
      <div class="card">
        <h3>Pre / Mid / Final Test</h3>
        <div class="toolbar">
          <select id="testJenis"><option value="pre">Pre-test</option><option value="mid">Mid-test</option><option value="final">Final-test</option></select>
          <input type="text" id="testJudul" placeholder="Judul test">
          <button class="btn small" id="btnAddTest">Tambah Test</button>
        </div>
        <div class="table-wrap">
          <table><thead><tr><th>Jenis</th><th>Judul</th><th></th></tr></thead><tbody>
            ${tests.test.map(t => `<tr><td>${t.jenis}</td><td>${esc(t.judul)}</td>
              <td><button class="btn small danger" data-del-test="${t.id}">Hapus</button></td></tr>`).join('') || '<tr><td colspan="3" class="muted">Belum ada test.</td></tr>'}
          </tbody></table>
        </div>
      </div>
    `);
    box.appendChild(testCard);
    $('#btnAddTest', testCard).addEventListener('click', async () => {
      try {
        await api('test_create', { method: 'POST', body: { level_id: levelId, jenis: $('#testJenis', testCard).value, judul: $('#testJudul', testCard).value } });
        loadForLevel();
      } catch (e) { alert(e.message); }
    });
    testCard.querySelectorAll('[data-del-test]').forEach(btn => btn.addEventListener('click', async () => {
      await api('test_delete', { method: 'POST', body: { id: btn.dataset.delTest } });
      loadForLevel();
    }));
  }
  levelSel.addEventListener('change', loadForLevel);
  loadForLevel();
}

async function renderManagerSop() {
  await ensureCatalogCache();
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>SOP (tampil di dashboard Tutor & Member)</h2></div>`));

  const form = el(`
    <div class="card">
      <div class="field"><label>Judul</label><input type="text" id="sopJudul"></div>
      <div class="field"><label>Level (opsional — kosongkan untuk SOP umum)</label><select id="sopLevel"><option value="">- SOP umum -</option>${levelOptionsHtml()}</select></div>
      <div class="field"><label>Isi</label><textarea id="sopIsi"></textarea></div>
      <button class="btn" id="btnAddSop">Simpan SOP</button>
    </div>
  `);
  c.appendChild(form);
  $('#btnAddSop', form).addEventListener('click', async () => {
    try {
      await api('sop_create', { method: 'POST', body: { judul: $('#sopJudul', form).value, level_id: $('#sopLevel', form).value || null, isi: $('#sopIsi', form).value } });
      renderManagerSop();
    } catch (e) { alert(e.message); }
  });

  const data = await api('sop_list');
  data.sop.forEach(s => {
    const card = el(`<div class="card"><h3>${esc(s.judul)}</h3><p>${esc(s.isi)}</p></div>`);
    const del = el(`<button class="btn small danger">Hapus</button>`);
    del.addEventListener('click', async () => { await api('sop_delete', { method: 'POST', body: { id: s.id } }); renderManagerSop(); });
    card.appendChild(del);
    c.appendChild(card);
  });
}

async function renderManagerMonitoring() {
  const [keh, nilai, mat] = await Promise.all([
    api('monitoring_kehadiran'), api('monitoring_nilai'), api('monitoring_materi'),
  ]);
  const c = $('#content');
  c.innerHTML = '';

  c.appendChild(el(`<div class="section-title"><h2>Monitoring Kehadiran</h2></div>`));
  c.appendChild(el(`
    <div class="table-wrap card"><table><thead><tr><th>Level</th><th>Tutor</th><th>Total Tercatat</th><th>Hadir</th></tr></thead><tbody>
      ${keh.kehadiran.map(k => `<tr><td>${esc(k.level_nama)}</td><td>${esc(k.tutor_nama || '-')}</td><td>${k.total_absensi_tercatat}</td><td>${k.total_hadir || 0}</td></tr>`).join('') || '<tr><td colspan="4" class="muted">Belum ada data.</td></tr>'}
    </tbody></table></div>
  `));

  c.appendChild(el(`<div class="section-title"><h2>Monitoring Nilai</h2></div>`));
  c.appendChild(el(`
    <div class="table-wrap card"><table><thead><tr><th>Level</th><th>Jenis Test</th><th>Jumlah Dinilai</th><th>Rata-rata</th></tr></thead><tbody>
      ${nilai.nilai.map(n => `<tr><td>${esc(n.level_nama)}</td><td>${n.jenis}</td><td>${n.jumlah_dinilai}</td><td>${n.rata_rata ? Number(n.rata_rata).toFixed(1) : '-'}</td></tr>`).join('') || '<tr><td colspan="4" class="muted">Belum ada data.</td></tr>'}
    </tbody></table></div>
  `));

  c.appendChild(el(`<div class="section-title"><h2>Monitoring Materi Dibawakan Tutor</h2></div>`));
  c.appendChild(el(`
    <div class="table-wrap card"><table><thead><tr><th>Level</th><th>Tutor</th><th>Status</th><th>Sesi Tercatat</th><th>Sesi Terakhir</th></tr></thead><tbody>
      ${mat.materi.map(m => `<tr><td>${esc(m.level_nama)}</td><td>${esc(m.tutor_nama || '-')}</td><td>${esc(m.status)}</td><td>${m.jumlah_sesi_tercatat}</td><td>${fmtDate(m.sesi_terakhir)}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">Belum ada data.</td></tr>'}
    </tbody></table></div>
  `));
}

// =====================================================================
// ================== TUTOR ==================
// =====================================================================

async function renderTutorKelas() {
  const data = await api('kelas_list');
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Kelas Saya</h2></div>`));

  if (!data.kelas.length) { c.appendChild(el(`<div class="empty-state">Belum ada kelas yang dipegang.</div>`)); return; }

  const sel = el(`<select>${data.kelas.map(k => `<option value="${k.id}">${esc(k.level_nama)} (${esc(k.jadwal || '-')})</option>`).join('')}</select>`);
  const wrap = el('<div class="card"></div>');
  wrap.appendChild(el('<label>Pilih Kelas</label>'));
  wrap.appendChild(sel);
  c.appendChild(wrap);

  const box = el('<div id="tutorBox"></div>');
  c.appendChild(box);

  async function loadKelas() {
    const kelasId = sel.value;
    const [roster, tests, cuti] = await Promise.all([
      api('kelas_roster', { params: { kelas_id: kelasId } }),
      api('test_list', { params: { level_id: data.kelas.find(k => String(k.id) === String(kelasId)).level_id } }),
      api('cuti_list', { params: { kelas_id: kelasId } }),
    ]);
    box.innerHTML = '';

    // Absensi
    const today = new Date().toISOString().substring(0, 10);
    const absCard = el(`
      <div class="card">
        <h3>Absensi</h3>
        <div class="field"><label>Tanggal Sesi</label><input type="date" id="absTgl" value="${today}"></div>
        <table><thead><tr><th>Member</th><th>Hadir</th></tr></thead><tbody>
          ${roster.roster.map(r => `<tr><td>${esc(r.nama)}</td><td><input type="checkbox" checked data-enr="${r.enrollment_id}" class="absChk"></td></tr>`).join('')}
        </tbody></table>
        <button class="btn small" id="btnSimpanAbsen">Simpan Absensi</button>
      </div>
    `);
    box.appendChild(absCard);
    $('#btnSimpanAbsen', absCard).addEventListener('click', async () => {
      const records = Array.from(absCard.querySelectorAll('.absChk')).map(chk => ({ enrollment_id: chk.dataset.enr, hadir: chk.checked }));
      await api('absensi_input', { method: 'POST', body: { kelas_id: kelasId, tanggal_sesi: $('#absTgl', absCard).value, records } });
      alert('Absensi tersimpan.');
    });

    // Kegiatan harian
    const khCard = el(`
      <div class="card">
        <h3>Kegiatan Harian</h3>
        <div class="field"><label>Tanggal</label><input type="date" id="khTgl" value="${today}"></div>
        <div class="field"><label>Catatan materi/kegiatan</label><textarea id="khCatatan"></textarea></div>
        <button class="btn small" id="btnSimpanKh">Simpan</button>
      </div>
    `);
    box.appendChild(khCard);
    $('#btnSimpanKh', khCard).addEventListener('click', async () => {
      await api('kegiatan_input', { method: 'POST', body: { kelas_id: kelasId, tanggal: $('#khTgl', khCard).value, catatan: $('#khCatatan', khCard).value } });
      $('#khCatatan', khCard).value = '';
      alert('Kegiatan tersimpan.');
    });

    // Zoom link
    const zoomCard = el(`
      <div class="card">
        <h3>Link Zoom Sesi</h3>
        <div class="grid2">
          <div class="field"><label>Tanggal Sesi</label><input type="date" id="zTgl" value="${today}"></div>
          <div class="field"><label>Link Zoom</label><input type="text" id="zLink"></div>
          <div class="field"><label>Link Record (opsional, isi setelah sesi selesai)</label><input type="text" id="zRecord"></div>
        </div>
        <button class="btn small" id="btnSimpanZoom">Simpan</button>
      </div>
    `);
    box.appendChild(zoomCard);
    $('#btnSimpanZoom', zoomCard).addEventListener('click', async () => {
      await api('zoom_input', { method: 'POST', body: { kelas_id: kelasId, tanggal_sesi: $('#zTgl', zoomCard).value, link: $('#zLink', zoomCard).value, link_record: $('#zRecord', zoomCard).value } });
      alert('Link Zoom tersimpan & member sudah dinotifikasi.');
    });

    // Test akses + nilai
    const testCard = el(`
      <div class="card">
        <h3>Akses Test & Nilai</h3>
        ${tests.test.map(t => `
          <div style="border-top:1px solid var(--border);padding:10px 0;">
            <b>${t.jenis.toUpperCase()} — ${esc(t.judul)}</b>
            <label style="margin-left:10px;"><input type="checkbox" class="testTgl" data-test="${t.id}"> Buka akses ke Member</label>
            <table style="margin-top:6px;"><thead><tr><th>Member</th><th>Nilai</th></tr></thead><tbody>
              ${roster.roster.map(r => `<tr><td>${esc(r.nama)}</td><td><input type="number" step="0.1" style="width:70px;" data-enr="${r.enrollment_id}" data-test="${t.id}" class="nilaiInput"></td></tr>`).join('')}
            </tbody></table>
          </div>
        `).join('') || '<p class="muted">Belum ada test untuk Level ini — hubungi Manager.</p>'}
        <button class="btn small" id="btnSimpanTestNilai" style="margin-top:8px;">Simpan Akses & Nilai</button>
      </div>
    `);
    box.appendChild(testCard);
    $('#btnSimpanTestNilai', testCard).addEventListener('click', async () => {
      for (const chk of testCard.querySelectorAll('.testTgl')) {
        await api('test_akses_toggle', { method: 'POST', body: { kelas_id: kelasId, test_id: chk.dataset.test, terbuka: chk.checked } });
      }
      for (const inp of testCard.querySelectorAll('.nilaiInput')) {
        if (inp.value !== '') {
          await api('nilai_input', { method: 'POST', body: { enrollment_id: inp.dataset.enr, test_id: inp.dataset.test, skor: inp.value } });
        }
      }
      alert('Tersimpan.');
    });

    // Cuti masuk
    const cutiCard = el(`
      <div class="card">
        <h3>Pengajuan Cuti Member</h3>
        ${cuti.cuti.length
          ? `<table><thead><tr><th>Member</th><th>Tanggal Sesi</th><th>Keterangan</th></tr></thead><tbody>
              ${cuti.cuti.map(cu => `<tr><td>${esc(cu.member_nama)}</td><td>${fmtDate(cu.tanggal_sesi_dicuti)}</td><td>${esc(cu.keterangan || '-')}</td></tr>`).join('')}
            </tbody></table>`
          : '<p class="muted">Belum ada pengajuan cuti.</p>'}
      </div>
    `);
    box.appendChild(cutiCard);
  }
  sel.addEventListener('change', loadKelas);
  loadKelas();
}

// =====================================================================
// ================== SUPERADMIN ==================
// =====================================================================

async function renderSuperadminAkun() {
  const c = $('#content');
  c.innerHTML = '';
  c.appendChild(el(`<div class="section-title"><h2>Buat Akun (semua role)</h2></div>`));

  const form = el(`
    <div class="card">
      <div class="grid2">
        <div class="field"><label>Nama</label><input type="text" id="sNama"></div>
        <div class="field"><label>Username</label><input type="text" id="sUsername"></div>
        <div class="field"><label>Role</label>
          <select id="sRole">
            <option value="member">Member</option><option value="cs">CS</option><option value="admin">Admin</option>
            <option value="manager">Manager</option><option value="tutor">Tutor</option><option value="superadmin">Superadmin</option>
          </select>
        </div>
        <div class="field" id="sNoIdWrap"><label>No ID (khusus Member)</label><input type="text" id="sNoId"></div>
        <div class="field" id="sDobWrap"><label>Tanggal Lahir (khusus Member — jadi password default, BR-3)</label><input type="date" id="sDob"></div>
      </div>
      <button class="btn" id="btnBuatAkun">Buat Akun</button>
    </div>
  `);
  c.appendChild(form);
  $('#btnBuatAkun', form).addEventListener('click', async () => {
    try {
      const res = await api('users_create', {
        method: 'POST',
        body: {
          nama: $('#sNama', form).value, username: $('#sUsername', form).value, role: $('#sRole', form).value,
          no_id: $('#sNoId', form).value || null, tanggal_lahir: $('#sDob', form).value || null,
        },
      });
      alert('Akun dibuat. Password awal: ' + res.password_awal);
      renderSuperadminAkun();
    } catch (e) { alert(e.message); }
  });

  c.appendChild(el(`<div class="section-title"><h2>Semua Akun</h2></div>`));
  const roleFilter = el(`
    <div class="toolbar">
      <select id="filterRole">
        <option value="">Semua Role</option>
        <option value="member">Member</option><option value="cs">CS</option><option value="admin">Admin</option>
        <option value="manager">Manager</option><option value="tutor">Tutor</option><option value="superadmin">Superadmin</option>
      </select>
    </div>
  `);
  c.appendChild(roleFilter);
  const listBox = el('<div class="table-wrap card"></div>');
  c.appendChild(listBox);

  async function loadUsers() {
    const role = $('#filterRole', roleFilter).value;
    const data = await api('users_list', { params: role ? { role } : {} });
    listBox.innerHTML = `<table><thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>
      ${data.users.map(u => `<tr><td>${esc(u.nama)}</td><td>${esc(u.username)}</td><td>${esc(u.role)}</td>
        <td><span class="pill ${u.is_active ? 'ok' : 'err'}">${u.is_active ? 'Aktif' : 'Nonaktif'}</span></td>
        <td><button class="btn small ${u.is_active ? 'danger' : 'secondary'}" data-toggle="${u.id}" data-now="${u.is_active}">${u.is_active ? 'Nonaktifkan' : 'Aktifkan'}</button></td></tr>`).join('')}
    </tbody></table>`;
    listBox.querySelectorAll('[data-toggle]').forEach(btn => btn.addEventListener('click', async () => {
      try {
        await api('users_set_active', { method: 'POST', body: { id: btn.dataset.toggle, aktif: btn.dataset.now !== '1' } });
        loadUsers();
      } catch (e) { alert(e.message); }
    }));
  }
  $('#filterRole', roleFilter).addEventListener('change', loadUsers);
  loadUsers();
}

// ---------------------------------------------------------------------
// Boot: cek sesi aktif (refresh halaman tanpa logout)
// ---------------------------------------------------------------------
(async function boot() {
  try {
    const data = await api('me');
    onLoggedIn(data.user);
  } catch (e) {
    // belum login — tetap di layar login
  }
})();
