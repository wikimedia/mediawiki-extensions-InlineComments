/* eslint-disable @typescript-eslint/no-var-requires */
const path = require('path');
const webpack = require('webpack');
//const HtmlWebpackPlugin = require('html-webpack-plugin');
// const CopyPlugin = require('copy-webpack-plugin');
// const { CleanWebpackPlugin } = require('clean-webpack-plugin');

module.exports = {
  optimization: {
    usedExports: true,
  },
  entry: {
    app: './resources/lib/sidenote-connector/index.js',
  },
  plugins: [
    // new CleanWebpackPlugin(),
 //   new HtmlWebpackPlugin({
 //     template: 'demo/index.html',
 //   }),
  ],
  output: {
    filename: 'sidenotes.min.js',
    path: path.resolve(__dirname, 'resources'),
  },
  module: {
  },
  resolve: {
    extensions: ['.js'],
  },
  mode: 'production'
};
