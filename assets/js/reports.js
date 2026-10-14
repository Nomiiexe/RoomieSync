/**
 * RoomieSync - Reports Module Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
  const filterForm = document.getElementById('reportFilterForm');
  const fromDateInput = document.getElementById('from_date');
  const toDateInput = document.getElementById('to_date');

  // Validate date range on form submit
  if (filterForm && fromDateInput && toDateInput) {
    filterForm.addEventListener('submit', (e) => {
      const fromVal = fromDateInput.value;
      const toVal = toDateInput.value;

      if (fromVal && toVal && fromVal > toVal) {
        e.preventDefault();
        // Automatically swap or alert
        fromDateInput.value = toVal;
        toDateInput.value = fromVal;
        filterForm.submit();
      }
    });
  }

  // Quick preset button handlers
  const presetButtons = document.querySelectorAll('.report-preset-btn');
  presetButtons.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const preset = btn.getAttribute('data-preset');
      const now = new Date();
      let fromDate = '';
      let toDate = '';

      const pad = (n) => String(n).padStart(2, '0');
      const formatYMD = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

      if (preset === 'this-month') {
        const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        fromDate = formatYMD(firstDay);
        toDate = formatYMD(lastDay);
      } else if (preset === 'last-month') {
        const firstDay = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const lastDay = new Date(now.getFullYear(), now.getMonth(), 0);
        fromDate = formatYMD(firstDay);
        toDate = formatYMD(lastDay);
      } else if (preset === 'last-30') {
        const past = new Date();
        past.setDate(now.getDate() - 30);
        fromDate = formatYMD(past);
        toDate = formatYMD(now);
      } else if (preset === 'this-year') {
        const firstDay = new Date(now.getFullYear(), 0, 1);
        const lastDay = new Date(now.getFullYear(), 11, 31);
        fromDate = formatYMD(firstDay);
        toDate = formatYMD(lastDay);
      }

      if (fromDateInput && toDateInput && fromDate && toDate) {
        fromDateInput.value = fromDate;
        toDateInput.value = toDate;
        if (filterForm) {
          filterForm.submit();
        }
      }
    });
  });

  // Report Export feedback handler (Excel & CSV)
  const exportLinks = document.querySelectorAll('a[href*="export=excel"], a[href*="export=xls"], a[href*="export=csv"]');
  exportLinks.forEach(link => {
    link.addEventListener('click', () => {
      if (typeof showToast === 'function') {
        const href = link.getAttribute('href') || '';
        const isExcel = href.includes('export=excel') || href.includes('export=xls');
        showToast(
          isExcel
            ? 'Preparing MS Excel report (.xls)... your download will begin shortly.'
            : 'Preparing CSV report... your download will begin shortly.',
          'success'
        );
      }
    });
  });
});
