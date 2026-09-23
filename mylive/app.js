(function () {
  'use strict';

  var deferredInstallPrompt = null;
  var installButtons = [];

  function all(selector) {
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  }

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }

  function isIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
  }

  function refreshInstallUi() {
    installButtons.forEach(function (button) {
      if (isStandalone()) {
        button.hidden = false;
        button.disabled = true;
        button.textContent = 'APP INSTALLED';
        button.dataset.state = 'installed';
        return;
      }

      if (deferredInstallPrompt) {
        button.hidden = false;
        button.disabled = false;
        button.textContent = 'INSTALL MYLIVE APP';
        button.dataset.state = 'ready';
        return;
      }

      if (isIos()) {
        button.hidden = false;
        button.disabled = false;
        button.textContent = 'INSTALL ON IPHONE';
        button.dataset.state = 'ios';
        return;
      }

      button.hidden = true;
    });
  }

  function showIosInstallHelp() {
    var message = 'Στο iPhone άνοιξε το Share menu του Safari και επίλεξε “Add to Home Screen”. Μετά άνοιγε το MyLive από το εικονίδιο της αρχικής οθόνης.';

    if (window.DeseoDialog) {
      window.DeseoDialog.alert(message, {
        title: 'Install MyLive App',
        confirmLabel: 'OK'
      });
    }
  }

  function initInstallButtons() {
    installButtons = all('[data-mylive-install]');

    window.addEventListener('beforeinstallprompt', function (event) {
      event.preventDefault();
      deferredInstallPrompt = event;
      refreshInstallUi();
    });

    window.addEventListener('appinstalled', function () {
      deferredInstallPrompt = null;
      refreshInstallUi();
    });

    installButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        if (button.dataset.state === 'ios') {
          showIosInstallHelp();
          return;
        }

        if (!deferredInstallPrompt) return;

        deferredInstallPrompt.prompt();
        deferredInstallPrompt.userChoice.finally(function () {
          deferredInstallPrompt = null;
          refreshInstallUi();
        });
      });
    });

    refreshInstallUi();
  }

  var webpushrSiteKey = 'BBFQF4QEleCIF57CoJnUQRFeh6iQaAxCWSQoVibx6rYFQpavi8Z_2flBoHsn6r6SXV4udR__JwYH7ahY4-m4Lek';
  var webpushrReadyPromise = null;

  function getCsrfToken() {
    var input = document.querySelector('input[name="csrf_token"]');
    return input ? input.value : '';
  }

  function ensureWebpushrSdk() {
    if (window.__myliveWebpushrReady) return Promise.resolve();
    if (webpushrReadyPromise) return webpushrReadyPromise;

    webpushrReadyPromise = new Promise(function (resolve, reject) {
      var settled = false;
      var previousReady = window._webpushrScriptReady;

      window._webpushrScriptReady = function () {
        window.__myliveWebpushrReady = true;

        if (typeof previousReady === 'function') {
          try { previousReady(); } catch (e) {}
        }

        if (!settled) {
          settled = true;
          resolve();
        }
      };

      if (typeof window.webpushr === 'undefined') {
        window.webpushr = function () {
          (window.webpushr.q = window.webpushr.q || []).push(arguments);
        };
      }

      var existing = document.getElementById('webpushr-jssdk');
      if (!existing) {
        var script = document.createElement('script');
        script.id = 'webpushr-jssdk';
        script.async = true;
        script.src = 'https://cdn.webpushr.com/app.min.js';
        script.onerror = function () {
          if (!settled) {
            settled = true;
            webpushrReadyPromise = null;
            reject(new Error('Webpushr SDK could not be loaded.'));
          }
        };
        document.head.appendChild(script);
      }

      window.webpushr('setup', { key: webpushrSiteKey });

      window.setTimeout(function () {
        if (!settled && !window.__myliveWebpushrReady) {
          settled = true;
          webpushrReadyPromise = null;
          reject(new Error('Webpushr SDK did not become ready.'));
        }
      }, 12000);
    });

    return webpushrReadyPromise;
  }

  function fetchWebpushrSubscriberId(attempt) {
    attempt = attempt || 0;

    return new Promise(function (resolve, reject) {
      if (typeof window.webpushr !== 'function') {
        reject(new Error('Webpushr is not ready.'));
        return;
      }

      window.webpushr('fetch_id', function (sid) {
        if (typeof sid === 'string' && sid.trim() !== '') {
          resolve(sid.trim());
          return;
        }

        if (attempt >= 8) {
          reject(new Error('Push subscription is not ready yet.'));
          return;
        }

        window.setTimeout(function () {
          fetchWebpushrSubscriberId(attempt + 1).then(resolve).catch(reject);
        }, 700);
      });
    });
  }

  function createPushPanel() {
    var mount = document.querySelector('[data-mylive-push-mount]');
    var installCard = document.querySelector('.mylive-app-card');
    if ((!mount && !installCard) || document.querySelector('[data-mylive-push-panel]')) return null;

    var panel = document.createElement('div');
    panel.className = 'mylive-push-card';
    panel.setAttribute('data-mylive-push-panel', '');
    panel.innerHTML =
      '<div class="mylive-push-card-copy">' +
        '<span>MYLIVE · NOTIFICATIONS</span>' +
        '<strong>DJ Alerts στο κινητό και στον browser σου.</strong>' +
        '<p>Λάβε ειδοποίηση 3 ημέρες πριν αν λείπει το επόμενο DJ Set σου και μόλις το show σου βγει live στον Deseo Radio.</p>' +
      '</div>' +
      '<div class="mylive-push-card-actions">' +
        '<span class="mylive-push-status" data-mylive-push-status>CHECKING…</span>' +
        '<button type="button" class="mylive-push-button" data-mylive-push-enable disabled>ENABLE PUSH ALERTS</button>' +
        '<button type="button" class="mylive-push-disable" data-mylive-push-disable hidden>TURN OFF ALERTS</button>' +
      '</div>' +
      '<small class="mylive-push-help" data-mylive-push-help>Δεν εμφανίζεται κανένα Webpushr popup. Η άδεια ζητείται μόνο όταν πατήσεις Enable.</small>';

    if (mount) {
      mount.appendChild(panel);
    } else {
      installCard.insertAdjacentElement('afterend', panel);
    }
    return panel;
  }

  function initPushNotifications() {
    var panel = createPushPanel();
    if (!panel) return;

    var status = panel.querySelector('[data-mylive-push-status]');
    var enableButton = panel.querySelector('[data-mylive-push-enable]');
    var disableButton = panel.querySelector('[data-mylive-push-disable]');
    var help = panel.querySelector('[data-mylive-push-help]');
    var state = {
      configured: false,
      enabled: false,
      subscriptions: 0,
      accountId: 0
    };

    function permissionState() {
      if (!('Notification' in window)) return 'unsupported';
      return Notification.permission || 'default';
    }

    function render() {
      var permission = permissionState();

      if (!state.configured) {
        status.textContent = 'API NOT CONFIGURED';
        status.dataset.state = 'error';
        enableButton.disabled = true;
        enableButton.textContent = 'PUSH UNAVAILABLE';
        disableButton.hidden = true;
        help.textContent = 'Το Webpushr API δεν είναι διαθέσιμο στον server.';
        return;
      }

      if (permission === 'unsupported') {
        status.textContent = 'NOT SUPPORTED';
        status.dataset.state = 'error';
        enableButton.disabled = true;
        enableButton.textContent = 'NOT SUPPORTED';
        disableButton.hidden = !state.enabled;
        help.textContent = 'Ο συγκεκριμένος browser δεν υποστηρίζει web push notifications.';
        return;
      }

      if (permission === 'denied') {
        status.textContent = state.enabled ? 'ACCOUNT ON · DEVICE BLOCKED' : 'BLOCKED';
        status.dataset.state = 'error';
        enableButton.disabled = true;
        enableButton.textContent = 'BLOCKED IN BROWSER';
        disableButton.hidden = !state.enabled;
        help.textContent = 'Οι ειδοποιήσεις έχουν αποκλειστεί από τον browser. Άλλαξε την άδεια του deseoradio.com σε Allow.';
        return;
      }

      if (permission === 'granted') {
        status.textContent = state.enabled
          ? 'ACTIVE · ' + Math.max(1, state.subscriptions) + ' DEVICE' + (state.subscriptions === 1 ? '' : 'S')
          : 'BROWSER READY';
        status.dataset.state = state.enabled ? 'active' : 'ready';
        enableButton.disabled = state.enabled;
        enableButton.textContent = state.enabled ? 'PUSH ALERTS ACTIVE' : 'ENABLE PUSH ALERTS';
        disableButton.hidden = !state.enabled;
        help.textContent = state.enabled
          ? 'Θα λαμβάνεις τα προσωπικά reminders του MyLive ακόμη κι όταν η σελίδα δεν είναι ανοιχτή.'
          : 'Ο browser έχει ήδη άδεια. Πάτησε Enable για να συνδεθεί με το MyLive account σου.';
        return;
      }

      status.textContent = state.enabled ? 'ACCOUNT ON · ENABLE THIS DEVICE' : 'OFF';
      status.dataset.state = state.enabled ? 'ready' : 'off';
      enableButton.disabled = false;
      enableButton.textContent = state.enabled ? 'ENABLE THIS DEVICE' : 'ENABLE PUSH ALERTS';
      disableButton.hidden = !state.enabled;
      help.textContent = 'Η άδεια του browser θα εμφανιστεί μόνο αφού πατήσεις το κουμπί.';
    }

    function postPreference(enabled, sid) {
      var csrf = getCsrfToken();
      if (!csrf) return Promise.reject(new Error('Session token not found.'));

      var data = new FormData();
      data.append('csrf_token', csrf);
      data.append('enabled', enabled ? '1' : '0');
      data.append('subscriber_id', sid || '');

      return fetch('/mylive/push-settings.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: data
      }).then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || !payload.ok) {
            throw new Error((payload && payload.message) || 'Push settings update failed.');
          }
          return payload;
        });
      });
    }

    function syncSubscription() {
      enableButton.disabled = true;
      enableButton.textContent = 'CONNECTING…';
      status.textContent = 'CONNECTING…';
      status.dataset.state = 'ready';

      return ensureWebpushrSdk()
        .then(function () {
          return fetchWebpushrSubscriberId(0);
        })
        .then(function (sid) {
          window.webpushr('attributes', {
            'mylive_user_id': String(state.accountId),
            'mylive_push_enabled': '1'
          });

          return postPreference(true, sid);
        })
        .then(function (payload) {
          state.enabled = true;
          state.subscriptions = Number(payload.subscriptions || 1);
          render();
        })
        .catch(function (error) {
          enableButton.disabled = false;
          status.textContent = 'TRY AGAIN';
          status.dataset.state = 'error';
          help.textContent = error && error.message
            ? error.message
            : 'Δεν ήταν δυνατή η ενεργοποίηση των push notifications.';
          render();
        });
    }

    enableButton.addEventListener('click', function () {
      var permission = permissionState();

      if (permission === 'unsupported' || permission === 'denied') {
        render();
        return;
      }

      if (permission === 'granted') {
        syncSubscription();
        return;
      }

      Notification.requestPermission().then(function (result) {
        if (result === 'granted') {
          syncSubscription();
        } else {
          render();
        }
      }).catch(function () {
        render();
      });
    });

    disableButton.addEventListener('click', function () {
      disableButton.disabled = true;

      postPreference(false, '')
        .then(function (payload) {
          state.enabled = false;
          state.subscriptions = Number(payload.subscriptions || 0);

          if (window.__myliveWebpushrReady && typeof window.webpushr === 'function') {
            window.webpushr('attributes', { 'mylive_push_enabled': '0' });
          }

          disableButton.disabled = false;
          render();
        })
        .catch(function (error) {
          disableButton.disabled = false;
          help.textContent = error && error.message
            ? error.message
            : 'Δεν ήταν δυνατή η απενεργοποίηση των push notifications.';
        });
    });

    fetch('/mylive/push-settings.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        return response.json().then(function (payload) {
          if (!response.ok || !payload || !payload.ok) {
            throw new Error('Push settings are unavailable.');
          }
          return payload;
        });
      })
      .then(function (payload) {
        state.configured = !!payload.configured;
        state.enabled = !!payload.enabled;
        state.subscriptions = Number(payload.subscriptions || 0);
        state.accountId = Number(payload.account_id || 0);
        render();

        if (state.configured && state.enabled && permissionState() === 'granted') {
          syncSubscription();
        }
      })
      .catch(function () {
        state.configured = false;
        render();
      });
  }

  function registerMyLiveServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    // Webpushr owns the /mylive/ push scope; its worker also loads the existing MyLive PWA worker.
    navigator.serviceWorker.register('/mylive/webpushr-sw.js', {
      scope: '/mylive/'
    }).catch(function () {});
  }

  function startEmailAutomationTick() {
    function tick() {
      fetch('/mylive/notification-tick.php', {
        method: 'GET',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).catch(function () {});
    }

    window.setTimeout(tick, 10000);
    window.setInterval(tick, 60000);
  }

  document.addEventListener('DOMContentLoaded', function () {
    initInstallButtons();
    registerMyLiveServiceWorker();
    initPushNotifications();
    startEmailAutomationTick();
  });
}());
