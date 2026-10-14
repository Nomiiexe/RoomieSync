document.addEventListener('DOMContentLoaded', () => {
  // Mobile Nav Toggle
  const mobileToggle = document.getElementById('mobileNavToggle');
  const navContainer = document.getElementById('navLinksContainer');
  if (mobileToggle && navContainer) {
    mobileToggle.addEventListener('click', () => {
      navContainer.classList.toggle('show');
    });
  }

  // Setup Share Split Calculation for Add and Edit Forms
  function setupShareCalculator(formId, prefix) {
    const form = document.getElementById(formId);
    if (!form) return;

    const amountInput = form.querySelector(`input[name="amount"]`);
    const splitBtn = form.querySelector(`.${prefix}-split-equally`);
    const memberCheckboxes = form.querySelectorAll(`.${prefix}-member-checkbox`);
    const memberShareInputs = form.querySelectorAll(`.${prefix}-share-input`);
    const shareTotalEl = form.querySelector(`.${prefix}-shares-total`);
    const shareDifferenceEl = form.querySelector(`.${prefix}-shares-difference`);

    function calculateTotalAssigned() {
      let sum = 0.0;
      memberCheckboxes.forEach((checkbox) => {
        const memberId = checkbox.value;
        const input = form.querySelector(`input[name="shares[${memberId}]"]`);
        if (checkbox.checked && input) {
          const val = parseFloat(input.value) || 0;
          sum += val;
        }
      });
      return Math.round(sum * 100) / 100;
    }

    function updateSummary() {
      const billAmount = Math.round((parseFloat(amountInput?.value) || 0) * 100) / 100;
      const assignedSum = calculateTotalAssigned();
      const diff = Math.round((billAmount - assignedSum) * 100) / 100;

      if (shareTotalEl) {
        shareTotalEl.textContent = `₱${assignedSum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      }

      if (shareDifferenceEl) {
        if (diff === 0 && billAmount > 0) {
          shareDifferenceEl.className = 'small fw-bold text-success';
          shareDifferenceEl.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Shares match total perfectly';
        } else if (diff > 0) {
          shareDifferenceEl.className = 'small fw-bold text-warning';
          shareDifferenceEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ₱${diff.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} unassigned`;
        } else {
          shareDifferenceEl.className = 'small fw-bold text-danger';
          shareDifferenceEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Shares exceed total by ₱${Math.abs(diff).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
      }
    }

    function splitEqually() {
      const billAmount = parseFloat(amountInput?.value) || 0;
      const checkedBoxes = Array.from(memberCheckboxes).filter(cb => cb.checked);

      if (checkedBoxes.length === 0) {
        alert('Please check at least one household member to divide the bill.');
        return;
      }

      if (billAmount <= 0) {
        alert('Please enter a total bill amount first.');
        amountInput?.focus();
        return;
      }

      const count = checkedBoxes.length;
      const baseShare = Math.floor((billAmount / count) * 100) / 100;
      let remainder = Math.round((billAmount - (baseShare * count)) * 100) / 100;

      checkedBoxes.forEach((cb, index) => {
        const input = form.querySelector(`input[name="shares[${cb.value}]"]`);
        if (input) {
          let share = baseShare;
          if (remainder > 0.0001) {
            share = Math.round((share + 0.01) * 100) / 100;
            remainder = Math.round((remainder - 0.01) * 100) / 100;
          }
          input.value = share.toFixed(2);
        }
      });

      updateSummary();
    }

    if (splitBtn) {
      splitBtn.addEventListener('click', (e) => {
        e.preventDefault();
        splitEqually();
      });
    }

    if (amountInput) {
      amountInput.addEventListener('input', updateSummary);
    }

    memberCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        const memberId = checkbox.value;
        const row = checkbox.closest('.share-member-row');
        const input = form.querySelector(`input[name="shares[${memberId}]"]`);
        if (row) row.classList.toggle('is-selected', checkbox.checked);
        if (input) {
          input.disabled = !checkbox.checked;
          if (!checkbox.checked) {
            input.value = '0.00';
          }
        }
        updateSummary();
      });
    });

    memberShareInputs.forEach((input) => {
      input.addEventListener('input', updateSummary);
    });

    // Form submission validation
    form.addEventListener('submit', (e) => {
      const billAmount = Math.round((parseFloat(amountInput?.value) || 0) * 100) / 100;
      const assignedSum = calculateTotalAssigned();

      const checkedCount = Array.from(memberCheckboxes).filter(cb => cb.checked).length;
      if (checkedCount === 0) {
        if (form.getAttribute('data-optional-shares') === 'true') {
          return;
        }
        e.preventDefault();
        alert('Please select at least one household member for this bill.');
        return;
      }

      if (billAmount <= 0) {
        e.preventDefault();
        alert('Total amount must be greater than 0.');
        return;
      }

      if (Math.abs(assignedSum - billAmount) > 0.01) {
        e.preventDefault();
        alert(`The sum of member shares (₱${assignedSum.toFixed(2)}) must exactly equal the total amount (₱${billAmount.toFixed(2)}). Click 'Split Equally' or adjust the shares.`);
      }
    });

    updateSummary();
  }

  setupShareCalculator('addBillForm', 'add');
  setupShareCalculator('editBillForm', 'edit');
  setupShareCalculator('addExpenseForm', 'add-expense');
  setupShareCalculator('editExpenseForm', 'edit-expense');

  // View Bill Details Modal Handling
  const viewModal = document.getElementById('viewBillModal');
  if (viewModal) {
    document.querySelectorAll('.view-bill-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const billDataRaw = button.getAttribute('data-bill');
        if (!billDataRaw) return;

        try {
          const bill = JSON.parse(billDataRaw);

          document.getElementById('viewBillName').textContent = bill.bill_name || 'Bill Details';
          document.getElementById('viewCategory').textContent = bill.category_name || 'Uncategorized';
          document.getElementById('viewAmount').textContent = `₱${parseFloat(bill.amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
          document.getElementById('viewDueDate').textContent = bill.due_date_formatted || bill.due_date || 'N/A';
          document.getElementById('viewCreatedBy').textContent = bill.creator_name || 'Household Admin';
          document.getElementById('viewCreatedAt').textContent = bill.created_at_formatted || bill.created_at || 'N/A';

          const statusBadge = document.getElementById('viewStatusBadge');
          if (statusBadge) {
            statusBadge.className = `rs-bill-status ${bill.status_badge_class || 'rs-bill-status-unpaid'}`;
            statusBadge.innerHTML = `<span class="status-dot"></span> ${bill.status_label || bill.status || 'Unpaid'}`;
          }

          // Personal Share Banner
          const myShareBanner = document.getElementById('viewMyShareBanner');
          const myShareAmountEl = document.getElementById('viewMyShareAmount');
          const myShareStatusEl = document.getElementById('viewMyShareStatus');
          if (myShareBanner && myShareAmountEl && myShareStatusEl) {
            if (bill.my_share_amount !== null && bill.my_share_amount !== undefined) {
              myShareBanner.classList.remove('d-none');
              myShareAmountEl.textContent = `₱${parseFloat(bill.my_share_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
              const isMyPaid = (bill.my_payment_status || '').toLowerCase() === 'paid';
              myShareStatusEl.innerHTML = `
                <span class="rs-bill-status ${isMyPaid ? 'rs-bill-status-paid' : 'rs-bill-status-unpaid'}">
                  <span class="status-dot"></span> ${isMyPaid ? 'Paid' : 'Unpaid'}
                </span>
              `;
            } else {
              myShareBanner.classList.add('d-none');
            }
          }

          // Setup Admin Bill Status Button
          const billStatusIdInput = document.getElementById('viewBillStatusId');
          const billStatusTargetInput = document.getElementById('viewBillStatusTarget');
          const billStatusBtn = document.getElementById('viewBillStatusBtn');
          if (billStatusIdInput && billStatusTargetInput && billStatusBtn) {
            billStatusIdInput.value = bill.bill_id || '';
            const isBillPaid = (bill.status || '').toLowerCase() === 'paid';
            billStatusTargetInput.value = isBillPaid ? 'Unpaid' : 'Paid';
            billStatusBtn.className = `btn btn-sm ${isBillPaid ? 'btn-outline-secondary' : 'btn-success'}`;
            billStatusBtn.innerHTML = isBillPaid
              ? '<i class="bi bi-arrow-counterclockwise me-1"></i> Mark as Unpaid'
              : '<i class="bi bi-check2-circle me-1"></i> Mark as Paid';
          }

          // Populate shares list
          const sharesList = document.getElementById('viewSharesList');
          if (sharesList) {
            sharesList.innerHTML = '';
            if (Array.isArray(bill.shares) && bill.shares.length > 0) {
              const csrfTokenEl = document.querySelector('input[name="csrf_token"]');
              const csrfVal = csrfTokenEl ? csrfTokenEl.value : '';

              bill.shares.forEach((share) => {
                const item = document.createElement('div');
                item.className = 'detail-share-list-item';

                const isPaid = (share.payment_status || '').toLowerCase() === 'paid';
                const badgeClass = isPaid ? 'rs-bill-status-paid' : 'rs-bill-status-unpaid';
                const badgeText = isPaid ? 'Paid' : 'Unpaid';

                let actionButtonHtml = '';
                if (csrfVal !== '') {
                  const targetStatus = isPaid ? 'Unpaid' : 'Paid';
                  const buttonLabel = isPaid ? 'Mark Unpaid' : 'Mark Paid';
                  const buttonIcon = isPaid ? 'bi-x-circle' : 'bi-check-circle';
                  const btnClass = isPaid ? 'btn-outline-secondary' : 'btn-success';

                  actionButtonHtml = `
                    <form action="actions/bill-share-status.php" method="POST" class="d-inline ms-2">
                      <input type="hidden" name="csrf_token" value="${csrfVal}">
                      <input type="hidden" name="share_id" value="${share.bill_share_id}">
                      <input type="hidden" name="status" value="${targetStatus}">
                      <button type="submit" class="btn btn-sm ${btnClass} py-0 px-2" style="font-size: 0.72rem;" title="${buttonLabel}">
                        <i class="bi ${buttonIcon} me-1"></i>${buttonLabel}
                      </button>
                    </form>
                  `;
                }

                item.innerHTML = `
                  <div class="d-flex align-items-center gap-2">
                    <div class="rs-avatar" style="width: 32px; height: 32px; font-size: 0.78rem;">
                      ${(share.full_name || share.username || '?').substring(0, 2).toUpperCase()}
                    </div>
                    <div>
                      <div class="fw-bold text-main small">${share.full_name || share.username}</div>
                      <div class="text-secondary-custom small">@${share.username || 'member'}</div>
                    </div>
                  </div>
                  <div class="d-flex align-items-center gap-2 text-end">
                    <div>
                      <div class="fw-bold text-main">₱${parseFloat(share.share_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                      <span class="rs-bill-status ${badgeClass}" style="font-size: 0.7rem; padding: 0.2rem 0.5rem;">
                        <span class="status-dot"></span> ${badgeText}
                      </span>
                    </div>
                    ${actionButtonHtml}
                  </div>
                `;
                sharesList.appendChild(item);
              });
            } else {
              sharesList.innerHTML = '<div class="p-3 text-center text-secondary-custom small">No individual shares assigned yet.</div>';
            }
          }
        } catch (err) {
          console.error('Failed to parse bill details:', err);
        }
      });
    });
  }

  // Edit Bill Modal Handling
  const editModal = document.getElementById('editBillModal');
  if (editModal) {
    document.querySelectorAll('.edit-bill-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const billDataRaw = button.getAttribute('data-bill');
        if (!billDataRaw) return;

        try {
          const bill = JSON.parse(billDataRaw);
          const form = document.getElementById('editBillForm');
          if (!form) return;

          form.querySelector('input[name="bill_id"]').value = bill.bill_id || '';
          form.querySelector('input[name="bill_name"]').value = bill.bill_name || '';
          form.querySelector('input[name="amount"]').value = parseFloat(bill.amount || 0).toFixed(2);
          form.querySelector('input[name="due_date"]').value = bill.due_date || '';
          form.querySelector('select[name="category_id"]').value = bill.category_id || '';
          form.querySelector('select[name="status"]').value = bill.status || 'Unpaid';

          // Reset all share checkboxes and inputs
          const checkboxes = form.querySelectorAll('.edit-member-checkbox');
          const shareInputs = form.querySelectorAll('.edit-share-input');

          checkboxes.forEach((cb) => {
            cb.checked = false;
            cb.closest('.share-member-row')?.classList.remove('is-selected');
          });

          shareInputs.forEach((input) => {
            input.value = '0.00';
            input.disabled = true;
          });

          // Check and populate existing shares
          if (Array.isArray(bill.shares)) {
            bill.shares.forEach((share) => {
              const cb = form.querySelector(`.edit-member-checkbox[value="${share.user_id}"]`);
              const input = form.querySelector(`input[name="shares[${share.user_id}]"]`);
              if (cb) {
                cb.checked = true;
                cb.closest('.share-member-row')?.classList.add('is-selected');
              }
              if (input) {
                input.disabled = false;
                input.value = parseFloat(share.share_amount || 0).toFixed(2);
              }
            });
          }

          // Trigger change event to update summary calculation
          form.querySelector('input[name="amount"]')?.dispatchEvent(new Event('input'));
        } catch (err) {
          console.error('Failed to populate edit bill form:', err);
        }
      });
    });
  }

  // Delete Bill Modal Handling
  const deleteModal = document.getElementById('deleteBillModal');
  if (deleteModal) {
    document.querySelectorAll('.delete-bill-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const billId = button.getAttribute('data-bill-id') || '';
        const billName = button.getAttribute('data-bill-name') || 'this bill';

        const deleteIdInput = document.getElementById('deleteBillId');
        const deleteNameEl = document.getElementById('deleteBillName');

        if (deleteIdInput) deleteIdInput.value = billId;
        if (deleteNameEl) deleteNameEl.textContent = `“${billName}”`;
      });
    });
  }

  // ==========================================
  // EXPENSES MODALS HANDLING
  // ==========================================

  // View Expense Modal Handling
  const viewExpenseModal = document.getElementById('viewExpenseModal');
  if (viewExpenseModal) {
    document.querySelectorAll('.view-expense-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const expDataRaw = button.getAttribute('data-expense');
        if (!expDataRaw) return;

        try {
          const exp = JSON.parse(expDataRaw);

          document.getElementById('viewExpenseTitle').textContent = exp.title || 'Expense Details';
          document.getElementById('viewExpenseCategory').textContent = exp.category_name || 'Uncategorized';
          document.getElementById('viewExpenseAmount').textContent = `₱${parseFloat(exp.amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
          document.getElementById('viewExpenseDate').textContent = exp.expense_date_formatted || exp.expense_date || 'N/A';
          document.getElementById('viewExpensePaidBy').textContent = exp.payer_name ? `${exp.payer_name} (@${exp.payer_username || 'member'})` : 'Household Member';
          document.getElementById('viewExpenseCreatedAt').textContent = exp.created_at_formatted || exp.created_at || 'N/A';

          const descEl = document.getElementById('viewExpenseDescription');
          if (descEl) {
            descEl.textContent = exp.description ? exp.description : 'No additional description provided.';
          }

          // Setup Admin Expense Status Button
          const expStatusIdInput = document.getElementById('viewExpenseStatusId');
          const expStatusTargetInput = document.getElementById('viewExpenseStatusTarget');
          const expStatusBtn = document.getElementById('viewExpenseStatusBtn');
          if (expStatusIdInput && expStatusTargetInput && expStatusBtn) {
            expStatusIdInput.value = exp.expense_id || '';
            const allPaid = Array.isArray(exp.shares) && exp.shares.length > 0
              ? exp.shares.every(s => parseInt(s.is_paid, 10) === 1)
              : true;
            expStatusTargetInput.value = allPaid ? '0' : '1';
            expStatusBtn.className = `btn btn-sm ${allPaid ? 'btn-outline-secondary' : 'btn-success'}`;
            expStatusBtn.innerHTML = allPaid
              ? '<i class="bi bi-arrow-counterclockwise me-1"></i> Mark as Unpaid'
              : '<i class="bi bi-check2-circle me-1"></i> Mark All as Paid';
          }

          // Populate shares list
          const sharesList = document.getElementById('viewExpenseSharesList');
          if (sharesList) {
            sharesList.innerHTML = '';
            if (Array.isArray(exp.shares) && exp.shares.length > 0) {
              const csrfTokenEl = document.querySelector('input[name="csrf_token"]');
              const csrfVal = csrfTokenEl ? csrfTokenEl.value : '';

              exp.shares.forEach((share) => {
                const item = document.createElement('div');
                item.className = 'detail-share-list-item';

                const isPaid = parseInt(share.is_paid, 10) === 1;
                const badgeClass = isPaid ? 'rs-bill-status-paid' : 'rs-bill-status-unpaid';
                const badgeText = isPaid ? 'Paid' : 'Owes Share';

                let actionButtonHtml = '';
                if (csrfVal !== '') {
                  const targetState = isPaid ? 0 : 1;
                  const buttonLabel = isPaid ? 'Mark Unpaid' : 'Mark Paid';
                  const buttonIcon = isPaid ? 'bi-x-circle' : 'bi-check-circle';
                  const btnClass = isPaid ? 'btn-outline-secondary' : 'btn-success';

                  actionButtonHtml = `
                    <form action="actions/expense-share-status.php" method="POST" class="d-inline ms-2">
                      <input type="hidden" name="csrf_token" value="${csrfVal}">
                      <input type="hidden" name="share_id" value="${share.share_id}">
                      <input type="hidden" name="is_paid" value="${targetState}">
                      <button type="submit" class="btn btn-sm ${btnClass} py-0 px-2" style="font-size: 0.72rem;" title="${buttonLabel}">
                        <i class="bi ${buttonIcon} me-1"></i>${buttonLabel}
                      </button>
                    </form>
                  `;
                }

                item.innerHTML = `
                  <div class="d-flex align-items-center gap-2">
                    <div class="rs-avatar" style="width: 32px; height: 32px; font-size: 0.78rem;">
                      ${(share.full_name || share.username || '?').substring(0, 2).toUpperCase()}
                    </div>
                    <div>
                      <div class="fw-bold text-main small">${share.full_name || share.username}</div>
                      <div class="text-secondary-custom small">@${share.username || 'member'}</div>
                    </div>
                  </div>
                  <div class="d-flex align-items-center gap-2 text-end">
                    <div>
                      <div class="fw-bold text-main">₱${parseFloat(share.share_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                      <span class="rs-bill-status ${badgeClass}" style="font-size: 0.7rem; padding: 0.2rem 0.5rem;">
                        <span class="status-dot"></span> ${badgeText}
                      </span>
                    </div>
                    ${actionButtonHtml}
                  </div>
                `;
                sharesList.appendChild(item);
              });
            } else {
              sharesList.innerHTML = '<div class="p-3 text-center text-secondary-custom small">General household purchase (not split among members).</div>';
            }
          }
        } catch (err) {
          console.error('Failed to parse expense details:', err);
        }
      });
    });
  }

  // Edit Expense Modal Handling
  const editExpenseModal = document.getElementById('editExpenseModal');
  if (editExpenseModal) {
    document.querySelectorAll('.edit-expense-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const expDataRaw = button.getAttribute('data-expense');
        if (!expDataRaw) return;

        try {
          const exp = JSON.parse(expDataRaw);
          const form = document.getElementById('editExpenseForm');
          if (!form) return;

          form.querySelector('input[name="expense_id"]').value = exp.expense_id || '';
          form.querySelector('input[name="title"]').value = exp.title || '';
          form.querySelector('input[name="amount"]').value = parseFloat(exp.amount || 0).toFixed(2);
          form.querySelector('input[name="expense_date"]').value = exp.expense_date || '';
          form.querySelector('select[name="category_id"]').value = exp.category_id || '';
          form.querySelector('select[name="paid_by"]').value = exp.paid_by || '';

          const descTextarea = form.querySelector('textarea[name="description"]');
          if (descTextarea) {
            descTextarea.value = exp.description || '';
          }

          // Reset all share checkboxes and inputs
          const checkboxes = form.querySelectorAll('.edit-expense-member-checkbox');
          const shareInputs = form.querySelectorAll('.edit-expense-share-input');

          checkboxes.forEach((cb) => {
            cb.checked = false;
            cb.closest('.share-member-row')?.classList.remove('is-selected');
          });

          shareInputs.forEach((input) => {
            input.value = '0.00';
            input.disabled = true;
          });

          // Check and populate existing shares if any
          if (Array.isArray(exp.shares)) {
            exp.shares.forEach((share) => {
              const cb = form.querySelector(`.edit-expense-member-checkbox[value="${share.user_id}"]`);
              const input = form.querySelector(`input[name="shares[${share.user_id}]"]`);
              if (cb) {
                cb.checked = true;
                cb.closest('.share-member-row')?.classList.add('is-selected');
              }
              if (input) {
                input.disabled = false;
                input.value = parseFloat(share.share_amount || 0).toFixed(2);
              }
            });
          }

          // Trigger change event to update summary calculation
          form.querySelector('input[name="amount"]')?.dispatchEvent(new Event('input'));
        } catch (err) {
          console.error('Failed to populate edit expense form:', err);
        }
      });
    });
  }

  // Delete Expense Modal Handling
  const deleteExpenseModal = document.getElementById('deleteExpenseModal');
  if (deleteExpenseModal) {
    document.querySelectorAll('.delete-expense-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const expenseId = button.getAttribute('data-expense-id') || '';
        const expenseTitle = button.getAttribute('data-expense-title') || 'this expense';

        const deleteIdInput = document.getElementById('deleteExpenseId');
        const deleteTitleEl = document.getElementById('deleteExpenseTitle');

        if (deleteIdInput) deleteIdInput.value = expenseId;
        if (deleteTitleEl) deleteTitleEl.textContent = `“${expenseTitle}”`;
      });
    });
  }
});
