/**
 * EWPLogDiagnose — AI-assisted diagnosis for the EWP Logger.
 *
 * Sends a plain-language issue description plus the viewer's current date
 * filters to the diagnose REST endpoint and renders the returned answer.
 *
 * Loaded via Dynamic Asset Loader when .ewp-log-diagnose is in the DOM.
 *
 * @class EWPLogDiagnose
 * @version 1.0.0
 * @since 1.3.0
 */
class EWPLogDiagnose {
    /**
     * Create a new EWPLogDiagnose instance.
     *
     * @param {HTMLElement} container - The .ewp-log-diagnose element.
     */
    constructor(container) {
        this.container = container;
        this.restUrl = container.dataset.restUrl || '';
        this.nonce = container.dataset.nonce || '';

        this.input = container.querySelector('#ewp-log-diagnose-issue');
        this.button = container.querySelector('#ewp-log-diagnose-run');
        this.range = container.querySelector('#ewp-log-diagnose-range');
        this.status = container.querySelector('#ewp-log-diagnose-status');
        this.result = container.querySelector('#ewp-log-diagnose-result');

        this.bind();
    }

    /**
     * Attach event listeners.
     *
     * @return {void}
     */
    bind() {
        if (!this.button) {
            return;
        }

        this.button.addEventListener('click', () => this.run());

        // Ctrl/Cmd+Enter submits from the textarea.
        if (this.input) {
            this.input.addEventListener('keydown', (event) => {
                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                    event.preventDefault();
                    this.run();
                }
            });
        }
    }

    /**
     * Controls belonging to this box rather than the log viewer's filters.
     *
     * @type {string[]}
     */
    static OWN_CONTROLS = ['ewp-log-diagnose-issue', 'ewp-log-diagnose-range'];

    /**
     * Build the request scope from the selected range option.
     *
     * A named preset sends just the range. "filters" mirrors whatever filter
     * fields the viewer currently renders - including any a plugin added -
     * by serializing the surrounding form rather than a fixed field list.
     *
     * @return {Object} Request parameters.
     */
    getScope() {
        const selected = this.range ? this.range.value : 'filters';

        if (selected !== 'filters') {
            return { range: selected };
        }

        const scope = { range: 'filters' };
        const form = this.container.closest('form');

        if (!form) {
            return scope;
        }

        const values = {};

        form.querySelectorAll('input, select, textarea').forEach((node) => {
            const name = node.name;

            if (!name || EWPLogDiagnose.OWN_CONTROLS.includes(node.id)) {
                return;
            }

            // Multi-selects render as name="field[]".
            const key = name.replace('[]', '');

            if (node.type === 'checkbox' || node.type === 'radio') {
                if (!node.checked) {
                    return;
                }
            }

            if (node.multiple) {
                Array.from(node.selectedOptions).forEach((option) => {
                    if (option.value !== '') {
                        (values[key] = values[key] || []).push(option.value);
                    }
                });
                return;
            }

            if (node.value !== '' && node.value !== null) {
                (values[key] = values[key] || []).push(node.value);
            }
        });

        Object.keys(values).forEach((key) => {
            if (values[key].length) {
                scope[key] = values[key].join(',');
            }
        });

        return scope;
    }

    /**
     * Toggle the busy state.
     *
     * @param {boolean} busy    - Whether a request is in flight.
     * @param {string}  message - Status text to display.
     * @return {void}
     */
    setBusy(busy, message = '') {
        if (this.button) {
            this.button.disabled = busy;
        }
        if (this.status) {
            this.status.textContent = message;
        }
    }

    /**
     * Run a diagnosis request.
     *
     * @return {Promise<void>}
     */
    async run() {
        const issue = this.input ? this.input.value.trim() : '';

        if (!issue) {
            this.setBusy(false, 'Describe the issue first.');
            return;
        }

        this.setBusy(true, 'Reading the logs...');
        if (this.result) {
            this.result.hidden = true;
            this.result.textContent = '';
        }

        try {
            const response = await fetch(`${this.restUrl}/logs/diagnose`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.nonce,
                },
                body: JSON.stringify(Object.assign({ issue }, this.getScope())),
            });

            const payload = await response.json();

            if (!response.ok) {
                const message = payload && payload.message ? payload.message : 'The request failed.';
                this.renderError(message);
                return;
            }

            this.renderAnswer(payload);
        } catch (error) {
            this.renderError(error.message || 'The request failed.');
        }
    }

    /**
     * Render a successful answer.
     *
     * @param {Object} payload - The REST response body.
     * @return {void}
     */
    renderAnswer(payload) {
        this.setBusy(false, '');

        if (!this.result) {
            return;
        }

        this.result.textContent = '';
        this.result.classList.remove('is-error');

        const answer = document.createElement('div');
        answer.className = 'ewp-log-diagnose-answer';
        // textContent keeps model output inert - never inject it as HTML.
        answer.textContent = payload.answer || '';
        this.result.appendChild(answer);

        if (payload.context) {
            const meta = document.createElement('p');
            meta.className = 'ewp-log-diagnose-meta';
            const applied = payload.context.filters_applied || [];
            meta.textContent = `Based on ${payload.context.entries_used} entries `
                + `from ${payload.context.date_from} to ${payload.context.date_to} `
                + `(${payload.context.total_in_window} total in window)`
                + (applied.length ? `, filtered by ${applied.join(', ')}.` : '.');
            this.result.appendChild(meta);
        }

        this.result.hidden = false;
    }

    /**
     * Render an error message.
     *
     * @param {string} message - The error text.
     * @return {void}
     */
    renderError(message) {
        this.setBusy(false, '');

        if (!this.result) {
            return;
        }

        this.result.textContent = message;
        this.result.classList.add('is-error');
        this.result.hidden = false;
    }
}

(function () {
    const init = () => {
        document.querySelectorAll('.ewp-log-diagnose').forEach((element) => {
            if (!element.dataset.ewpDiagnoseReady) {
                element.dataset.ewpDiagnoseReady = '1';
                new EWPLogDiagnose(element);
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
