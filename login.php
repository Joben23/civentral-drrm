<?php
require_once __DIR__ . '/config/recaptcha.php';

$recaptchaReady = false;
$recaptchaSiteKey = '';
try {
    $recaptchaConfig = \App\Config\RecaptchaConfig::fromEnvironment(__DIR__ . '/.env');
    $recaptchaSiteKey = $recaptchaConfig->siteKey();
    $recaptchaReady = true;
} catch (Throwable) {
    http_response_code(503);
    error_log('Admin login reCAPTCHA configuration is unavailable.');
}

$initialLoginMessage = !$recaptchaReady
    ? 'Sign-in security is temporarily unavailable.'
    : ((($_GET['reason'] ?? '') === 'session_expired')
        ? 'Your session expired due to inactivity.'
        : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Civentral</title>
  <link rel="icon" type="image/png" href="assets/images/logo.png">
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <style type="text/tailwindcss">
  @theme {
    --color-brand-light: #EEF5FF;
    --color-brand-border: #B4D4FF;
    --color-brand-medium: #86B6F6;
    --color-brand-dark: #176B87;
  }
</style>
  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <?php if ($recaptchaReady): ?>
    <script src='https://www.google.com/recaptcha/api.js' async defer></script>
  <?php endif; ?>
</head>
<body class="bg-white min-h-screen font-sans antialiased selection:bg-brand-medium selection:text-white">

  <div class="min-h-screen flex flex-col md:flex-row relative">
    
    <div class="hidden md:block md:w-1/2 lg:w-3/5 bg-[url(assets/images/building-bg.jpg)] bg-cover bg-left bg-no-repeat mix-blend-multiply relative">
      <div class="absolute inset-0 bg-gradient-to-r from-transparent to-white"></div>
    </div>

    <div class="flex-1 flex flex-col justify-between p-8 sm:p-12 md:p-16 lg:p-24 bg-white z-10">
      
      <div></div>

      <div class="w-full max-w-md mx-auto space-y-6 my-auto relative">
  
        <div class="w-full space-y-6">
          <div class="flex flex-col items-center justify-center text-center pb-4 w-full">
            <img src="assets/images/logo.png" alt="Civentral Graphic" class="h-24 w-auto object-contain mb-3">
            <span class="text-4xl font-black text-brand-medium tracking-[0.25em] uppercase font-sans">
              Civentral
            </span>
          </div>
          
          <div class="flex flex-col items-center md:items-start text-center md:text-left space-y-2">
            <span class="text-xs font-bold tracking-widest text-gray-400 uppercase">Employee Access</span>
            <h1 class="text-3xl font-extrabold text-gray-600 tracking-tight">Sign in to your office</h1>
            <p class="text-xs text-gray-500">Enter your LGU-issued credentials to continue.</p>
          </div>
        </div>

        <div class="relative min-h-[4px]">
          <div id="statusModal" class="hidden absolute left-0 right-0 -top-2 z-20 border rounded-lg p-4 items-start space-x-3 shadow-md transition-all duration-300">
            <div id="modalIcon" class="mt-0.5"></div>
            <p id="modalMessage" class="text-xs font-medium leading-relaxed"></p>
          </div>
        </div>

        <form id="loginForm" class="space-y-4 pt-2" onsubmit="handleLogin(event)">
    
        <div class="space-y-1.5">
          <label for="employeeId" class="text-xs font-semibold text-gray-500">LGU Employee ID</label>
          <div class="relative flex items-center">
            <span class="absolute left-4 text-gray-400">
              <i class="fa-solid fa-user-tie text-sm"></i>
            </span>
            <input 
              type="text" 
              id="employeeId" 
              placeholder="e.g. SADM-2026-001 or email" 
              required
              class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-lg text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:border-brand-medium focus:ring-1 focus:ring-brand-medium transition"
            />
          </div>
        </div>

        <div class="space-y-1.5">
          <label for="password" class="text-xs font-semibold text-gray-500">Password</label>
          <div class="relative flex items-center">
            <span class="absolute left-4 text-gray-400">
              <i class="fa-solid fa-key text-sm"></i>
            </span>
            <input 
              type="password" 
              id="password" 
              placeholder="••••••••••••••••" 
              required
              class="w-full pl-11 pr-11 py-3 bg-white border border-gray-300 rounded-lg text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:border-brand-medium focus:ring-1 focus:ring-brand-medium transition"
            />
            <button 
              type="button" 
              onclick="togglePasswordVisibility()" 
              class="absolute right-4 text-gray-400 hover:text-gray-600 focus:outline-none"
            >
              <i id="passwordIcon" class="fa-solid fa-eye-slash text-sm"></i>
            </button>
          </div>
        </div>

        <div class="flex items-center justify-end pt-1">
          <a href="#" class="text-xs font-semibold text-brand-medium hover:underline">Forgot password?</a>
        </div>

        <?php if ($recaptchaReady): ?>
          <div class="flex justify-center py-1">
            <div
              class="g-recaptcha"
              data-sitekey="<?php echo htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8'); ?>"
              data-expired-callback="handleRecaptchaExpired"
              data-error-callback="handleRecaptchaError"
            ></div>
          </div>
        <?php endif; ?>

        <button 
          id="loginSubmitButton"
          type="submit" 
          <?php echo $recaptchaReady ? '' : 'disabled aria-disabled="true"'; ?>
          class="w-full py-3 px-4 bg-brand-medium hover:bg-opacity-90 text-white font-medium rounded-lg text-sm transition shadow-sm focus:outline-none"
        >
          Sign In
        </button>
      </form>

      <div class="relative flex py-2 items-center">
        <div class="flex-grow border-t border-gray-200"></div>
        <span class="flex-shrink mx-4 text-xs font-semibold text-gray-400 tracking-wider">OR</span>
        <div class="flex-grow border-t border-gray-200"></div>
      </div>

      <a 
        href="index.php"
        class="inline-block text-center w-full py-3 px-4 bg-white hover:bg-brand-medium hover:text-white text-brand-medium font-medium border border-brand-medium rounded-lg text-sm transition focus:outline-none"
      >
        Back to Home
      </a>

  </div>

      <div class="text-center pt-8">
        <p class="text-[10px] md:text-xs font-bold text-gray-400 tracking-wider uppercase max-w-sm mx-auto leading-relaxed">
          DEPT ACCESS ONLY - UNAUTHORIZED USE IS LOGGED & PROSECUTABLE UNDER RA 8792
        </p>
      </div>

    </div>
  </div>

  <!-- 2FA OTP Verification Modal -->
  <div id="otpModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs transition-all duration-300 opacity-0">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 sm:p-8 transform scale-95 transition-all duration-300 border border-slate-100 relative">
      
      <!-- Close / Back Button -->
      <button type="button" onclick="closeOtpModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition cursor-pointer">
        <i class="fa-solid fa-xmark text-lg"></i>
      </button>

      <div class="text-center space-y-3 mb-6">
        <div class="h-14 w-14 rounded-2xl bg-brand-light border border-brand-border flex items-center justify-center text-brand-dark mx-auto shadow-xs">
          <i class="fa-solid fa-shield-halved text-2xl"></i>
        </div>
        <h3 class="text-xl font-black text-slate-800 tracking-tight">Two-Factor Verification</h3>
        <p class="text-xs text-slate-500 max-w-xs mx-auto leading-relaxed">
          We sent a 6-digit security code to <strong id="otpMaskedEmail" class="text-brand-dark font-mono">your email</strong>.
        </p>
      </div>

      <!-- OTP Form -->
      <form id="otpForm" onsubmit="handleVerifyOTP(event)" class="space-y-6">
        
        <!-- 6 Input boxes -->
        <div class="flex justify-between items-center gap-2 max-w-xs mx-auto">
          <input type="text" maxlength="1" pattern="[0-9]*" inputmode="numeric" class="otp-input w-11 h-12 text-center text-xl font-bold font-mono text-brand-dark bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/20 focus:outline-none transition" required autofocus />
          <input type="text" maxlength="1" pattern="[0-9]*" inputmode="numeric" class="otp-input w-11 h-12 text-center text-xl font-bold font-mono text-brand-dark bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/20 focus:outline-none transition" required />
          <input type="text" maxlength="1" pattern="[0-9]*" inputmode="numeric" class="otp-input w-11 h-12 text-center text-xl font-bold font-mono text-brand-dark bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/20 focus:outline-none transition" required />
          <input type="text" maxlength="1" pattern="[0-9]*" inputmode="numeric" class="otp-input w-11 h-12 text-center text-xl font-bold font-mono text-brand-dark bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/20 focus:outline-none transition" required />
          <input type="text" maxlength="1" pattern="[0-9]*" inputmode="numeric" class="otp-input w-11 h-12 text-center text-xl font-bold font-mono text-brand-dark bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/20 focus:outline-none transition" required />
          <input type="text" maxlength="1" pattern="[0-9]*" inputmode="numeric" class="otp-input w-11 h-12 text-center text-xl font-bold font-mono text-brand-dark bg-slate-50 border border-slate-300 rounded-xl focus:bg-white focus:border-brand-medium focus:ring-2 focus:ring-brand-medium/20 focus:outline-none transition" required />
        </div>

        <div id="otpAlert" class="hidden text-xs text-center p-3 rounded-lg border"></div>

        <button 
          type="submit" 
          id="btnVerifyOtp"
          class="w-full py-3 px-4 bg-brand-medium hover:bg-opacity-90 text-white font-bold rounded-xl text-sm transition shadow-sm focus:outline-none cursor-pointer flex items-center justify-center gap-2"
        >
          <span>Verify & Complete Sign In</span>
        </button>

        <div class="text-center pt-2">
          <p class="text-xs text-slate-500">
            Didn't receive the code? 
            <button type="button" id="btnResendOtp" onclick="handleResendOTP()" class="font-bold text-brand-dark hover:underline cursor-pointer focus:outline-none">
              Resend Code
            </button>
            <span id="resendTimerText" class="text-slate-400 font-semibold hidden ml-1"></span>
          </p>
        </div>

      </form>

    </div>
  </div>

  <script>
    window.civentralLoginInitialMessage = <?php echo json_encode(
      $initialLoginMessage,
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
    window.civentralRecaptchaReady = <?php echo $recaptchaReady ? 'true' : 'false'; ?>;
  </script>
  <script src="assets/js/login.js"></script>
</body>
</html>
