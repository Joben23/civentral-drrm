// Server-authoritative admin idle-session UX.
let countdownInterval;
let sessionExpiryAt = Number(window.civentralSessionExpiresAt) || 0;
let lastTouchAttempt = Date.now();
let sessionCheckInFlight = false;
const SESSION_WARNING_SECONDS = 60;
const SESSION_TOUCH_THROTTLE_MS = 30000;

function adminSessionBasePath() {
  return (typeof window.civentralBasePath !== 'undefined')
    ? window.civentralBasePath
    : '../';
}

function redirectForSession(statusCode) {
  const reason = statusCode === 'SESSION_EXPIRED' ? '?reason=session_expired' : '';
  window.location.href = adminSessionBasePath() + 'login.php' + reason;
}

function setSessionExpiry(expiresAt) {
  const parsed = Number(expiresAt);
  if (Number.isFinite(parsed) && parsed > 0) {
    sessionExpiryAt = parsed > 100000000000 ? parsed : parsed * 1000;
    window.civentralSessionExpiresAt = sessionExpiryAt;
  }
}

function updateSessionFromResponse(response) {
  const expiresAtHeader = response.headers.get('X-Civentral-Session-Expires-At');
  if (expiresAtHeader) {
    setSessionExpiry(expiresAtHeader);
  }

  if (response.status === 401) {
    void response.clone().json().then((payload) => {
      if (payload && (payload.code === 'SESSION_EXPIRED' || payload.code === 'AUTHENTICATION_REQUIRED')) {
        redirectForSession(payload.code);
      }
    }).catch(() => {});
  }
}

const civentralNativeFetch = window.fetch.bind(window);
window.fetch = async function civentralSessionFetch(...args) {
  const response = await civentralNativeFetch(...args);
  updateSessionFromResponse(response);
  return response;
};

async function confirmServerSession() {
  if (sessionCheckInFlight) return;
  sessionCheckInFlight = true;
  try {
    const response = await window.fetch(
      adminSessionBasePath() + 'api/employee/session-status.php',
      { method: 'GET', cache: 'no-store', credentials: 'same-origin' }
    );
    if (!response.ok) return;
    const payload = await response.json();
    if (payload && payload.expires_at) {
      setSessionExpiry(payload.expires_at);
    }
  } catch (error) {
    // Fail safely without claiming server-confirmed expiry.
    window.location.href = adminSessionBasePath() + 'pages/logout.php';
  } finally {
    sessionCheckInFlight = false;
  }
}

async function touchServerSession() {
  try {
    await window.fetch(
      adminSessionBasePath() + 'api/employee/session-touch.php',
      {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }
    );
  } catch (error) {
    // A later request or the authoritative expiry check will resolve state.
  }
}

function handleAdminActivity() {
  const now = Date.now();
  if (!sessionExpiryAt || now >= sessionExpiryAt) {
    void confirmServerSession();
    return;
  }
  if ((now - lastTouchAttempt) < SESSION_TOUCH_THROTTLE_MS) return;
  lastTouchAttempt = now;
  void touchServerSession();
}

function updateCountdownDisplay() {
  const now = Date.now();
  const remainingMs = Math.max(0, sessionExpiryAt - now);
  const secondsLeft = Math.ceil(remainingMs / 1000);
  const minutes = Math.floor(secondsLeft / 60);
  const seconds = secondsLeft % 60;
  const countdown = document.getElementById('inactivityCountdown');
  const warning = document.getElementById('sessionExpiryWarning');

  if (countdown) {
    countdown.textContent = sessionExpiryAt
      ? `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
      : '--:--';
    countdown.classList.toggle('text-rose-500', sessionExpiryAt > 0 && secondsLeft <= SESSION_WARNING_SECONDS);
  }

  if (warning) {
    warning.classList.toggle(
      'hidden',
      !sessionExpiryAt || secondsLeft <= 0 || secondsLeft > SESSION_WARNING_SECONDS
    );
  }

  if (sessionExpiryAt && remainingMs <= 0) {
    void confirmServerSession();
  }
}
