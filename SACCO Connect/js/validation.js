/* ============================================================
   validation.js — client-side form validation
   (Server-side validation in the PHP files is still required —
   never trust client-side checks alone.)
   ============================================================ */

function showError(input, message) {
  var errorEl = document.getElementById(input.id + '-error');
  if (errorEl) {
    errorEl.textContent = message;
    errorEl.classList.add('show');
  }
  input.style.borderColor = 'var(--danger)';
}

function clearError(input) {
  var errorEl = document.getElementById(input.id + '-error');
  if (errorEl) {
    errorEl.textContent = '';
    errorEl.classList.remove('show');
  }
  input.style.borderColor = '';
}

function validateRequired(input, label) {
  if (!input.value.trim()) {
    showError(input, label + ' is required.');
    return false;
  }
  clearError(input);
  return true;
}

function validatePhone(input) {
  var pattern = /^[0-9+ ]{9,15}$/;
  if (!pattern.test(input.value.trim())) {
    showError(input, 'Enter a valid phone number.');
    return false;
  }
  clearError(input);
  return true;
}

function validateEmail(input) {
  if (!input.value.trim()) { clearError(input); return true; } // email optional in some forms
  var pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!pattern.test(input.value.trim())) {
    showError(input, 'Enter a valid email address.');
    return false;
  }
  clearError(input);
  return true;
}

function validatePasswordMatch(pass, confirm) {
  if (pass.value !== confirm.value) {
    showError(confirm, 'Passwords do not match.');
    return false;
  }
  clearError(confirm);
  return true;
}

function validateAmount(input) {
  var value = parseFloat(input.value);
  if (isNaN(value) || value <= 0) {
    showError(input, 'Enter an amount greater than 0.');
    return false;
  }
  clearError(input);
  return true;
}

/* ---------- Login form ---------- */
document.addEventListener('DOMContentLoaded', function () {
  var loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
      var username = document.getElementById('username');
      var password = document.getElementById('password');
      var valid = validateRequired(username, 'Username') & validateRequired(password, 'Password');
      if (!valid) e.preventDefault();
    });
  }

  /* ---------- Register member form ---------- */
  var registerForm = document.getElementById('register-form');
  if (registerForm) {
    registerForm.addEventListener('submit', function (e) {
      var fullName = document.getElementById('full_name');
      var phone = document.getElementById('phone');
      var email = document.getElementById('email');
      var password = document.getElementById('password');
      var confirm = document.getElementById('confirm_password');

      var valid = validateRequired(fullName, 'Full name');
      valid = validatePhone(phone) && valid;
      valid = validateEmail(email) && valid;
      valid = validateRequired(password, 'Password') && valid;
      valid = validatePasswordMatch(password, confirm) && valid;

      if (!valid) e.preventDefault();
    });
  }

  /* ---------- Deposit / withdrawal forms ---------- */
  var txnForm = document.getElementById('transaction-form');
  if (txnForm) {
    txnForm.addEventListener('submit', function (e) {
      var amount = document.getElementById('amount');
      if (!validateAmount(amount)) e.preventDefault();
    });
  }

  /* ---------- Loan application form ---------- */
  var loanForm = document.getElementById('loan-form');
  if (loanForm) {
    loanForm.addEventListener('submit', function (e) {
      var amount = document.getElementById('principal');
      var purpose = document.getElementById('purpose');
      var valid = validateAmount(amount);
      valid = validateRequired(purpose, 'Purpose') && valid;
      if (!valid) e.preventDefault();
    });
  }
});
