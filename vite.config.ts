import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react()],
  // The application serves the emitted entrypoints and lazy chunks at /build/.
  // Without this base Vite's modulepreload URLs request /chunks/ and 404.
  base: '/build/',
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        admin: 'frontend/admin/main.tsx',
        configurator: 'frontend/configurator/main.tsx'
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) => (
          assetInfo.name?.endsWith('.css')
            ? 'admin.css'
            : 'assets/[name]-[hash][extname]'
        )
      }
    }
  }
});
