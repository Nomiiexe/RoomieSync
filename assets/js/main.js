document.addEventListener('DOMContentLoaded', () => {
  const passwordToggles = document.querySelectorAll('.toggle-password-btn');
  passwordToggles.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const targetInputId = btn.getAttribute('data-target');
      const input = document.getElementById(targetInputId);
      if (input) {
        const isPassword = input.getAttribute('type') === 'password';
        input.setAttribute('type', isPassword ? 'text' : 'password');
        const icon = btn.querySelector('i');
        if (icon) {
          icon.classList.toggle('bi-eye', !isPassword);
          icon.classList.toggle('bi-eye-slash', isPassword);
        }
      }
    });
  });

  const mobileNavToggle = document.getElementById('mobileNavToggle');
  const navLinksContainer = document.getElementById('navLinksContainer');
  if (mobileNavToggle && navLinksContainer) {
    mobileNavToggle.addEventListener('click', () => {
      navLinksContainer.classList.toggle('show-mobile');
    });
  }

  const copyButtons = document.querySelectorAll('.btn-copy-code');
  copyButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const code = btn.getAttribute('data-code') || '';
      if (!code) return;
      navigator.clipboard.writeText(code).then(() => {
        showToast(`Join code "${code}" copied to clipboard!`, 'success');
      }).catch(() => {
        const temp = document.createElement('input');
        temp.value = code;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        showToast(`Join code "${code}" copied to clipboard!`, 'success');
      });
    });
  });

  const tableSearchInputs = document.querySelectorAll('.rs-table-search');
  tableSearchInputs.forEach(input => {
    const targetTableId = input.getAttribute('data-target');
    const table = document.getElementById(targetTableId);
    if (table) {
      input.addEventListener('input', () => {
        const term = input.value.toLowerCase().trim();
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(term) ? '' : 'none';
        });
      });
    }
  });

  const tableFilterSelects = document.querySelectorAll('.rs-table-filter');
  tableFilterSelects.forEach(select => {
    const targetTableId = select.getAttribute('data-target');
    const colIndex = parseInt(select.getAttribute('data-col'), 10);
    const table = document.getElementById(targetTableId);
    if (table && !isNaN(colIndex)) {
      select.addEventListener('change', () => {
        const selectedValue = select.value.toLowerCase().trim();
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
          const cells = row.querySelectorAll('td');
          if (cells.length > colIndex) {
            const cellText = cells[colIndex].textContent.toLowerCase();
            if (selectedValue === '' || selectedValue === 'all' || cellText.includes(selectedValue)) {
              row.style.display = '';
            } else {
              row.style.display = 'none';
            }
          }
        });
      });
    }
  });

  const deleteButtons = document.querySelectorAll('.rs-btn-delete');
  deleteButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      const itemTitle = btn.getAttribute('data-title') || 'this item';
      if (confirm(`Are you sure you want to delete ${itemTitle}?`)) {
        const row = btn.closest('tr');
        if (row) {
          row.style.opacity = '0.4';
          setTimeout(() => {
            row.remove();
          }, 300);
        }
        showToast(`Deleted ${itemTitle}`, 'info');
      }
    });
  });

  const printButtons = document.querySelectorAll('.btn-print-report');
  printButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      window.print();
    });
  });

  const exportButtons = document.querySelectorAll('.btn-export-report');
  exportButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      showToast('Report export initiated', 'info');
    });
  });
});

function showToast(message, type = 'info') {
  let container = document.querySelector('.toast-container-custom');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container-custom';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = 'rs-toast';
  
  let iconClass = 'bi-info-circle-fill text-dark-accent';
  if (type === 'success') iconClass = 'bi-check-circle-fill text-success';
  if (type === 'error') iconClass = 'bi-exclamation-triangle-fill text-danger';

  toast.innerHTML = `
    <i class="bi ${iconClass} fs-5"></i>
    <div class="flex-grow-1" style="font-size: 0.88rem; font-weight: 500;">${message}</div>
    <button type="button" class="btn-close ms-2" style="font-size: 0.75rem;" aria-label="Close"></button>
  `;

  const closeBtn = toast.querySelector('.btn-close');
  closeBtn.addEventListener('click', () => toast.remove());

  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}
