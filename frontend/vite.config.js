import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// The SPA is served by PHP as part of the same application, so Vite builds
// straight into public/ rather than to its own dist directory.
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: '../public',
    // public/ also holds index.php, the PHP front controller, which is
    // not Vite's to delete.
    emptyOutDir: false,
    assetsDir: 'assets',
  },
  server: {
    // During `npm run dev`, proxy the API to the PHP server so the two
    // halves behave as one origin exactly as they do in production.
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
