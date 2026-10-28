/**
 * RoomieSync - Manage Module Scripts
 * Handles expandable accordion cards, sub-tabs, modals, and dynamic controls.
 */

document.addEventListener('DOMContentLoaded', () => {
  // ==================================================
  // 1. EXPANDABLE ACCORDION CARDS LOGIC
  // ==================================================
  const accordionCards = document.querySelectorAll('.manage-accordion-card');
  const accordionHeaders = document.querySelectorAll('.manage-accordion-header');

  function openCard(card, smoothScroll = false) {
    if (!card) return;
    
    // Close other accordion cards to keep view clean and prevent excessive page length
    accordionCards.forEach(c => {
      if (c !== card) {
        c.classList.remove('is-expanded');
        const h = c.querySelector('.manage-accordion-header');
        if (h) h.setAttribute('aria-expanded', 'false');
      }
    });

    card.classList.add('is-expanded');
    const header = card.querySelector('.manage-accordion-header');
    if (header) header.setAttribute('aria-expanded', 'true');

    if (smoothScroll) {
      setTimeout(() => {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 100);
    }
  }

  function toggleCard(card) {
    if (!card) return;
    if (card.classList.contains('is-expanded')) {
      card.classList.remove('is-expanded');
      const header = card.querySelector('.manage-accordion-header');
      if (header) header.setAttribute('aria-expanded', 'false');
    } else {
      openCard(card);
    }
  }

  accordionHeaders.forEach(header => {
    header.addEventListener('click', (e) => {
      // Don't toggle if clicking an interactive control inside header (if any)
      if (e.target.closest('button, a, input, select')) return;
      const card = header.closest('.manage-accordion-card');
      toggleCard(card);
    });

    // Keyboard accessibility: Enter or Space
    header.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        const card = header.closest('.manage-accordion-card');
        toggleCard(card);
      }
    });
  });

  // Auto-expand card based on URL hash or action
  function handleUrlHash() {
    const hash = window.location.hash.toLowerCase();
    if (!hash) return;

    if (hash === '#manageusers' || hash === '#cardmanageusers') {
      const card = document.getElementById('cardManageUsers');
      if (card) openCard(card, true);
    } else if (hash === '#categoriessection' || hash === '#cardcategories') {
      const card = document.getElementById('cardCategories');
      if (card) openCard(card, true);
    } else if (hash === '#announcementssection' || hash === '#cardannouncements') {
      const card = document.getElementById('cardAnnouncements');
      if (card) {
        openCard(card, true);
        switchManageSubtab('announcements');
      }
    } else if (hash === '#activitylogssection' || hash === '#activity') {
      const card = document.getElementById('cardAnnouncements');
      if (card) {
        openCard(card, true);
        switchManageSubtab('activity');
      }
    } else if (hash === '#householdsettingssection' || hash === '#cardhouseholdsettings') {
      const card = document.getElementById('cardHouseholdSettings');
      if (card) openCard(card, true);
    }
  }

  handleUrlHash();
  window.addEventListener('hashchange', handleUrlHash);

  // ==================================================
  // 2. SUB-TABS (Announcements vs Activity Logs)
  // ==================================================
  function switchManageSubtab(targetTab) {
    const subtabBtns = document.querySelectorAll('.manage-subtab-btn');
    const subtabPanels = document.querySelectorAll('.manage-subtab-panel');

    subtabBtns.forEach(btn => {
      if (btn.getAttribute('data-subtab') === targetTab) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });

    subtabPanels.forEach(panel => {
      if (panel.getAttribute('data-subtab') === targetTab) {
        panel.classList.remove('d-none');
      } else {
        panel.classList.add('d-none');
      }
    });
  }

  const subtabBtns = document.querySelectorAll('.manage-subtab-btn');
  subtabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-subtab');
      if (target) switchManageSubtab(target);
    });
  });

  // ==================================================
  // 3. CATEGORY TABS (Chores / Expenses / Bills)
  // ==================================================
  const categoryTabBtns = document.querySelectorAll('.category-tab-btn');
  const categoryPanels = document.querySelectorAll('.category-panel');

  categoryTabBtns.forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const targetType = btn.getAttribute('data-category-type');

      categoryTabBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      categoryPanels.forEach(panel => {
        if (panel.getAttribute('data-category-type') === targetType) {
          panel.classList.remove('d-none');
        } else {
          panel.classList.add('d-none');
        }
      });
    });
  });

  // ==================================================
  // 4. MODALS PREFILL HANDLERS
  // ==================================================
  // Edit Announcement Modal prefill
  const editAnnBtns = document.querySelectorAll('.btn-edit-announcement');
  const editAnnId = document.getElementById('editAnnouncementId');
  const editAnnTitle = document.getElementById('editAnnouncementTitle');
  const editAnnContent = document.getElementById('editAnnouncementContent');

  editAnnBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (editAnnId) editAnnId.value = btn.getAttribute('data-id') || '';
      if (editAnnTitle) editAnnTitle.value = btn.getAttribute('data-title') || '';
      if (editAnnContent) editAnnContent.value = btn.getAttribute('data-content') || '';
    });
  });

  // Delete Announcement Modal prefill
  const deleteAnnBtns = document.querySelectorAll('.btn-delete-announcement');
  const deleteAnnId = document.getElementById('deleteAnnouncementId');
  const deleteAnnTitle = document.getElementById('deleteAnnouncementTitleText');

  deleteAnnBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (deleteAnnId) deleteAnnId.value = btn.getAttribute('data-id') || '';
      if (deleteAnnTitle) deleteAnnTitle.textContent = btn.getAttribute('data-title') || 'this announcement';
    });
  });

  // Edit Category Modal prefill
  const editCatBtns = document.querySelectorAll('.btn-edit-category');
  const editCatType = document.getElementById('editCategoryType');
  const editCatId = document.getElementById('editCategoryId');
  const editCatName = document.getElementById('editCategoryName');
  const editCatDesc = document.getElementById('editCategoryDesc');
  const editCatBadge = document.getElementById('editCategoryTypeBadge');

  editCatBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const type = btn.getAttribute('data-type') || 'chore';
      if (editCatType) editCatType.value = type;
      if (editCatId) editCatId.value = btn.getAttribute('data-id') || '';
      if (editCatName) editCatName.value = btn.getAttribute('data-name') || '';
      if (editCatDesc) editCatDesc.value = btn.getAttribute('data-desc') || '';
      if (editCatBadge) {
        editCatBadge.textContent = type.charAt(0).toUpperCase() + type.slice(1);
      }
    });
  });

  // Delete Category Modal prefill
  const deleteCatBtns = document.querySelectorAll('.btn-delete-category');
  const deleteCatType = document.getElementById('deleteCategoryType');
  const deleteCatId = document.getElementById('deleteCategoryId');
  const deleteCatName = document.getElementById('deleteCategoryNameText');

  deleteCatBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (deleteCatType) deleteCatType.value = btn.getAttribute('data-type') || '';
      if (deleteCatId) deleteCatId.value = btn.getAttribute('data-id') || '';
      if (deleteCatName) deleteCatName.textContent = btn.getAttribute('data-name') || 'this category';
    });
  });

  // Remove Member Modal prefill
  const removeMemberBtns = document.querySelectorAll('.btn-remove-member');
  const removeMemberId = document.getElementById('removeMemberId');
  const removeMemberName = document.getElementById('removeMemberNameText');

  removeMemberBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (removeMemberId) removeMemberId.value = btn.getAttribute('data-id') || '';
      if (removeMemberName) removeMemberName.textContent = btn.getAttribute('data-name') || 'this member';
    });
  });

  // Change Role Modal prefill
  const changeRoleBtns = document.querySelectorAll('.btn-change-role');
  const changeRoleUserId = document.getElementById('changeRoleUserId');
  const changeRoleSelect = document.getElementById('changeRoleSelect');
  const changeRoleMemberName = document.getElementById('changeRoleMemberNameText');

  changeRoleBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const currentRole = btn.getAttribute('data-role') || 'Roommate';
      const targetRole = currentRole.toLowerCase() === 'admin' ? 'Roommate' : 'Admin';
      if (changeRoleUserId) changeRoleUserId.value = btn.getAttribute('data-id') || '';
      if (changeRoleMemberName) changeRoleMemberName.textContent = btn.getAttribute('data-name') || 'this member';
      if (changeRoleSelect) changeRoleSelect.value = targetRole;
    });
  });

  // Edit Maintenance Report Modal prefill
  const editMaintBtns = document.querySelectorAll('.btn-edit-maintenance');
  const editMaintId = document.getElementById('editMaintId');
  const editMaintType = document.getElementById('editMaintType');
  const editMaintStatus = document.getElementById('editMaintStatus');
  const editMaintDesc = document.getElementById('editMaintDesc');

  editMaintBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (editMaintId) editMaintId.value = btn.getAttribute('data-id') || '';
      if (editMaintType) editMaintType.value = btn.getAttribute('data-type') || 'Plumbing';
      if (editMaintStatus) editMaintStatus.value = btn.getAttribute('data-status') || 'Open';
      if (editMaintDesc) editMaintDesc.value = btn.getAttribute('data-desc') || '';
    });
  });

  // ==================================================
  // 5. LIVE SEARCH & BANNER PREVIEW & COPY CODE
  // ==================================================
  // Live Member Filter Search
  const memberSearchInput = document.getElementById('memberSearchInput');
  const memberTable = document.getElementById('manageMembersTable');
  if (memberSearchInput && memberTable) {
    memberSearchInput.addEventListener('input', () => {
      const term = memberSearchInput.value.toLowerCase().trim();
      const rows = memberTable.querySelectorAll('tbody tr');
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    });
  }

  // Household Banner Live Preview & Drag-and-Drop
  const imageInput = document.getElementById('householdImageFileInput');
  const previewBox = document.getElementById('householdImagePreviewBox');
  if (imageInput && previewBox) {
    const handleBannerFile = (file) => {
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (event) => {
        previewBox.innerHTML = `
          <img src="${event.target.result}" alt="Preview" class="household-banner-img mb-2">
          <small class="text-success fw-bold d-block"><i class="bi bi-check2-circle"></i> Ready to upload: ${file.name}</small>
        `;
      };
      reader.readAsDataURL(file);
    };

    imageInput.addEventListener('change', (e) => {
      handleBannerFile(e.target.files[0]);
    });

    ['dragenter', 'dragover'].forEach((evt) => {
      previewBox.addEventListener(evt, (e) => {
        e.preventDefault();
        e.stopPropagation();
        previewBox.classList.add('border-primary', 'opacity-75');
      });
    });

    ['dragleave', 'drop'].forEach((evt) => {
      previewBox.addEventListener(evt, (e) => {
        e.preventDefault();
        e.stopPropagation();
        previewBox.classList.remove('border-primary', 'opacity-75');
      });
    });

    previewBox.addEventListener('drop', (e) => {
      const dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length > 0) {
        imageInput.files = dt.files;
        handleBannerFile(dt.files[0]);
      }
    });
  }

  // Copy Join Code Button
  const copyCodeBtns = document.querySelectorAll('.btn-copy-code');
  copyCodeBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const code = btn.getAttribute('data-code') || '';
      if (code && navigator.clipboard) {
        navigator.clipboard.writeText(code).then(() => {
          const origHtml = btn.innerHTML;
          btn.innerHTML = '<i class="bi bi-check2 me-1"></i> Copied!';
          btn.classList.add('btn-success');
          btn.classList.remove('btn-rs-outline');
          if (typeof showToast === 'function') {
            showToast('Join code copied to clipboard!', 'success');
          }
          setTimeout(() => {
            btn.innerHTML = origHtml;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-rs-outline');
          }, 2000);
        });
      }
    });
  });
});
