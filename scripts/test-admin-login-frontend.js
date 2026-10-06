const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class ClassList {
  constructor(...initial) {
    this.values = new Set(initial);
  }

  add(...names) {
    names.forEach((name) => this.values.add(name));
  }

  remove(...names) {
    names.forEach((name) => this.values.delete(name));
  }

  contains(name) {
    return this.values.has(name);
  }
}

function element(initialClasses = []) {
  return {
    value: '',
    textContent: '',
    innerHTML: '',
    className: '',
    disabled: false,
    classList: new ClassList(...initialClasses),
    addEventListener() {},
    focus() {},
  };
}

const transform = element(['scale-95']);
const elements = {
  employeeId: element(),
  password: element(),
  statusModal: element(['hidden']),
  modalIcon: element(),
  modalMessage: element(),
  otpModal: element(['hidden', 'opacity-0']),
  otpMaskedEmail: element(),
  otpAlert: element(['hidden']),
  btnVerifyOtp: element(),
  btnResendOtp: element(),
  resendTimerText: element(['hidden']),
};
elements.otpMaskedEmail.textContent = 'your email';
elements.otpModal.querySelector = () => transform;

const otpInputs = Array.from({ length: 6 }, () => element());
const calls = [];
let nextResponse = null;
let recaptchaResetCount = 0;

const context = {
  console,
  document: {
    getElementById(id) {
      return elements[id] || null;
    },
    querySelectorAll(selector) {
      return selector === '.otp-input' ? otpInputs : [];
    },
    addEventListener() {},
  },
  fetch: async (url, options = {}) => {
    calls.push({ url, options });
    if (!nextResponse) {
      throw new Error('No mocked response configured.');
    }
    const payload = nextResponse;
    nextResponse = null;
    return { json: async () => payload };
  },
  setTimeout(callback) {
    callback();
    return 1;
  },
  clearTimeout() {},
  setInterval() {
    return 1;
  },
  clearInterval() {},
  location: { href: '' },
  civentralRecaptchaReady: true,
  grecaptcha: {
    getResponse: () => 'mock-recaptcha-token',
    reset: () => {
      recaptchaResetCount += 1;
    },
  },
};
context.window = context;

const source = fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'js', 'login.js'),
  'utf8'
);
vm.createContext(context);
vm.runInContext(source, context, { filename: 'assets/js/login.js' });

function submitEvent() {
  const submitButton = element();
  submitButton.innerHTML = 'Sign In';
  return {
    preventDefault() {},
    target: {
      querySelector: () => submitButton,
    },
  };
}

async function run() {
  elements.employeeId.value = 'EMP-TEST';
  elements.password.value = 'safe-test-password';

  nextResponse = { status: 'success', message: 'Login successful.' };
  await context.handleLogin(submitEvent());
  assert.equal(context.location.href, 'pages/dashboard.php');
  assert.equal(calls.at(-1).url, 'api/employee/login.php');
  const loginBody = JSON.parse(calls.at(-1).options.body);
  assert.deepEqual(Object.keys(loginBody).sort(), ['employeeId', 'password', 'recaptcha_token']);
  assert.equal(loginBody.recaptcha_token, 'mock-recaptcha-token');

  context.location.href = '';
  nextResponse = {
    status: 'otp_required',
    message: 'A verification code was sent to your registered email.',
  };
  await context.handleLogin(submitEvent());
  assert.equal(elements.otpModal.classList.contains('hidden'), false);
  assert.equal(elements.otpMaskedEmail.textContent, 'your email');
  assert.equal(recaptchaResetCount > 0, true);

  otpInputs.forEach((input, index) => {
    input.value = String(index + 1);
  });
  nextResponse = { status: 'success', message: 'Verification successful.' };
  await context.handleVerifyOTP({ preventDefault() {} });
  assert.equal(context.location.href, 'pages/dashboard.php');
  assert.equal(calls.at(-1).url, 'api/employee/verify-otp.php');
  assert.deepEqual(JSON.parse(calls.at(-1).options.body), { otp: '123456' });

  elements.btnResendOtp.disabled = false;
  nextResponse = { status: 'success', message: 'A new verification code has been sent.' };
  await context.handleResendOTP();
  assert.equal(calls.at(-1).url, 'api/employee/resend-otp.php');
  assert.equal(elements.otpAlert.textContent, 'A new verification code has been sent.');

  nextResponse = {
    status: 'error',
    message: 'Sign-in failed. Check your credentials and try again.',
  };
  await context.handleLogin(submitEvent());
  assert.equal(elements.modalMessage.textContent, 'Sign-in failed. Check your credentials and try again.');

  nextResponse = {
    status: 'maintenance',
    message: 'Sign-in is temporarily unavailable due to maintenance. Please try again later.',
  };
  await context.handleLogin(submitEvent());
  assert.equal(
    elements.modalMessage.textContent,
    'Sign-in is temporarily unavailable due to maintenance. Please try again later.'
  );

  console.log('AdminLoginFrontendProjectedContract=PASS');
}

run().catch((error) => {
  console.error(error.stack || error.message);
  process.exit(1);
});
