/**
 * RoomieSync - Manage Module Scripts
 */

document.addEventListener('DOMContentLoaded', () => {
  // Category tabs switching (Chores / Expenses / Bills)
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

  // Household Banner Live Preview
  const imageInput = document.getElementById('householdImageFileInput');
  const previewBox = document.getElementById('householdImagePreviewBox');
  if (imageInput && previewBox) {
    imageInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = (event) => {
          previewBox.innerHTML = `
            <img src="${event.target.result}" alt="Preview" class="household-banner-img mb-2">
            <small class="text-success fw-bold d-block"><i class="bi bi-check2-circle"></i> Ready to upload: ${file.name}</small>
          `;
        };
        reader.readAsDataURL(file);
      }
    });
  }
});
