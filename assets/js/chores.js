document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.chore-assignment-type').forEach((select) => {
    const updateFields = () => {
      const target = document.getElementById(select.dataset.target || '');
      if (!target) return;

      const selectedType = select.type === 'radio'
        ? document.querySelector(`input[name="${select.name}"]:checked`)?.value
        : select.value;
      const rotation = selectedType === 'Rotation';
      const singleSelect = target.querySelector('select[name="assigned_user_id"]');
      const singleField = target.querySelector('[id^="singleAssignmentField"]');
      const rotationField = target.querySelector('.rotation-fields');
      if (rotationField) rotationField.classList.toggle('d-none', !rotation);
      if (singleSelect) singleSelect.disabled = rotation;
      if (singleField) {
        singleField.classList.toggle('d-none', rotation);
      } else if (singleSelect) {
        if (singleSelect.previousElementSibling) singleSelect.previousElementSibling.classList.toggle('d-none', rotation);
      }
      if (rotationField) {
        rotationField.querySelectorAll('input[name="rotation_members[]"]').forEach((checkbox) => {
          checkbox.disabled = !rotation;
        });
      }

      if (select.type === 'radio') {
        document.querySelectorAll(`input[name="${select.name}"]`).forEach((radio) => {
          radio.closest('.assignment-type-card')?.classList.toggle('is-selected', radio.checked);
        });
      }

      if (target.id === 'addAssignmentFields') {
        const form = select.closest('form');
        const summaryAssigned = form?.querySelector('#summaryAssigned');
        const summaryFrequency = form?.querySelector('#summaryFrequency');
        const summaryDueDate = form?.querySelector('#summaryDueDate');
        const assignedSelect = form?.querySelector('#assignedUser');
        const dueDate = form?.querySelector('#choreDueDate');
        const frequency = form?.querySelector('#choreFrequency');

        if (summaryAssigned) {
          if (rotation) {
            const names = [...(form?.querySelectorAll('input[name="rotation_members[]"]:checked') || [])]
              .map((checkbox) => checkbox.nextElementSibling?.textContent.trim())
              .filter(Boolean);
            summaryAssigned.textContent = names.length ? names.join(', ') : 'Select rotation members';
          } else {
            summaryAssigned.textContent = assignedSelect?.selectedOptions[0]?.textContent.trim() || 'Choose a member';
          }
        }
        if (summaryFrequency) summaryFrequency.textContent = frequency?.value.trim() || 'Not set';
        if (summaryDueDate) {
          const value = dueDate?.value || '';
          if (value) {
            const [year, month, day] = value.split('-').map(Number);
            summaryDueDate.textContent = new Date(year, month - 1, day).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
          } else {
            summaryDueDate.textContent = 'Choose a date';
          }
        }
      }
    };

    select.addEventListener('change', updateFields);
    updateFields();
  });

  document.querySelectorAll('#addChoreForm input, #addChoreForm select').forEach((input) => {
    input.addEventListener('input', () => {
      document.querySelector('#addChoreForm .chore-assignment-type:checked')?.dispatchEvent(new Event('change'));
    });
    input.addEventListener('change', () => {
      document.querySelector('#addChoreForm .chore-assignment-type:checked')?.dispatchEvent(new Event('change'));
    });
  });

  document.querySelectorAll('input[id^="editFrequency"]').forEach((input) => {
    const select = document.createElement('select');
    select.className = input.className;
    select.id = input.id;
    select.name = input.name;
    select.required = input.required;
    [
      ['One-time', 'One-time'],
      ['Daily', 'Daily'],
      ['Weekly', 'Weekly'],
    ].forEach(([value, label]) => {
      const option = new Option(label, value, false, input.value === value);
      select.add(option);
    });
    input.replaceWith(select);
  });

  const deleteId = document.getElementById('deleteChoreId');
  const deleteName = document.getElementById('deleteChoreName');
  document.querySelectorAll('.chore-delete-trigger').forEach((button) => {
    button.addEventListener('click', () => {
      if (deleteId) deleteId.value = button.dataset.choreId || '';
      if (deleteName) deleteName.textContent = `“${button.dataset.choreName || 'this chore'}”`;
    });
  });
});
