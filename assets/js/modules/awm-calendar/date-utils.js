/**
 * Date helpers shared by the calendar script and the inputs module.
 */

/**
 * Get the Unix timestamp (seconds) of the end of the given day, in UTC.
 *
 * @param {Date|string|number} d Any value accepted by the Date constructor.
 * @return {number} Timestamp in seconds.
 */
export function awm_timestamp(d) {
    "use strict";
    d = new Date(d);
    d = d.setUTCHours(24, 0, 0, 0);
    return (d / 1000);
}
