(function () {
    'use strict';

    if (window.DeseoDialog) return;

    var queue = Promise.resolve();

    function ensureUi() {
        var root = document.getElementById('deseo-dialog-root');
        if (root) return root;

        var style = document.createElement('style');
        style.id = 'deseo-dialog-styles';
        style.textContent = [
            '.deseo-dialog-root{position:fixed;inset:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(0,0,0,.68);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}',
            '.deseo-dialog-root.is-open{display:flex}',
            '.deseo-dialog-card{width:min(100%,520px);overflow:hidden;border:1px solid rgba(255,255,255,.12);border-radius:28px;background:radial-gradient(circle at 92% 0,rgba(255,43,54,.12),transparent 32%),linear-gradient(145deg,#151517,#0b0b0d);box-shadow:0 38px 120px rgba(0,0,0,.62),inset 0 1px 0 rgba(255,255,255,.035);color:#fff;font-family:"Google Sans",Arial,sans-serif;transform:translateY(12px) scale(.985);opacity:0;transition:transform .18s ease,opacity .18s ease}',
            '.deseo-dialog-root.is-visible .deseo-dialog-card{transform:none;opacity:1}',
            '.deseo-dialog-head{padding:27px 28px 0}',
            '.deseo-dialog-kicker{display:flex;align-items:center;gap:10px;color:#ff2b36;font-size:9px;font-weight:900;letter-spacing:.17em;text-transform:uppercase}',
            '.deseo-dialog-kicker:before{content:"";width:24px;height:1px;background:#ff2b36}',
            '.deseo-dialog-title{margin:13px 0 0;color:#fff;font-size:28px;font-weight:700;line-height:1.08;letter-spacing:-.035em}',
            '.deseo-dialog-body{padding:15px 28px 25px;color:#aaaab1;font-size:14px;line-height:1.7;white-space:pre-line}',
            '.deseo-dialog-input{width:100%;min-height:50px;margin-top:16px;padding:0 14px;border:1px solid rgba(255,255,255,.11);border-radius:14px;background:#070708;color:#fff;font:500 14px "Google Sans",Arial,sans-serif;outline:none}',
            '.deseo-dialog-input:focus{border-color:rgba(255,43,54,.48);box-shadow:0 0 0 3px rgba(255,43,54,.07)}',
            '.deseo-dialog-actions{display:flex;justify-content:flex-end;gap:9px;padding:0 28px 27px}',
            '.deseo-dialog-button{min-height:46px;padding:0 19px;border-radius:999px;border:1px solid rgba(255,255,255,.11);background:#0a0a0c;color:#d4d4d8;font:800 10px "Google Sans",Arial,sans-serif;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;transition:.18s ease}',
            '.deseo-dialog-button:hover{transform:translateY(-1px);border-color:rgba(255,255,255,.22);color:#fff}',
            '.deseo-dialog-button.primary{border-color:transparent;background:linear-gradient(180deg,#ff4350,#d70020);color:#fff;box-shadow:0 12px 30px rgba(215,0,32,.2)}',
            '.deseo-dialog-button.primary:hover{background:#fff;color:#080809}',
            '.deseo-dialog-button.danger{border-color:rgba(255,43,54,.24);background:rgba(255,43,54,.08);color:#ff9299}',
            '.deseo-dialog-button.danger:hover{background:#ff2b36;color:#fff}',
            'body.deseo-dialog-open{overflow:hidden}',
            '@media(max-width:620px){.deseo-dialog-root{align-items:flex-end;padding:12px}.deseo-dialog-card{border-radius:24px}.deseo-dialog-head{padding:24px 21px 0}.deseo-dialog-title{font-size:25px}.deseo-dialog-body{padding:14px 21px 22px;font-size:13px}.deseo-dialog-actions{display:grid;grid-template-columns:1fr 1fr;padding:0 21px 21px}.deseo-dialog-button{width:100%;padding:0 12px}.deseo-dialog-actions.single{grid-template-columns:1fr}}'
        ].join('');

        root = document.createElement('div');
        root.id = 'deseo-dialog-root';
        root.className = 'deseo-dialog-root';
        root.setAttribute('aria-hidden', 'true');
        root.innerHTML =
            '<div class="deseo-dialog-card" role="dialog" aria-modal="true" aria-labelledby="deseo-dialog-title">' +
                '<div class="deseo-dialog-head">' +
                    '<div class="deseo-dialog-kicker" id="deseo-dialog-kicker">DESEO RADIO</div>' +
                    '<h2 class="deseo-dialog-title" id="deseo-dialog-title"></h2>' +
                '</div>' +
                '<div class="deseo-dialog-body" id="deseo-dialog-body"></div>' +
                '<div class="deseo-dialog-actions" id="deseo-dialog-actions"></div>' +
            '</div>';

        document.head.appendChild(style);
        document.body.appendChild(root);
        return root;
    }

    function openDialog(options) {
        return new Promise(function (resolve) {
            var root = ensureUi();
            var title = root.querySelector('#deseo-dialog-title');
            var body = root.querySelector('#deseo-dialog-body');
            var actions = root.querySelector('#deseo-dialog-actions');
            var kicker = root.querySelector('#deseo-dialog-kicker');
            var previousFocus = document.activeElement;
            var settled = false;
            var input = null;

            kicker.textContent = options.kicker || 'DESEO RADIO';
            title.textContent = options.title || 'Confirm action';
            body.textContent = '';
            actions.textContent = '';
            actions.classList.toggle('single', options.type === 'alert');

            var message = document.createElement('div');
            message.textContent = options.message || '';
            body.appendChild(message);

            if (options.type === 'prompt') {
                input = document.createElement('input');
                input.className = 'deseo-dialog-input';
                input.type = 'text';
                input.value = options.defaultValue || '';
                input.setAttribute('aria-label', options.inputLabel || 'Value');
                body.appendChild(input);
            }

            function close(value) {
                if (settled) return;
                settled = true;
                root.classList.remove('is-visible');
                document.body.classList.remove('deseo-dialog-open');

                window.setTimeout(function () {
                    root.classList.remove('is-open');
                    root.setAttribute('aria-hidden', 'true');
                    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
                    resolve(value);
                }, 170);

                document.removeEventListener('keydown', onKeydown, true);
            }

            function button(label, kind, value) {
                var el = document.createElement('button');
                el.type = 'button';
                el.className = 'deseo-dialog-button' + (kind ? ' ' + kind : '');
                el.textContent = label;
                el.addEventListener('click', function () {
                    close(value === '__prompt__' ? (input ? input.value : '') : value);
                });
                actions.appendChild(el);
                return el;
            }

            var cancelButton = null;
            var confirmButton = null;

            if (options.type === 'alert') {
                confirmButton = button(options.confirmLabel || 'OK', 'primary', true);
            } else {
                cancelButton = button(options.cancelLabel || 'Cancel', '', options.type === 'prompt' ? null : false);
                confirmButton = button(
                    options.confirmLabel || (options.type === 'prompt' ? 'Copy' : 'Confirm'),
                    options.danger ? 'danger' : 'primary',
                    options.type === 'prompt' ? '__prompt__' : true
                );
            }

            function onKeydown(event) {
                if (event.key === 'Escape' && options.type !== 'alert') {
                    event.preventDefault();
                    close(options.type === 'prompt' ? null : false);
                }
                if (event.key === 'Enter' && options.type === 'prompt' && input) {
                    event.preventDefault();
                    close(input.value);
                }
            }

            document.addEventListener('keydown', onKeydown, true);
            root.classList.add('is-open');
            root.setAttribute('aria-hidden', 'false');
            document.body.classList.add('deseo-dialog-open');

            window.requestAnimationFrame(function () {
                root.classList.add('is-visible');
                if (input) {
                    input.focus();
                    input.select();
                } else if (confirmButton) {
                    confirmButton.focus();
                }
            });

            root.onclick = function (event) {
                if (event.target === root && options.type !== 'alert') {
                    close(options.type === 'prompt' ? null : false);
                }
            };
        });
    }

    function enqueue(options) {
        var run = function () { return openDialog(options); };
        var result = queue.then(run, run);
        queue = result.catch(function () {});
        return result;
    }

    window.DeseoDialog = {
        alert: function (message, options) {
            options = options || {};
            return enqueue({
                type: 'alert',
                kicker: options.kicker,
                title: options.title || 'Notice',
                message: String(message || ''),
                confirmLabel: options.confirmLabel || 'OK'
            });
        },
        confirm: function (message, options) {
            options = options || {};
            return enqueue({
                type: 'confirm',
                kicker: options.kicker,
                title: options.title || 'Confirm action',
                message: String(message || ''),
                confirmLabel: options.confirmLabel || 'Confirm',
                cancelLabel: options.cancelLabel || 'Cancel',
                danger: !!options.danger
            });
        },
        prompt: function (message, defaultValue, options) {
            options = options || {};
            return enqueue({
                type: 'prompt',
                kicker: options.kicker,
                title: options.title || 'Copy link',
                message: String(message || ''),
                defaultValue: String(defaultValue || ''),
                inputLabel: options.inputLabel || 'Value',
                confirmLabel: options.confirmLabel || 'Done',
                cancelLabel: options.cancelLabel || 'Cancel'
            });
        }
    };

    function bindConfirmForms() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches || !form.matches('form[data-deseo-confirm]')) return;

            if (form.dataset.deseoConfirmBypass === '1') {
                delete form.dataset.deseoConfirmBypass;
                return;
            }

            var conditionalStatus = form.getAttribute('data-deseo-confirm-if-status');
            if (conditionalStatus) {
                var select = form.querySelector('select[name="status"]');
                if (!select || select.value !== conditionalStatus) return;
                if (form.dataset.fileRemoved === '1') return;
            }

            event.preventDefault();
            var submitter = event.submitter || null;

            window.DeseoDialog.confirm(
                form.getAttribute('data-deseo-confirm') || 'Να συνεχίσουμε;',
                {
                    title: form.getAttribute('data-deseo-confirm-title') || 'Επιβεβαίωση',
                    confirmLabel: form.getAttribute('data-deseo-confirm-label') || 'Συνέχεια',
                    cancelLabel: form.getAttribute('data-deseo-cancel-label') || 'Ακύρωση',
                    danger: form.hasAttribute('data-deseo-confirm-danger')
                }
            ).then(function (confirmed) {
                if (!confirmed) return;
                form.dataset.deseoConfirmBypass = '1';

                if (typeof form.requestSubmit === 'function') {
                    if (submitter) form.requestSubmit(submitter);
                    else form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindConfirmForms, { once: true });
    } else {
        bindConfirmForms();
    }
}());
