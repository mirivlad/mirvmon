import { cp, mkdir, rm } from 'node:fs/promises';

const files = [
  ['node_modules/bootstrap/dist/css/bootstrap.min.css', 'public/vendor/bootstrap/bootstrap.min.css'],
  ['node_modules/bootstrap/dist/css/bootstrap.min.css.map', 'public/vendor/bootstrap/bootstrap.min.css.map'],
  ['node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', 'public/vendor/bootstrap/bootstrap.bundle.min.js'],
  ['node_modules/bootstrap/dist/js/bootstrap.bundle.min.js.map', 'public/vendor/bootstrap/bootstrap.bundle.min.js.map'],
  ['node_modules/@fortawesome/fontawesome-free/css/all.min.css', 'public/vendor/fontawesome/css/all.min.css'],
  ['node_modules/@fortawesome/fontawesome-free/webfonts', 'public/vendor/fontawesome/webfonts'],
  ['node_modules/chart.js/dist/chart.umd.js', 'public/vendor/chart.js/chart.umd.js'],
  ['node_modules/chart.js/dist/chart.umd.js.map', 'public/vendor/chart.js/chart.umd.js.map'],
  ['node_modules/hammerjs/hammer.min.js', 'public/vendor/hammerjs/hammer.min.js'],
  ['node_modules/chartjs-plugin-zoom/dist/chartjs-plugin-zoom.min.js', 'public/vendor/chartjs-plugin-zoom/chartjs-plugin-zoom.min.js']
];

for (const [source, destination] of files) {
  await rm(destination, { recursive: true, force: true });
  await mkdir(destination.substring(0, destination.lastIndexOf('/')), {
    recursive: true
  });
  await cp(source, destination, { recursive: true, force: true });
}
