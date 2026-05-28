/**
 * Webpack configuration for wp-extend plugin.
 *
 * Extends the default @wordpress/scripts webpack config.
 * Dynamic entries for modules + explicit global script entry.
 * Outputs to build/ with support for npm imports (e.g., slim-select).
 */
const path = require('path');
const fs = require('fs');
const defaultConfig = require("@wordpress/scripts/config/webpack.config");

/**
 * Build an entries object by scanning a folder.
 * Adds every .js file as: '<prefix>/<basename>': absolutePath
 * 
 * @param {string} folder - Relative folder path from project root
 * @param {string} prefix - Entry key prefix (e.g., 'modules', 'global')
 * @returns {Object} Entries object for webpack
 */
const buildEntries = (folder, prefix) => {
 const dir = path.resolve(__dirname, folder);
 return fs.readdirSync(dir)
  .filter(file => file.endsWith('.js'))
  .reduce((entries, file) => {
   const name = path.basename(file, '.js');
   entries[`${prefix}/${name}`] = path.join(dir, file);
   return entries;
  }, {});
};

module.exports = {
 ...defaultConfig,
 entry: {
  index: path.resolve(__dirname, 'src/index.js'),
  ...buildEntries('assets/js/modules', 'modules'),
  'global/awm-global-script': path.resolve(__dirname, 'assets/js/global/awm-global-script.js'),
   'admin/awm-admin-script': path.resolve(__dirname, 'assets/js/admin/awm-admin-script.js'),
 },
 output: {
  ...defaultConfig.output,
  path: path.resolve(__dirname, 'build'),
  filename: '[name].js',
   chunkFilename: '[name].chunk.js',
   publicPath: 'auto',
 },
  resolve: {
    ...defaultConfig.resolve,
    alias: {
      ...defaultConfig.resolve?.alias,
      '@modules': path.resolve(__dirname, 'assets/js/modules'),
    },
  },
  optimization: {
    ...defaultConfig.optimization,
    chunkIds: 'named',
    moduleIds: 'named',
  },
};