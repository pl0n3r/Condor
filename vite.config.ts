import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        admin: resolve(import.meta.dirname, 'frontend/admin/main.tsx')
      },
      output: {
        entryFileNames: 'admin.js',
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
