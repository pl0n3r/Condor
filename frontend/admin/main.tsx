import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AdminApp } from './AdminApp';
import './admin.css';

const rootElement = document.getElementById('condor-admin-root');

if (rootElement) {
  const version = rootElement.dataset.version ?? '0.1.0';
  const logoutToken = rootElement.dataset.logoutToken ?? '';
  const accessToken = rootElement.dataset.accessToken ?? '';

  createRoot(rootElement).render(
    <StrictMode>
      <AdminApp
        version={version}
        logoutToken={logoutToken}
        accessToken={accessToken}
      />
    </StrictMode>,
  );
}
