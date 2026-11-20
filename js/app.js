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
});
