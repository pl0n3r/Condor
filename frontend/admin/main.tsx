import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AdminApp } from './AdminApp';
import { PlatformOwnerApp } from './PlatformOwnerApp';
import './admin.css';

const adminRoot = document.getElementById('condor-admin-root');
const platformRoot = document.getElementById('condor-platform-root');

if (adminRoot) {
  const version = adminRoot.dataset.version ?? '0.1.0';
  const logoutToken = adminRoot.dataset.logoutToken ?? '';
  const accessToken = adminRoot.dataset.accessToken ?? '';

  createRoot(adminRoot).render(
    <StrictMode>
      <AdminApp
        version={version}
        logoutToken={logoutToken}
        accessToken={accessToken}
      />
    </StrictMode>,
  );
}

if (platformRoot) {
  const version = platformRoot.dataset.version ?? '0.1.0';
  const logoutToken = platformRoot.dataset.logoutToken ?? '';

  createRoot(platformRoot).render(
    <StrictMode>
      <PlatformOwnerApp
        version={version}
        logoutToken={logoutToken}
      />
    </StrictMode>,
  );
}
