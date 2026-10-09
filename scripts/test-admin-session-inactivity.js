const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class ClassList {
  constructor(...initial) {
    this.values = new Set(initial);
  }

  toggle(name, force) {
    if (force) {
      this.values.add(name);
    } else {
      this.values.delete(name);
    }
  }

  contains(name) {
    return this.values.has(name);
  }
}

const countdown = { textContent: '', classList: new ClassList() };
const warning = { classList: new ClassList('hidden') };
let now = 1_000_000;
const expiry = now + 180_000;

class TestDate extends Date {
  static now() {
    return now;
  }
}

const context = {
  Date: TestDate,
  document: {
    getElementById(id) {
      return id === 'inactivityCountdown' ? countdown
        : id === 'sessionExpiryWarning' ? warning
          : null;
    },
  },
  window: {
    civentralSessionExpiresAt: expiry,
    fetch: async () => ({
      ok: false,
      status: 401,
      headers: { get: () => null },
      clone: () => ({ json: async () => ({}) }),
    }),
    location: { href: '' },
  },
};

context.window.window = context.window;
vm.createContext(context);
vm.runInContext(
  fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'header', 'inactivity.js'), 'utf8'),
  context,
  { filename: 'assets/js/header/inactivity.js' }
);

context.updateCountdownDisplay();
assert.equal(countdown.textContent, '03:00');
assert.equal(warning.classList.contains('hidden'), true);

now = expiry - 60_001;
context.updateCountdownDisplay();
assert.equal(countdown.textContent, '01:01');
assert.equal(warning.classList.contains('hidden'), true);

now = expiry - 60_000;
context.updateCountdownDisplay();
assert.equal(countdown.textContent, '01:00');
assert.equal(warning.classList.contains('hidden'), false);

now = expiry - 1_000;
context.updateCountdownDisplay();
assert.equal(countdown.textContent, '00:01');
assert.equal(warning.classList.contains('hidden'), false);

console.log('AdminSessionInactivityContract=PASS');
