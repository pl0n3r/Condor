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
