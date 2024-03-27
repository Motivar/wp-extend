const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require('path');

module.exports = {
 ...defaultConfig,
 entry: {
  index: path.resolve(__dirname, 'src/index.js')
 },
 output: {
  path: path.resolve(__dirname, 'build'),
  filename: '[name].js'
 },
 module: {
  ...defaultConfig.module,
  rules: [
   ...defaultConfig.module.rules,
   {
    test: /\.js$/,
    exclude: /node_modules/,
    use: {
     loader: 'babel-loader',
     options: {
      presets: ['@babel/preset-env', '@babel/preset-react']
     }
    }
   }
  ]
 }
};