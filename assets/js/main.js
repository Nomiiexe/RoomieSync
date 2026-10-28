document.addEventListener('DOMContentLoaded', () => {
  // Password Visibility Toggle
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

  // Mobile Navigation Drawer Toggle
  const mobileNavToggle = document.getElementById('mobileNavToggle');
  const navLinksContainer = document.getElementById('navLinksContainer');
  if (mobileNavToggle && navLinksContainer) {
    mobileNavToggle.addEventListener('click', () => {
      navLinksContainer.classList.toggle('show-mobile');
    });
  }

  // Keep navigation placeholders from changing the page until their pages exist.
  const disabledNavLinks = document.querySelectorAll('.rs-nav-link[aria-disabled="true"], .rs-dropdown-item[aria-disabled="true"]');
  disabledNavLinks.forEach(link => {
    link.addEventListener('click', (e) => e.preventDefault());
  });

  // 1-Click Copy Invite Code Button
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

  // Live Table Search
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

  // Table Filter Dropdowns
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

  // Tab Filtering for Chores and List Items
  const filterTabs = document.querySelectorAll('.rs-filter-tab');
  filterTabs.forEach(tab => {
    tab.addEventListener('click', (e) => {
      e.preventDefault();
      const parent = tab.closest('.rs-nav-pills');
      if (parent) {
        parent.querySelectorAll('.rs-filter-tab').forEach(t => t.classList.remove('active'));
      }
      tab.classList.add('active');
      const filter = tab.getAttribute('data-filter') || 'all';
      const targetSelector = tab.getAttribute('data-target-items') || '.rs-filter-item';
      const items = document.querySelectorAll(targetSelector);
      items.forEach(item => {
        const status = item.getAttribute('data-status') || '';
        if (filter === 'all' || status === filter) {
          item.style.display = '';
        } else {
          item.style.display = 'none';
        }
      });
    });
  });

  // Dynamic Chore Checkbox Status Toggle
  const choreCheckboxes = document.querySelectorAll('.rs-chore-check');
  choreCheckboxes.forEach(checkbox => {
    checkbox.addEventListener('change', () => {
      const item = checkbox.closest('.rs-chore-item') || checkbox.closest('.list-group-item');
      const label = item ? item.querySelector('.rs-chore-title') || item.querySelector('label') : null;
      const badge = item ? item.querySelector('.rs-chore-badge') || item.querySelector('.rs-badge') : null;

      if (checkbox.checked) {
        if (label) label.classList.add('text-decoration-line-through', 'text-muted');
        if (badge) {
          badge.className = 'rs-badge rs-badge-completed rs-chore-badge';
          badge.innerHTML = '<span class="rs-badge-dot"></span> Completed';
        }
        if (item) item.setAttribute('data-status', 'completed');
        showToast('Chore marked as completed! Nice work!', 'success');
      } else {
        if (label) label.classList.remove('text-decoration-line-through', 'text-muted');
        if (badge) {
          badge.className = 'rs-badge rs-badge-pending rs-chore-badge';
          badge.innerHTML = '<span class="rs-badge-dot"></span> Pending';
        }
        if (item) item.setAttribute('data-status', 'pending');
        showToast('Chore marked as pending.', 'info');
      }
    });
  });

  // Delete Action Buttons
  const deleteButtons = document.querySelectorAll('.rs-btn-delete');
  deleteButtons.forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      const itemTitle = btn.getAttribute('data-title') || 'this item';
      const confirmed = await showConfirmDialog(`Are you sure you want to delete ${itemTitle}?`, {
        title: 'Confirm Deletion',
        confirmText: 'Delete',
        variant: 'danger'
      });
      if (confirmed) {
        const target = btn.closest('tr') || btn.closest('.rs-card') || btn.closest('.list-group-item');
        if (target) {
          target.style.transition = 'all 0.3s ease';
          target.style.opacity = '0';
          target.style.transform = 'scale(0.95)';
          setTimeout(() => target.remove(), 300);
        }
        showToast(`Deleted ${itemTitle}`, 'info');
      }
    });
  });

  // Mark Bill Paid Action
  const markBillPaidButtons = document.querySelectorAll('.btn-mark-bill-paid');
  markBillPaidButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const card = btn.closest('.rs-bill-card') || btn.closest('.rs-card');
      const badge = card ? card.querySelector('.rs-bill-badge') : null;
      if (badge) {
        badge.className = 'rs-badge rs-badge-paid rs-bill-badge';
        badge.innerHTML = '<span class="rs-badge-dot"></span> Paid';
      }
      btn.classList.add('disabled');
      btn.innerHTML = '<i class="bi bi-check2"></i> Paid';
      showToast('Bill marked as paid successfully!', 'success');
    });
  });

  // Photo Upload Live Preview
  const imageInputs = document.querySelectorAll('.rs-image-input');
  imageInputs.forEach(input => {
    input.addEventListener('change', (e) => {
      const file = e.target.files[0];
      const previewContainer = document.getElementById(input.getAttribute('data-preview-target'));
      if (file && previewContainer) {
        const reader = new FileReader();
        reader.onload = (event) => {
          previewContainer.innerHTML = `
            <img src="${event.target.result}" alt="Preview" class="img-fluid rounded-3 mb-2" style="max-height: 180px; object-fit: cover; width: 100%;">
            <p class="small text-success mb-0 fw-semibold"><i class="bi bi-check-circle-fill me-1"></i> ${file.name} selected</p>
          `;
        };
        reader.readAsDataURL(file);
      }
    });
  });

  // Client-Side Simulated Form Handlers for Modals (ready for future PHP POST)
  const simulatedForms = document.querySelectorAll('.rs-interactive-form');
  simulatedForms.forEach(form => {
    form.addEventListener('submit', (e) => {
      // If no backend endpoint or standalone preview mode, show toast and close modal smoothly
      const action = form.getAttribute('action') || '';
      if (!action || action === '#' || action.endsWith('.html') || !action.endsWith('.php')) {
        e.preventDefault();
        const successMsg = form.getAttribute('data-success-msg') || 'Action completed successfully!';
        const modalEl = form.closest('.modal');
        if (modalEl && typeof bootstrap !== 'undefined') {
          const modalInstance = bootstrap.Modal.getInstance(modalEl);
          if (modalInstance) modalInstance.hide();
        }
        showToast(successMsg, 'success');
        form.reset();
      }
    });
  });

  // Print Report Handler
  const printButtons = document.querySelectorAll('.btn-print-report');
  printButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.print();
    });
  });

  // Export Handler (Excel & CSV)
  const exportButtons = document.querySelectorAll('.btn-export-report, a[href*="export=excel"], a[href*="export=xls"], a[href*="export=csv"]');
  exportButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const href = btn.getAttribute('href') || '';
      const isExcel = href.includes('export=excel') || href.includes('export=xls');
      showToast(
        isExcel ? 'Preparing MS Excel report (.xls)... starting download.' : 'Preparing report export... starting download.',
        'success'
      );
    });
  });
});

// Toast Notification Utility Function
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
    toast.style.transform = 'translateY(8px)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}

// Global Pagination Page-Size Change Handler
function rsHandlePageSizeChange(selectElement) {
  if (!selectElement) return;
  const newSize = selectElement.value;
  const pageKey = selectElement.getAttribute('data-page-key') || 'page';
  const perPageKey = selectElement.getAttribute('data-per-page-key') || 'per_page';
  const anchor = selectElement.getAttribute('data-anchor') || '';

  const url = new URL(window.location.href);
  url.searchParams.set(perPageKey, newSize);
  url.searchParams.set(pageKey, '1'); // Reset to page 1 on per-page change
  if (anchor) {
    url.hash = anchor.replace(/^#/, '');
  }
  window.location.href = url.toString();
}
window.rsHandlePageSizeChange = rsHandlePageSizeChange;

/**
 * In-App Themed Confirmation Dialog (Replaces native browser window.confirm)
 * Returns a Promise<boolean> resolving true if confirmed, false if cancelled/closed.
 */
function showConfirmDialog(message, options = {}) {
  return new Promise((resolve) => {
    let modalEl = document.getElementById('rsConfirmModal');
    if (!modalEl) {
      modalEl = document.createElement('div');
      modalEl.id = 'rsConfirmModal';
      modalEl.className = 'modal fade';
      modalEl.tabIndex = -1;
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.innerHTML = `
        <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
          <div class="modal-content rs-modal border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background-color: var(--rs-card-secondary, #faf7f4);">
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center" id="rsConfirmIconBox" style="width: 36px; height: 36px; font-size: 1.15rem;">
                  <i class="bi bi-question-circle-fill" id="rsConfirmIcon"></i>
                </div>
                <h5 class="modal-title fw-bold text-main mb-0 fs-6" id="rsConfirmTitle">Confirmation</h5>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="rsConfirmCloseBtn"></button>
            </div>
            <div class="modal-body py-4 px-4 text-secondary-custom" id="rsConfirmMessage" style="font-size: 0.95rem; line-height: 1.55; color: #4b5563;">
            </div>
            <div class="modal-footer border-top py-3 px-4 d-flex justify-content-end gap-2" style="background-color: #fff;">
              <button type="button" class="btn btn-rs-outline btn-sm px-3" data-bs-dismiss="modal" id="rsConfirmCancelBtn">Cancel</button>
              <button type="button" class="btn btn-rs-primary btn-sm px-3" id="rsConfirmOkBtn">Confirm</button>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modalEl);
    }

    const titleEl = modalEl.querySelector('#rsConfirmTitle');
    const msgEl = modalEl.querySelector('#rsConfirmMessage');
    const okBtn = modalEl.querySelector('#rsConfirmOkBtn');
    const cancelBtn = modalEl.querySelector('#rsConfirmCancelBtn');
    const iconBox = modalEl.querySelector('#rsConfirmIconBox');
    const iconEl = modalEl.querySelector('#rsConfirmIcon');

    const title = options.title || 'Confirmation';
    const confirmText = options.confirmText || 'Confirm';
    const cancelText = options.cancelText || 'Cancel';
    const variant = options.variant || 'primary';

    titleEl.textContent = title;
    msgEl.textContent = message;
    okBtn.textContent = confirmText;
    cancelBtn.textContent = cancelText;

    okBtn.className = 'btn btn-sm px-3';
    if (variant === 'danger') {
      okBtn.classList.add('btn-danger');
      iconBox.style.background = '#fee2e2';
      iconBox.style.color = '#dc2626';
      iconEl.className = 'bi bi-exclamation-triangle-fill';
    } else if (variant === 'success') {
      okBtn.classList.add('btn-success');
      iconBox.style.background = '#dcfce7';
      iconBox.style.color = '#16a34a';
      iconEl.className = 'bi bi-check2-circle';
    } else {
      okBtn.classList.add('btn-rs-primary');
      iconBox.style.background = '#fef3c7';
      iconBox.style.color = '#d97706';
      iconEl.className = 'bi bi-question-circle-fill';
    }

    let settled = false;
    let bsModal = (typeof bootstrap !== 'undefined' && bootstrap.Modal)
      ? (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true }))
      : null;

    const showHandler = () => {
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 0) {
          const topBackdrop = backdrops[backdrops.length - 1];
          topBackdrop.classList.add('rs-confirm-backdrop');
          topBackdrop.style.zIndex = '1075';
        }
      }, 10);
    };
    modalEl.addEventListener('shown.bs.modal', showHandler, { once: true });

    const handleConfirm = () => {
      if (!settled) {
        settled = true;
        if (bsModal) bsModal.hide();
        resolve(true);
      }
    };

    const handleDismiss = () => {
      if (!settled) {
        settled = true;
        resolve(false);
      }
    };

    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    newOkBtn.addEventListener('click', handleConfirm);

    const hiddenHandler = () => {
      handleDismiss();
      modalEl.removeEventListener('hidden.bs.modal', hiddenHandler);
      if (document.querySelectorAll('.modal.show').length > 0) {
        document.body.classList.add('modal-open');
      }
    };
    modalEl.addEventListener('hidden.bs.modal', hiddenHandler);

    if (bsModal) {
      bsModal.show();
    } else {
      resolve(window.confirm(message));
    }
  });
}
window.showConfirmDialog = showConfirmDialog;

// Intercept forms configured with data-confirm
document.addEventListener('submit', async (e) => {
  const form = e.target;
  if (!form || !form.getAttribute) return;
  const confirmMsg = form.getAttribute('data-confirm');
  if (confirmMsg && form.getAttribute('data-confirmed') !== 'true') {
    e.preventDefault();
    const title = form.getAttribute('data-confirm-title') || 'Confirmation';
    const confirmText = form.getAttribute('data-confirm-btn') || 'Confirm';
    const variant = form.getAttribute('data-confirm-variant') || 'primary';
    const ok = await showConfirmDialog(confirmMsg, { title, confirmText, variant });
    if (ok) {
      form.setAttribute('data-confirmed', 'true');
      form.submit();
    }
  }
});
