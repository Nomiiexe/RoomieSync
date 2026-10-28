/**
 * RoomieSync Interactive Schedule Calendar
 * Connected to live database chore assignments and bill due dates.
 * Features month navigation, Today jump, visual task indicators, and
 * date-specific task inspection panel.
 */
class RoomieSyncCalendar {
  constructor(options = {}) {
    this.container = typeof options.container === 'string'
      ? document.querySelector(options.container)
      : (options.container || document.getElementById('rsDashboardCalendar'));

    if (!this.container) return;

    this.apiUrl = options.apiUrl || 'actions/calendar-tasks.php';
    this.initialData = options.initialData || (window.RS_CALENDAR_DATA || null);

    // Initial date settings
    const now = new Date();
    this.todayStr = options.today || this.formatDate(now);
    const [tY, tM, tD] = this.todayStr.split('-').map(Number);
    this.todayYear = tY;
    this.todayMonth = tM; // 1-12
    this.todayDay = tD;

    this.currentYear = this.todayYear;
    this.currentMonth = this.todayMonth; // 1-12
    this.selectedDate = this.todayStr;

    this.cache = {};
    if (this.initialData && this.initialData.tasks_by_date) {
      const monthKey = `${this.currentYear}-${String(this.currentMonth).padStart(2, '0')}`;
      this.cache[monthKey] = this.initialData.tasks_by_date;
    }

    // DOM Elements
    this.monthYearEl = this.container.querySelector('.rs-cal-month-year');
    this.gridEl = this.container.querySelector('.rs-cal-grid');
    this.taskPanelEl = document.getElementById(options.taskPanelId || 'rsCalTaskPanel');
    this.prevBtn = this.container.querySelector('.rs-cal-prev');
    this.nextBtn = this.container.querySelector('.rs-cal-next');
    this.todayBtn = this.container.querySelector('.rs-cal-today');

    this.init();
  }

  formatDate(d) {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  init() {
    this.bindEvents();
    this.loadMonthData(this.currentYear, this.currentMonth).then(() => {
      this.render();
      this.renderTaskPanel(this.selectedDate);
    });
  }

  bindEvents() {
    if (this.prevBtn) {
      this.prevBtn.addEventListener('click', () => {
        this.currentMonth--;
        if (this.currentMonth < 1) {
          this.currentMonth = 12;
          this.currentYear--;
        }
        this.onMonthChange();
      });
    }

    if (this.nextBtn) {
      this.nextBtn.addEventListener('click', () => {
        this.currentMonth++;
        if (this.currentMonth > 12) {
          this.currentMonth = 1;
          this.currentYear++;
        }
        this.onMonthChange();
      });
    }

    if (this.todayBtn) {
      this.todayBtn.addEventListener('click', () => {
        this.currentYear = this.todayYear;
        this.currentMonth = this.todayMonth;
        this.selectedDate = this.todayStr;
        this.onMonthChange();
      });
    }

    if (this.gridEl) {
      this.gridEl.addEventListener('click', (e) => {
        const dayCell = e.target.closest('.rs-cal-cell');
        if (!dayCell) return;

        const date = dayCell.dataset.date;
        if (!date) return;

        // If clicking a date from adjacent month, switch view
        const [y, m] = date.split('-').map(Number);
        if (y !== this.currentYear || m !== this.currentMonth) {
          this.currentYear = y;
          this.currentMonth = m;
          this.selectedDate = date;
          this.onMonthChange();
          return;
        }

        this.selectedDate = date;
        this.highlightSelectedCell();
        this.renderTaskPanel(date);
      });
    }
  }

  async onMonthChange() {
    await this.loadMonthData(this.currentYear, this.currentMonth);
    this.render();
    this.renderTaskPanel(this.selectedDate);
  }

  async loadMonthData(year, month) {
    const monthKey = `${year}-${String(month).padStart(2, '0')}`;
    if (this.cache[monthKey]) {
      return this.cache[monthKey];
    }

    try {
      const res = await fetch(`${this.apiUrl}?month=${monthKey}`);
      if (!res.ok) throw new Error('Network error loading calendar tasks');
      const data = await res.json();
      if (data.status === 'success') {
        this.cache[monthKey] = data.tasks_by_date || {};
        return this.cache[monthKey];
      }
    } catch (err) {
      console.warn('Failed to fetch calendar tasks:', err);
      this.cache[monthKey] = {};
    }
    return {};
  }

  getMonthName(month) {
    const months = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];
    return months[month - 1];
  }

  render() {
    if (this.monthYearEl) {
      this.monthYearEl.textContent = `${this.getMonthName(this.currentMonth)} ${this.currentYear}`;
    }

    if (!this.gridEl) return;

    const monthKey = `${this.currentYear}-${String(this.currentMonth).padStart(2, '0')}`;
    const tasksByDate = this.cache[monthKey] || {};

    const firstDayIndex = new Date(this.currentYear, this.currentMonth - 1, 1).getDay(); // 0 is Sun
    const daysInCurrentMonth = new Date(this.currentYear, this.currentMonth, 0).getDate();
    const daysInPrevMonth = new Date(this.currentYear, this.currentMonth - 1, 0).getDate();

    let html = '';

    // Day of week headers
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    html += '<div class="rs-cal-header-row">';
    dayNames.forEach(name => {
      html += `<div class="rs-cal-header-cell">${name}</div>`;
    });
    html += '</div>';

    html += '<div class="rs-cal-days-row">';

    // Previous month overflow days
    const prevYear = this.currentMonth === 1 ? this.currentYear - 1 : this.currentYear;
    const prevMonth = this.currentMonth === 1 ? 12 : this.currentMonth - 1;
    for (let i = firstDayIndex - 1; i >= 0; i--) {
      const dayNum = daysInPrevMonth - i;
      const dateStr = `${prevYear}-${String(prevMonth).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
      html += this.renderDayCell(dateStr, dayNum, true, tasksByDate);
    }

    // Current month days
    for (let day = 1; day <= daysInCurrentMonth; day++) {
      const dateStr = `${this.currentYear}-${String(this.currentMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      html += this.renderDayCell(dateStr, day, false, tasksByDate);
    }

    // Next month overflow days (fill 42 or full grid)
    const totalRendered = firstDayIndex + daysInCurrentMonth;
    const remaining = (totalRendered % 7 === 0) ? 0 : 7 - (totalRendered % 7);
    const nextYear = this.currentMonth === 12 ? this.currentYear + 1 : this.currentYear;
    const nextMonth = this.currentMonth === 12 ? 1 : this.currentMonth + 1;
    for (let day = 1; day <= remaining; day++) {
      const dateStr = `${nextYear}-${String(nextMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      html += this.renderDayCell(dateStr, day, true, tasksByDate);
    }

    html += '</div>';

    this.gridEl.innerHTML = html;
  }

  renderDayCell(dateStr, dayNum, isOtherMonth, currentMonthTasks) {
    const isToday = dateStr === this.todayStr;
    const isSelected = dateStr === this.selectedDate;

    // Retrieve tasks for this date
    const monthKey = dateStr.slice(0, 7);
    const tasks = (this.cache[monthKey] && this.cache[monthKey][dateStr])
      ? this.cache[monthKey][dateStr]
      : (currentMonthTasks[dateStr] || []);

    const hasTasks = tasks.length > 0;
    const choreTasks = tasks.filter(t => t.type === 'chore');
    const billTasks = tasks.filter(t => t.type === 'bill');

    let classes = ['rs-cal-cell'];
    if (isOtherMonth) classes.push('is-other-month');
    if (isToday) classes.push('is-today');
    if (isSelected) classes.push('is-selected');
    if (hasTasks) classes.push('has-tasks');

    let dotsHtml = '';
    if (hasTasks) {
      dotsHtml = '<div class="rs-cal-dots">';
      if (choreTasks.length > 0) {
        dotsHtml += '<span class="rs-cal-dot dot-chore" title="Scheduled Chore"></span>';
      }
      if (billTasks.length > 0) {
        dotsHtml += '<span class="rs-cal-dot dot-bill" title="Scheduled Bill"></span>';
      }
      if (tasks.length > 1) {
        dotsHtml += `<span class="rs-cal-task-count">${tasks.length}</span>`;
      }
      dotsHtml += '</div>';
    }

    return `
      <button type="button" class="${classes.join(' ')}" data-date="${dateStr}" aria-label="${dateStr}">
        <span class="rs-cal-day-num">${dayNum}</span>
        ${dotsHtml}
      </button>
    `;
  }

  highlightSelectedCell() {
    if (!this.gridEl) return;
    this.gridEl.querySelectorAll('.rs-cal-cell').forEach(cell => {
      cell.classList.toggle('is-selected', cell.dataset.date === this.selectedDate);
    });
  }

  formatHumanDate(dateStr) {
    const [y, m, d] = dateStr.split('-').map(Number);
    const dateObj = new Date(y, m - 1, d);
    return dateObj.toLocaleDateString('en-US', {
      weekday: 'long',
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  renderTaskItem(task) {
    const isChore = task.type === 'chore';
    const isCompleted = task.is_completed;
    const statusBadge = isChore
      ? (isCompleted ? 'rs-badge-completed' : (task.status === 'Overdue' ? 'rs-badge-overdue' : 'rs-badge-pending'))
      : (isCompleted ? 'rs-badge-paid' : 'rs-badge-unpaid');

    return `
      <div class="rs-cal-task-item p-3 rounded-3 border bg-white shadow-sm d-flex justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-start gap-2 flex-grow-1">
          <div class="rs-cal-item-icon ${isChore ? 'icon-chore' : 'icon-bill'} mt-1">
            <i class="bi ${isChore ? 'bi-check2-square' : 'bi-receipt'}"></i>
          </div>
          <div>
            <div class="fw-bold text-dark-accent rs-cal-item-name">${task.title}</div>
            <div class="small text-secondary-custom mt-1 d-flex flex-wrap align-items-center gap-2">
              ${isChore
                ? `<span><i class="bi bi-person me-1"></i>${task.assigned_name}</span>`
                : `<span class="fw-semibold text-main">${task.amount_formatted}</span>`
              }
              <span>&bull;</span>
              <span class="badge bg-warm-secondary text-dark-accent border-0">${task.category}</span>
            </div>
          </div>
        </div>
        <div class="text-end d-flex flex-column align-items-end gap-1">
          <span class="rs-badge ${statusBadge}">
            <span class="rs-badge-dot"></span>${task.status}
          </span>
          <a href="${task.url}" class="btn btn-sm btn-link text-decoration-none p-0 text-dark-accent fw-semibold" style="font-size: 0.8rem;">
            View details <i class="bi bi-arrow-right"></i>
          </a>
        </div>
      </div>
    `;
  }

  ensureModalExists() {
    let modalEl = document.getElementById('rsCalAllTasksModal');
    if (modalEl) return modalEl;

    const modalHtml = `
      <div class="modal fade" id="rsCalAllTasksModal" tabindex="-1" aria-labelledby="rsCalAllTasksModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
          <div class="modal-content rs-modal shadow-lg border">
            <div class="modal-header rs-modal-header d-flex align-items-center justify-content-between">
              <div>
                <div class="d-flex align-items-center gap-2">
                  <div class="rs-stat-icon p-2 bg-warm-secondary rounded-circle text-dark-accent" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                    <i class="bi bi-calendar3"></i>
                  </div>
                  <h5 class="modal-title mb-0" id="rsCalAllTasksModalLabel">Tasks Scheduled</h5>
                  <span id="rsCalModalTodayBadge"></span>
                </div>
                <small class="text-secondary-custom d-block mt-1" id="rsCalModalSubtitle">Scheduled tasks for this day</small>
              </div>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body rs-modal-body p-3 p-md-4">
              <div class="rs-cal-modal-task-list d-flex flex-column gap-2" id="rsCalModalTaskList">
              </div>
            </div>
            <div class="modal-footer rs-modal-footer d-flex justify-content-between align-items-center">
              <span class="small text-secondary-custom" id="rsCalModalFooterCount"></span>
              <button type="button" class="btn btn-rs-outline btn-sm px-3" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    return document.getElementById('rsCalAllTasksModal');
  }

  openAllTasksModal(dateStr, tasks) {
    const modalEl = this.ensureModalExists();
    if (!modalEl) return;

    const humanDate = this.formatHumanDate(dateStr);
    const isToday = dateStr === this.todayStr;
    const choreTasks = tasks.filter(t => t.type === 'chore');
    const billTasks = tasks.filter(t => t.type === 'bill');

    const titleEl = modalEl.querySelector('#rsCalAllTasksModalLabel');
    if (titleEl) titleEl.textContent = humanDate;

    const todayBadgeEl = modalEl.querySelector('#rsCalModalTodayBadge');
    if (todayBadgeEl) {
      todayBadgeEl.innerHTML = isToday
        ? '<span class="badge bg-warm-secondary text-dark-accent border px-2 py-1"><i class="bi bi-star-fill me-1 text-warning"></i> Today</span>'
        : '';
    }

    const subtitleEl = modalEl.querySelector('#rsCalModalSubtitle');
    if (subtitleEl) {
      subtitleEl.textContent = `${tasks.length} total tasks scheduled (${choreTasks.length} ${choreTasks.length === 1 ? 'chore' : 'chores'}, ${billTasks.length} ${billTasks.length === 1 ? 'bill' : 'bills'})`;
    }

    const listEl = modalEl.querySelector('#rsCalModalTaskList');
    if (listEl) {
      listEl.innerHTML = tasks.map(t => this.renderTaskItem(t)).join('');
    }

    const footerCountEl = modalEl.querySelector('#rsCalModalFooterCount');
    if (footerCountEl) {
      footerCountEl.textContent = `Showing all ${tasks.length} scheduled items for ${humanDate}`;
    }

    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
      const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
      bsModal.show();
    }
  }

  renderTaskPanel(dateStr) {
    if (!this.taskPanelEl) return;

    const monthKey = dateStr.slice(0, 7);
    const tasks = (this.cache[monthKey] && this.cache[monthKey][dateStr]) ? this.cache[monthKey][dateStr] : [];

    const isToday = dateStr === this.todayStr;
    const humanDate = this.formatHumanDate(dateStr);
    const hasMoreThanThree = tasks.length > 3;

    let subtitleText = `${tasks.length} ${tasks.length === 1 ? 'task' : 'tasks'} scheduled`;
    if (hasMoreThanThree) {
      subtitleText = `Showing 3 of ${tasks.length} tasks scheduled`;
    }

    let html = `
      <div class="rs-cal-panel-header mb-3 pb-2 border-bottom">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h5 class="rs-cal-panel-title mb-0">${humanDate}</h5>
            <small class="text-secondary-custom">${subtitleText}</small>
          </div>
          <div class="d-flex align-items-center gap-2">
            ${hasMoreThanThree ? `
              <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 text-dark-accent fw-semibold rs-cal-header-open-card" id="rsCalHeaderOpenCard">
                View all (${tasks.length}) <i class="bi bi-box-arrow-up-right ms-1"></i>
              </button>
            ` : ''}
            ${isToday ? '<span class="badge bg-warm-secondary text-dark-accent border px-2 py-1"><i class="bi bi-star-fill me-1 text-warning"></i> Today</span>' : ''}
          </div>
        </div>
      </div>
    `;

    if (tasks.length === 0) {
      html += `
        <div class="rs-cal-empty text-center py-4 px-2">
          <div class="rs-empty-icon mb-2"><i class="bi bi-calendar2-check text-muted fs-2"></i></div>
          <h6 class="fw-bold text-main mb-1">No tasks scheduled for this day.</h6>
          <p class="small text-secondary-custom mb-0">There are no chore assignments or bills due on this date.</p>
        </div>
      `;
    } else {
      const visibleTasks = hasMoreThanThree ? tasks.slice(0, 3) : tasks;

      html += '<div class="rs-cal-task-list d-flex flex-column gap-2">';
      visibleTasks.forEach(task => {
        html += this.renderTaskItem(task);
      });
      html += '</div>';

      if (hasMoreThanThree) {
        html += `
          <div class="rs-cal-view-all-wrapper mt-3 pt-2 border-top">
            <button type="button" class="btn btn-sm btn-rs-outline w-100 py-2 d-flex align-items-center justify-content-center gap-2 fw-semibold rs-cal-open-card-btn" id="rsCalOpenCardBtn">
              <i class="bi bi-calendar2-range text-dark-accent"></i>
              <span>View all (${tasks.length} tasks)</span>
              <i class="bi bi-box-arrow-up-right ms-auto small text-muted"></i>
            </button>
          </div>
        `;
      }
    }

    this.taskPanelEl.innerHTML = html;

    if (hasMoreThanThree) {
      const openBtn = this.taskPanelEl.querySelector('#rsCalOpenCardBtn');
      const headerOpenBtn = this.taskPanelEl.querySelector('#rsCalHeaderOpenCard');

      const handleOpen = (e) => {
        if (e) e.preventDefault();
        this.openAllTasksModal(dateStr, tasks);
      };

      if (openBtn) openBtn.addEventListener('click', handleOpen);
      if (headerOpenBtn) headerOpenBtn.addEventListener('click', handleOpen);
    }
  }
}

// Auto initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  const calEl = document.getElementById('rsDashboardCalendar');
  if (calEl) {
    window.rsCalendarInstance = new RoomieSyncCalendar({
      container: calEl,
      taskPanelId: 'rsCalTaskPanel',
      today: calEl.dataset.today || null,
    });
  }
});
