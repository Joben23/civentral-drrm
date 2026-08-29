function togglePasswordVisibility() {
      const passwordInput = document.getElementById('password');
      const passwordIcon = document.getElementById('passwordIcon');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
      } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
      }
    }

      // FOR THE FUCKING MODALS 
    function showStatusAlert(type, customMessage = "") {
      const modal = document.getElementById('statusModal');
      const icon = document.getElementById('modalIcon');
      const msgText = document.getElementById('modalMessage');

      modal.classList.remove('hidden');
      modal.className = "mt-4 border rounded-lg p-4 flex items-start space-x-3 transition-all duration-300";

      if (type === 'success') {
        modal.classList.add('border-green-200', 'bg-green-50', 'text-green-700');
        icon.innerHTML = '<img src="assets/images/spinner.svg" class="h-6 w-6 shrink-0" alt="Loading...">';
        msgText.textContent = customMessage || "Login successful. Redirecting...";
      } else if (type === 'error') {
        modal.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
        icon.innerHTML = '<i class="fa-solid fa-circle-exclamation text-xl"></i>';
        msgText.textContent = customMessage || "Login failed. Please check your credentials.";
      } else if (type === 'maintenance') {
        modal.classList.add('border-brand-border', 'bg-brand-light', 'text-brand-dark');
        icon.innerHTML = '<i class="fa-solid fa-circle-info text-xl"></i>';
        msgText.textContent = customMessage || "System maintenance is scheduled for Sunday, 11:00 PM–1:00 AM. Save drafts before then.";
      }
    }

    async function handleLogin(event) {
      event.preventDefault();
      const id = document.getElementById('employeeId').value.trim();
      const pass = document.getElementById('password').value.trim();
      const submitBtn = event.target.querySelector('button[type="submit"]');
      const originalBtnHtml = submitBtn ? submitBtn.innerHTML : 'Sign in';

      if (!id || !pass) {
        showStatusAlert('error', 'Please fill in both Employee ID / Email and Password.');
        return;
      }
      
      if (id.toLowerCase() === 'maintenance') {
        showStatusAlert('maintenance');
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="inline-flex items-center justify-center gap-2"><img src="assets/images/spinner.svg" class="h-4 w-4 inline" alt="loading"> Authenticating...</span>';
      }

      try {
        const response = await fetch('api/employee/login.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ employeeId: id, password: pass })
        });

        const data = await response.json();

        if (data.status === 'otp_required') {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
          }
          openOtpModal(data.email || '');
        } else if (data.status === 'success') {
          showStatusAlert('success', data.message || 'Login successful! Redirecting to dashboard...');
          if (submitBtn) {
            submitBtn.innerHTML = '<span class="inline-flex items-center justify-center gap-2"><img src="assets/images/spinner.svg" class="h-4 w-4 inline" alt="loading"> Entering Dashboard...</span>';
          }
          setTimeout(() => {
            window.location.href = 'pages/dashboard.php';
          }, 1200);
        } else if (data.status === 'maintenance') {
          showStatusAlert('maintenance', data.message);
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
          }
        } else {
          showStatusAlert('error', data.message || 'Login failed. Please check your credentials.');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
          }
        }
      } catch (err) {
        showStatusAlert('error', 'Login failed. Invalid credentials or network error.');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalBtnHtml;
        }
      }
    }

    // OTP MODAL CONTROLLERS & EVENT HANDLERS
    let resendCooldownTimer = null;

    function openOtpModal(email = '') {
      const modal = document.getElementById('otpModal');
      const emailEl = document.getElementById('otpMaskedEmail');
      const otpInputs = document.querySelectorAll('.otp-input');

      if (emailEl && email) {
        emailEl.textContent = email;
      }

      // Reset inputs & alerts
      otpInputs.forEach(input => input.value = '');
      showOtpAlert('');

      modal.classList.remove('hidden');
      setTimeout(() => {
        modal.classList.remove('opacity-0');
        modal.querySelector('.transform').classList.remove('scale-95');
        modal.querySelector('.transform').classList.add('scale-100');
        if (otpInputs[0]) otpInputs[0].focus();
      }, 10);
    }

    function closeOtpModal() {
      const modal = document.getElementById('otpModal');
      modal.classList.add('opacity-0');
      modal.querySelector('.transform').classList.remove('scale-100');
      modal.querySelector('.transform').classList.add('scale-95');
      setTimeout(() => {
        modal.classList.add('hidden');
      }, 200);
    }

    function showOtpAlert(message, isError = true) {
      const alertEl = document.getElementById('otpAlert');
      if (!alertEl) return;
      if (!message) {
        alertEl.classList.add('hidden');
        alertEl.textContent = '';
        return;
      }
      alertEl.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'border-red-200', 'bg-green-50', 'text-green-700', 'border-green-200');
      if (isError) {
        alertEl.classList.add('bg-red-50', 'text-red-700', 'border-red-200');
      } else {
        alertEl.classList.add('bg-green-50', 'text-green-700', 'border-green-200');
      }
      alertEl.textContent = message;
    }

    // Auto-advance & paste handler for 6 OTP boxes
    document.addEventListener('DOMContentLoaded', () => {
      const otpInputs = document.querySelectorAll('.otp-input');
      otpInputs.forEach((input, index) => {
        input.addEventListener('input', (e) => {
          const val = e.target.value;
          if (val && index < otpInputs.length - 1) {
            otpInputs[index + 1].focus();
          }
        });

        input.addEventListener('keydown', (e) => {
          if (e.key === 'Backspace' && !e.target.value && index > 0) {
            otpInputs[index - 1].focus();
          }
        });

        input.addEventListener('paste', (e) => {
          e.preventDefault();
          const pastedData = (e.clipboardData || window.clipboardData).getData('text').trim();
          if (/^\d{6}$/.test(pastedData)) {
            pastedData.split('').forEach((char, i) => {
              if (otpInputs[i]) otpInputs[i].value = char;
            });
            otpInputs[5].focus();
          }
        });
      });
    });

    async function handleVerifyOTP(event) {
      event.preventDefault();
      const otpInputs = document.querySelectorAll('.otp-input');
      const otpCode = Array.from(otpInputs).map(i => i.value.trim()).join('');

      if (otpCode.length !== 6 || !/^\d{6}$/.test(otpCode)) {
        showOtpAlert('Please enter a complete 6-digit OTP code.');
        return;
      }

      const verifyBtn = document.getElementById('btnVerifyOtp');
      const originalBtnText = verifyBtn ? verifyBtn.innerHTML : 'Verify';

      if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<span class="inline-flex items-center gap-2"><img src="assets/images/spinner.svg" class="h-4 w-4 inline" alt="loading"> Verifying...</span>';
      }

      try {
        const response = await fetch('api/employee/verify-otp.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ otp: otpCode })
        });
        const data = await response.json();

        if (data.status === 'success') {
          showOtpAlert('OTP verified! Entering Dashboard...', false);
          if (verifyBtn) {
            verifyBtn.innerHTML = '<span class="inline-flex items-center gap-2"><img src="assets/images/spinner.svg" class="h-4 w-4 inline" alt="loading"> Entering Dashboard...</span>';
          }
          setTimeout(() => {
            window.location.href = 'pages/dashboard.php';
          }, 1000);
        } else {
          showOtpAlert(data.message || 'Verification failed.');
          if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = originalBtnText;
          }
        }
      } catch (err) {
        showOtpAlert('Network error verifying OTP code.');
        if (verifyBtn) {
          verifyBtn.disabled = false;
          verifyBtn.innerHTML = originalBtnText;
        }
      }
    }

    async function handleResendOTP() {
      const resendBtn = document.getElementById('btnResendOtp');
      const timerText = document.getElementById('resendTimerText');
      if (resendBtn.disabled) return;

      resendBtn.disabled = true;
      showOtpAlert('Resending verification code...', false);

      try {
        const response = await fetch('api/employee/resend-otp.php', { method: 'POST' });
        const data = await response.json();

        if (data.status === 'success') {
          showOtpAlert(data.message || 'A new OTP code has been sent to your email.', false);
          startResendCooldown(60);
        } else {
          showOtpAlert(data.message || 'Failed to resend code.');
          resendBtn.disabled = false;
        }
      } catch (err) {
        showOtpAlert('Network error resending OTP code.');
        resendBtn.disabled = false;
      }
    }

    function startResendCooldown(seconds) {
      const resendBtn = document.getElementById('btnResendOtp');
      const timerText = document.getElementById('resendTimerText');
      let countdown = seconds;

      resendBtn.disabled = true;
      resendBtn.classList.add('opacity-50', 'cursor-not-allowed');
      timerText.classList.remove('hidden');
      timerText.textContent = `(${countdown}s)`;

      if (resendCooldownTimer) clearInterval(resendCooldownTimer);

      resendCooldownTimer = setInterval(() => {
        countdown--;
        if (countdown <= 0) {
          clearInterval(resendCooldownTimer);
          resendBtn.disabled = false;
          resendBtn.classList.remove('opacity-50', 'cursor-not-allowed');
          timerText.classList.add('hidden');
          timerText.textContent = '';
        } else {
          timerText.textContent = `(${countdown}s)`;
        }
      }, 1000);
    }
