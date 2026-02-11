/**
 * Webpack configuration for wp-extend plugin.
 *
 * Extends the default @wordpress/scripts webpack config.
 * Entry: src/index.js → Output: build/index.js (defaults).
 */
const defaultConfig = require("@wordpress/scripts/config/webpack.config");

module.exports = {
 ...defaultConfig,
};