import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  publicDir: 'public',
  build: {
    outDir: 'assets/dist',
    emptyOutDir: false,
    manifest: true,
    rollupOptions: {
      input: {
        'dashboard.js': resolve(__dirname, 'assets/js/dashboard.js'),
        'assistant.js': resolve(__dirname, 'assets/js/assistant.js'),
        'auth.js': resolve(__dirname, 'assets/js/auth.js'),
        'discipline-engine.js': resolve(__dirname, 'assets/js/discipline-engine.js'),
        'settings.js': resolve(__dirname, 'assets/js/settings.js'),
        'student-management.js': resolve(__dirname, 'assets/js/student-management.js'),
        'theme.js': resolve(__dirname, 'assets/js/theme.js'),
        'user-management.js': resolve(__dirname, 'assets/js/user-management.js'),
        'toast.js': resolve(__dirname, 'assets/js/toast.js'),
        'sidebar.js': resolve(__dirname, 'assets/js/sidebar.js'),
        'vendor/chart.js': resolve(__dirname, 'assets/js/vendor/chart.js'),
        'assistant.css': resolve(__dirname, 'assets/css/assistant.css'),
        'auth.css': resolve(__dirname, 'assets/css/auth.css'),
        'discipline-engine.css': resolve(__dirname, 'assets/css/discipline-engine.css'),
        'settings.css': resolve(__dirname, 'assets/css/settings.css'),
        'student-management.css': resolve(__dirname, 'assets/css/student-management.css'),
        'official.css': resolve(__dirname, 'assets/css/official.css'),
        'mobile-first.css': resolve(__dirname, 'assets/css/mobile-first.css'),
        'tokens.css': resolve(__dirname, 'assets/css/tokens.css'),
        'components.css': resolve(__dirname, 'assets/css/components.css')
      }
    }
  }
});
