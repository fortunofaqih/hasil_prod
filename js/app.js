/* PT. MCP - small progressive enhancements */
document.addEventListener('DOMContentLoaded', function () {
  // Confirm before destructive / decision actions.
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (event) {
      if (!window.confirm(el.dataset.confirm || 'Yakin lanjutkan?')) {
        event.preventDefault();
      }
    });
  });

  // Auto-hide alerts after 6 seconds.
  var alert = document.querySelector('.alert-dismissible');
  if (alert) {
    window.setTimeout(function () {
      var instance = bootstrap.Alert.getOrCreateInstance(alert);
      instance.close();
    }, 6000);
  }

  // Toggle "rencana potong" fields based on process type.
  var processSelect = document.getElementById('jenis_proses');
  if (processSelect) {
    var potongFields = document.querySelectorAll('.potong-field');
    var toggle = function () {
      var show = processSelect.value === 'potong';
      potongFields.forEach(function (field) {
        field.classList.toggle('d-none', !show);
        field.querySelectorAll('input').forEach(function (input) {
          input.disabled = !show;
          if (!show) { input.value = ''; }
        });
      });
    };
    processSelect.addEventListener('change', toggle);
    toggle();
  }

  // Dinamis: tombol + Tambah Item pada form barang kembalian / komplain.
  var itemTable = document.getElementById('itemTable');
  var tambahItem = document.getElementById('tambahItem');
  if (itemTable && tambahItem) {
    tambahItem.addEventListener('click', function () {
      var rows = itemTable.querySelectorAll('.item-row');
      if (!rows.length) { return; }
      var baris = rows[rows.length - 1].cloneNode(true);
      baris.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      baris.querySelectorAll('select').forEach(function (select) {
        if (select.selectedIndex >= 0) { select.selectedIndex = 0; }
      });
      itemTable.querySelector('tbody').appendChild(baris);
    });

    itemTable.addEventListener('click', function (event) {
      if (!event.target.classList.contains('hapus-item')) { return; }
      if (itemTable.querySelectorAll('.item-row').length > 1) {
        event.target.closest('tr').remove();
      }
    });
  }

  // Tabel panjang: pencarian cepat + tombol tampilkan semua baris.
  document.querySelectorAll('input[data-filter-table]').forEach(function (input) {
    input.addEventListener('input', function () {
      var target = document.getElementById(this.dataset.filterTable);
      if (!target) { return; }
      var q = this.value.toLowerCase();
      target.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.classList.toggle('d-none', tr.textContent.toLowerCase().indexOf(q) === -1);
      });
    });
  });

  document.querySelectorAll('[data-toggle-rows]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(this.dataset.toggleRows);
      if (!target) { return; }
      var extra = target.querySelectorAll('.baris-extra');
      if (!extra.length) { return; }
      var tampilkan = extra[0].classList.contains('d-none');
      extra.forEach(function (tr) { tr.classList.toggle('d-none', !tampilkan); });
      this.textContent = tampilkan ? 'Tampilkan 8 terbaru' : 'Tampilkan semua';
    });
  });

  // Peningkatan otomatis untuk tabel daftar bertanda data-scalable.
  document.querySelectorAll('table[data-scalable]').forEach(function (table, index) {
    if (!table.id) { table.id = 'tabelScalable' + index; }
    var wrap = table.closest('.table-responsive-mcp') || table.parentElement;
    if (wrap) { wrap.classList.add('table-scroll-box'); }

    var toolbar = document.createElement('div');
    toolbar.className = 'table-toolbar';
    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-control form-control-sm';
    input.placeholder = table.dataset.searchPlaceholder || 'Cari data...';
    input.setAttribute('aria-label', input.placeholder);
    toolbar.appendChild(input);

    var hint = document.createElement('div');
    hint.className = 'mobile-scroll-hint';
    hint.textContent = 'Geser tabel ke samping untuk melihat kolom lain \u2192';
    if (wrap && wrap.parentNode) {
      wrap.parentNode.insertBefore(toolbar, wrap);
      wrap.parentNode.insertBefore(hint, wrap);
    }

    var semuaBaris = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    if (semuaBaris.length > 8) {
      semuaBaris.slice(8).forEach(function (tr) { tr.classList.add('baris-extra', 'd-none'); });
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-sm btn-outline-secondary';
      button.textContent = 'Tampilkan semua';
      toolbar.appendChild(button);
      button.addEventListener('click', function () {
        var extra = table.querySelectorAll('.baris-extra');
        var tampilkan = extra[0].classList.contains('d-none');
        extra.forEach(function (tr) { tr.classList.toggle('d-none', !tampilkan); });
        button.textContent = tampilkan ? 'Tampilkan 8 terbaru' : 'Tampilkan semua';
      });
    }

    input.addEventListener('input', function () {
      var q = this.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        tr.classList.toggle('d-none', tr.textContent.toLowerCase().indexOf(q) === -1);
      });
    });
  });

  // Perbandingan KG marketing vs berat aktual gudang (langsung saat diketik).
  document.querySelectorAll('.berat-aktual').forEach(function (input) {
    function hitung() {
      var baris = input.closest('tr');
      var sel = baris ? baris.querySelector('.kg-selisih') : null;
      if (!sel) { return; }
      var kgMarketing = parseFloat(input.dataset.kgMarketing || '0');
      var aktual = parseFloat(input.value || '0');
      var beda = aktual - kgMarketing;
      if (Math.abs(beda) < 0.005) {
        sel.innerHTML = '<span class="badge bg-success">Sama</span>';
      } else {
        sel.innerHTML = '<span class="badge bg-warning text-dark">Selisih ' +
          Math.abs(beda).toFixed(2).replace('.', ',') + ' KG</span>';
      }
    }
    input.addEventListener('input', hitung);
    hitung();
  });

  // Data CLOSE: form hanya bisa dilihat, tidak bisa diubah.
  document.querySelectorAll('form[data-readonly]').forEach(function (form) {
    form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
      if (el.type === 'submit' || el.type === 'button') { el.remove(); }
      else { el.disabled = true; }
    });
    var info = document.createElement('div');
    info.className = 'alert alert-info small';
    info.innerHTML = '<i class="bi bi-lock"></i> Data sudah CLOSE. Hanya bisa dilihat, tidak bisa diubah.';
    form.prepend(info);
  });
});
