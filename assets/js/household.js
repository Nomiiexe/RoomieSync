document.addEventListener('DOMContentLoaded', () => {
  // Mobile Navigation Toggle
  const mobileToggle = document.getElementById('mobileNavToggle');
  const navContainer = document.getElementById('navLinksContainer');
  if (mobileToggle && navContainer) {
    mobileToggle.addEventListener('click', () => {
      navContainer.classList.toggle('show');
    });
  }

  // Copy Household Join Code functionality
  const copyBtn = document.getElementById('copyJoinCodeBtn');
  const joinCodeEl = document.getElementById('householdJoinCodeText');

  if (copyBtn && joinCodeEl) {
    copyBtn.addEventListener('click', async () => {
      const code = joinCodeEl.textContent.trim();
      if (!code) return;

      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(code);
        } else {
          // Fallback using textarea
          const textArea = document.createElement('textarea');
          textArea.value = code;
          textArea.style.position = 'fixed';
          textArea.style.left = '-999999px';
          textArea.style.top = '-999999px';
          document.body.appendChild(textArea);
          textArea.focus();
          textArea.select();
          document.execCommand('copy');
          textArea.remove();
        }

        // Show feedback
        const originalHtml = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="bi bi-check2 text-success me-1"></i> Copied!';
        copyBtn.classList.remove('btn-rs-outline');
        copyBtn.classList.add('btn-outline-success');

        setTimeout(() => {
          copyBtn.innerHTML = originalHtml;
          copyBtn.classList.remove('btn-outline-success');
          copyBtn.classList.add('btn-rs-outline');
        }, 2200);
      } catch (err) {
        console.error('Failed to copy code: ', err);
      }
    });
  }

  // Auto-uppercase join code input in Join Modal
  const joinCodeInput = document.getElementById('joinHouseholdInput');
  if (joinCodeInput) {
    joinCodeInput.addEventListener('input', () => {
      joinCodeInput.value = joinCodeInput.value.toUpperCase();
    });
  }
});
