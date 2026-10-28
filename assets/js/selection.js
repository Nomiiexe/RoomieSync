/**
 * RoomieSync Reusable Multi-Select System
 * Handles table row and card selections, "Select All", count badges,
 * contextual floating toolbar, and secure bulk actions with confirmation.
 */
class RoomieSyncSelection {
  constructor(options = {}) {
    this.container = typeof options.container === 'string'
      ? document.querySelector(options.container)
      : (options.container || document);

    if (!this.container) return;

    this.selectAllSelector = options.selectAllSelector || '.rs-select-all';
    this.itemSelector = options.itemSelector || '.rs-select-item';
    this.rowSelector = options.rowSelector || 'tr, .announcement-card, .rs-selectable-card';
    this.toolbar = typeof options.toolbar === 'string'
      ? document.querySelector(options.toolbar)
      : (options.toolbar || document.getElementById('rsBulkToolbar'));
    this.countElement = options.countElement || document.querySelector('.rs-bulk-count');
    this.clearBtn = options.clearBtn || document.querySelector('.rs-bulk-clear');
    this.form = typeof options.form === 'string'
      ? document.querySelector(options.form)
      : (options.form || document.getElementById('rsBulkForm'));
    this.actionUrl = options.actionUrl || '';
    this.csrfToken = options.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';

    this.init();
  }

  init() {
    this.bindEvents();
    this.updateState();
  }

  getItems() {
    return Array.from(this.container.querySelectorAll(this.itemSelector));
  }

  getSelectAll() {
    return this.container.querySelectorAll(this.selectAllSelector);
  }

  getSelectedIds() {
    return this.getItems()
      .filter(item => item.checked && !item.disabled)
      .map(item => item.value);
  }

  bindEvents() {
    // Select All Checkbox(es)
    this.getSelectAll().forEach(selectAll => {
      selectAll.addEventListener('change', (e) => {
        const checked = e.target.checked;
        this.getItems().forEach(item => {
          if (!item.disabled) {
            item.checked = checked;
            this.updateRowHighlight(item);
          }
        });
        this.updateState();
      });
    });

    // Individual Item Checkboxes
    this.container.addEventListener('change', (e) => {
      if (e.target.matches(this.itemSelector)) {
        this.updateRowHighlight(e.target);
        this.updateState();
      }
    });

    // Clear Selection Button
    if (this.clearBtn) {
      this.clearBtn.addEventListener('click', () => {
        this.clearSelection();
      });
    }

    // Action Buttons in Toolbar
    if (this.toolbar) {
      const actionButtons = this.toolbar.querySelectorAll('[data-bulk-action]');
      actionButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const action = btn.dataset.bulkAction;
          const confirmMsg = btn.dataset.confirm;
          this.executeBulkAction(action, confirmMsg);
        });
      });
    }
  }

  updateRowHighlight(checkbox) {
    const row = checkbox.closest(this.rowSelector);
    if (row) {
      row.classList.toggle('is-selected', checkbox.checked);
    }
  }

  updateState() {
    const items = this.getItems().filter(i => !i.disabled);
    const selected = items.filter(i => i.checked);
    const count = selected.length;

    // Update Select All Checkboxes & Indeterminate State
    this.getSelectAll().forEach(selectAll => {
      if (items.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
      } else if (count === items.length) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
      } else if (count > 0) {
        selectAll.checked = false;
        selectAll.indeterminate = true;
      } else {
        selectAll.checked = false;
        selectAll.indeterminate = false;
      }
    });

    // Update Counter Elements
    if (this.countElement) {
      this.countElement.textContent = count;
    }
    document.querySelectorAll('.rs-bulk-count').forEach(el => {
      el.textContent = count;
    });

    // Show or Hide Toolbar
    if (this.toolbar) {
      if (count > 0) {
        this.toolbar.classList.add('show');
        this.toolbar.setAttribute('aria-hidden', 'false');
      } else {
        this.toolbar.classList.remove('show');
        this.toolbar.setAttribute('aria-hidden', 'true');
      }
    }
  }

  clearSelection() {
    this.getItems().forEach(item => {
      item.checked = false;
      this.updateRowHighlight(item);
    });
    this.updateState();
  }

  async executeBulkAction(action, confirmMsg) {
    const selectedIds = this.getSelectedIds();
    if (selectedIds.length === 0) {
      if (typeof showToast === 'function') {
        showToast('Please select at least one record.', 'error');
      } else {
        alert('Please select at least one record.');
      }
      return;
    }

    if (confirmMsg) {
      const formattedMsg = confirmMsg.replace('{count}', selectedIds.length);
      const isDelete = action.toLowerCase().includes('delete');
      const isPaid = action.toLowerCase().includes('paid') || action.toLowerCase().includes('settled');
      const variant = isDelete ? 'danger' : (isPaid ? 'success' : 'primary');
      const title = isDelete ? 'Confirm Deletion' : (isPaid ? 'Confirm Payment Status' : 'Confirm Bulk Action');
      const confirmText = isDelete ? 'Delete Selected' : (isPaid ? 'Confirm' : 'Continue');

      if (typeof showConfirmDialog === 'function') {
        const ok = await showConfirmDialog(formattedMsg, {
          title,
          confirmText,
          variant
        });
        if (!ok) return;
      } else if (!window.confirm(formattedMsg)) {
        return;
      }
    }

    if (!this.form) {
      console.error('No bulk form found to submit.');
      return;
    }

    // Set action input
    let actionInput = this.form.querySelector('input[name="bulk_action"]');
    if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'bulk_action';
      this.form.appendChild(actionInput);
    }
    actionInput.value = action;

    // Clean old ID inputs
    this.form.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());

    // Append selected IDs
    selectedIds.forEach(id => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'selected_ids[]';
      input.value = id;
      this.form.appendChild(input);
    });

    this.form.submit();
  }
}

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  const selectionElements = document.querySelectorAll('[data-rs-selection]');
  selectionElements.forEach(container => {
    new RoomieSyncSelection({
      container: container,
      toolbar: container.dataset.toolbar || '#rsBulkToolbar',
      form: container.dataset.form || '#rsBulkForm',
    });
  });
});
