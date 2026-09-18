'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class FakeElement {
  constructor() {
    this.children = [];
    this.dataset = {};
    this.hidden = false;
    this.value = 'PENDING_REVIEW';
    this.textContent = '';
    this.listeners = new Map();
    this.classList = { add() {}, remove() {}, toggle() {} };
  }

  set innerHTML(_value) {
    throw new Error('Untrusted advisory content must not use innerHTML.');
  }

  appendChild(child) {
    this.children.push(child);
  }

  append(...children) {
    this.children.push(...children);
  }

  replaceChildren(...children) {
    this.children = children;
  }

  addEventListener(event, callback) {
    this.listeners.set(event, callback);
  }
}

const body = new FakeElement();
const status = new FakeElement();
const filter = new FakeElement();
const inAppStatus = new FakeElement();
const emailStatus = new FakeElement();
const smsStatus = new FakeElement();
const deliveryDescription = new FakeElement();
emailStatus.textContent = 'Not Connected';
smsStatus.textContent = 'Not Connected';
const deliverySection = {
  querySelector(selector) {
    if (selector === 'h2 + p') return deliveryDescription;
    if (selector === 'article p') return inAppStatus;
    return null;
  }
};
const deliveryHeading = { closest() { return deliverySection; } };
const elements = new Map([
  ['[data-external-advisory-body]', body],
  ['[data-external-advisory-status]', status],
  ['[data-external-advisory-filter]', filter],
  ['#deliveryChannelsTitle', deliveryHeading]
]);
const documentListeners = new Map();
const document = {
  readyState: 'loading',
  body: new FakeElement(),
  querySelector(selector) { return elements.get(selector) || null; },
  querySelectorAll() { return []; },
  createElement() { return new FakeElement(); },
  addEventListener(event, callback) { documentListeners.set(event, callback); }
};

let advisories = [];
let readinessAvailable = false;
let readinessUnavailable = false;
const window = {
  CiventralEarlyWarningConfig: {
    externalAdvisoriesEndpoint: '/api/drrm/external-advisories.php',
    notificationReadinessEndpoint: '/api/drrm/early-warning-notification-readiness.php',
    security: { csrfToken: 'isolated-test-token', capabilities: { canView: true, canReviewExternalAdvisories: true } }
  },
  async fetch(url) {
    if (url === '/api/drrm/early-warning-notification-readiness.php') {
      if (readinessUnavailable) throw new Error('Isolated catalog unavailable.');
      return {
        ok: true,
        async json() {
          return { success: true, data: { in_app_available: readinessAvailable,
            delivery_type: 'AUTHENTICATED_PULL_FEED' } };
        }
      };
    }
    if (typeof url === 'string' && url.startsWith('/api/drrm/external-advisories.php?status=')) {
      return {
        ok: true,
        async json() { return { success: true, data: { advisories } }; }
      };
    }
    throw new Error('Unrelated dashboard endpoint omitted from isolated UI test.');
  }
};

const script = fs.readFileSync(
  path.join(__dirname, '..', 'assets', 'js', 'drrm', 'disaster-early-warning.js'),
  'utf8'
);
vm.runInNewContext(script, { window, document, console, Date, Intl, setTimeout });

async function settle() {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

(async () => {
  documentListeners.get('DOMContentLoaded')();
  await settle();
  assert.equal(inAppStatus.textContent, 'Not Connected');
  readinessAvailable = true;
  await window.CiventralEarlyWarningDashboard.refreshInAppReadiness();
  assert.equal(inAppStatus.textContent, 'Available - Pull Feed');
  assert.equal(emailStatus.textContent, 'Not Connected');
  assert.equal(smsStatus.textContent, 'Not Connected');
  assert.match(deliveryDescription.textContent, /push delivery are not connected/);
  readinessUnavailable = true;
  await window.CiventralEarlyWarningDashboard.refreshInAppReadiness();
  assert.equal(inAppStatus.textContent, 'Not Connected');
  console.log('InAppReadinessFailClosedAndPullOnly=PASS');
  assert.equal(body.children[0].children[0].textContent, 'No staged external advisories awaiting review.');
  assert.equal(status.textContent, 'No staged external advisories awaiting review.');
  console.log('ZeroAdvisoryEmptyState=PASS');

  advisories = [{
    id: '70000000-0000-4000-8000-000000000001',
    source: { code: 'PAGASA', name: 'DOST-PAGASA' },
    title: '<img src=x onerror=alert(1)>',
    advisory_type: 'WEATHER_ADVISORY',
    hazard_type: 'HEAVY_RAINFALL',
    issued_at: '2026-09-16T00:00:00Z',
    fetched_at: '2026-09-16T01:00:00Z',
    payload_version: 1,
    review_status: 'DRAFT_CREATED'
  }];
  filter.value = 'DRAFT_CREATED';
  filter.listeners.get('change')();
  await settle();
  assert.equal(body.children[0].children[0].textContent, '<img src=x onerror=alert(1)>');
  console.log('UntrustedAdvisoryRenderedAsText=PASS');
  console.log('Module4ExternalAdvisoryUi=PASS');
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
