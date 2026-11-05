/**
 * Calendar (jQuery UI datepicker) script.
 *
 * Loaded on demand by the Dynamic Asset Loader when a `.awm_cl_date` field is in the DOM
 * (registered in AWM_Meta::register_calendar_assets()), together with jQuery UI core, the
 * datepicker and its stylesheet. Exposes `window.awmCalendarInit()`, which the inputs module's
 * `awm_create_calendar()` calls for fields added later (AJAX, repeaters).
 */
import { awm_timestamp } from './awm-calendar/date-utils.js';

const FIELD_SELECTOR = '.awm_cl_date:not(.hasDatepicker)';
const READY_TIMEOUT_MS = 10000;
const READY_POLL_MS = 50;

const DEFAULT_PARAMETERS = {
    dateFormat: 'dd-mm-yy',
    changeMonth: false,
    altFormat: 'YYYY-DD-MM',
    minDate: '-24M',
    maxDate: '+24M',
};

let defaultsApplied = false;

/**
 * Resolve once jQuery and its datepicker plugin exist (they may still be executing when this runs).
 *
 * @return {Promise<Function|null>} jQuery, or null when it never became available.
 */
function whenDatepickerReady() {
    const ready = () => window.jQuery && window.jQuery.fn && window.jQuery.fn.datepicker;
    return new Promise((resolve) => {
        if (ready()) {
            resolve(window.jQuery);
            return;
        }
        const started = Date.now();
        const timer = setInterval(() => {
            if (ready()) {
                clearInterval(timer);
                resolve(window.jQuery);
                return;
            }
            if (Date.now() - started > READY_TIMEOUT_MS) {
                clearInterval(timer);
                resolve(null);
            }
        }, READY_POLL_MS);
    });
}

/**
 * Apply the localized datepicker defaults (month and day names, first day of week) once.
 *
 * @param {Function} $ jQuery.
 * @return {void}
 */
function applyRegionalDefaults($) {
    if (defaultsApplied) {
        return;
    }
    defaultsApplied = true;
    const regional = window.awmCalendar && window.awmCalendar.regional;
    if (regional) {
        $.datepicker.setDefaults(regional);
    }
}

/**
 * Parse the `date-params` attribute (single-quoted JSON) of a field.
 *
 * @param {Function} $ jQuery.
 * @param {Element} el The date input.
 * @return {Object} Extra datepicker options, empty when missing or invalid.
 */
function readExtraParameters($, el) {
    const raw = $(el).attr('date-params');
    if (!raw) {
        return {};
    }
    try {
        return JSON.parse(raw.replace(/'/g, '"'));
    } catch (e) {
        return {};
    }
}

/**
 * Build the onSelect handler that keeps a linked end-date field (`data-change`) after the start date.
 *
 * @param {Function} $ jQuery.
 * @param {string} idd Id of the date input.
 * @return {Function} Datepicker onSelect callback.
 */
function buildOnSelect($, idd) {
    return function (d, i) {
        if (d === i.lastVal) {
            return;
        }
        const date = $('#' + idd).datepicker('getDate');
        const change = $('#' + idd).attr('data-change');
        if (date !== null && change) {
            syncLinkedDate($, date, change);
        }
        document.getElementById(idd).dispatchEvent(new Event('change'));
    };
}

/**
 * Move the linked field's minimum date after the selected date, pushing its value if it ended up earlier.
 *
 * @param {Function} $ jQuery.
 * @param {Date} date Selected date (mutated: shifted by the linked field's `data-days`).
 * @param {string} change Id of the linked date input.
 * @return {void}
 */
function syncLinkedDate($, date, change) {
    const nextDate = $('#' + change).datepicker('getDate');
    const addDays = $('#' + change).attr('data-days') ? parseInt($('#' + change).attr('data-days')) : 1;
    const stop = nextDate !== null && awm_timestamp(date) > awm_timestamp(nextDate);
    date.setDate(date.getDate() + addDays);
    $('#' + change).datepicker('option', 'minDate', date);
    if (stop) {
        $('#' + change).datepicker('setDate', date);
    }
}

/**
 * Initialize one date input.
 *
 * @param {Function} $ jQuery.
 * @param {Element} el The date input.
 * @return {{id: string, value: string}} Id and value to restore after initialisation.
 */
function initField($, el) {
    const idd = $(el).attr('id');
    const parameters = { ...DEFAULT_PARAMETERS, ...readExtraParameters($, el) };
    if ($(el).hasClass('awm-no-limit-date')) {
        parameters.minDate = null;
    }
    parameters.onSelect = buildOnSelect($, idd);
    const initial = { id: idd, value: $('#' + idd).val() };
    $('#' + idd).datepicker(parameters);
    return initial;
}

/**
 * Create the date pickers for every `.awm_cl_date` field that has none yet.
 *
 * @return {Promise<void>} Resolves when the fields are initialised.
 */
export async function awmCalendarInit() {
    if (!document.querySelector(FIELD_SELECTOR)) {
        return;
    }
    const $ = await whenDatepickerReady();
    if (!$) {
        return;
    }
    applyRegionalDefaults($);
    const values = $(FIELD_SELECTOR).toArray().map((el) => initField($, el));
    values.forEach((val) => {
        $('#' + val.id).datepicker('setDate', '');
        if (val.value != '' && val.value != 0) {
            $('#' + val.id).datepicker('setDate', val.value);
            $('#' + val.id).change();
        }
    });
}

window.awmCalendarInit = awmCalendarInit;
window.awm_timestamp = window.awm_timestamp || awm_timestamp;
awmCalendarInit();
