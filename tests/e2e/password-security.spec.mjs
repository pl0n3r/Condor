import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const recoveryEmail = process.env.E2E_RECOVERY_EMAIL;
const recoveryPassword = process.env.E2E_RECOVERY_PASSWORD;
const recoveryNewPassword = process.env.E2E_RECOVERY_NEW_PASSWORD;
const recoveryChangedPassword = process.env.E2E_RECOVERY_CHANGED_PASSWORD;
const mailboxPath = process.env.CONDOR_E2E_MAILBOX_PATH;
const appVersion = process.env.APP_VERSION;

async function login(page, username, passwordValue) {
  await page.goto('/admin/login');
  await page.getByLabel('Correo').fill(username);
  await page.getByLabel('Contraseña').fill(passwordValue);
  await page.getByRole('button', { name: 'Ingresar' }).click();
}

async function mailboxRecords() {
  try {
    const raw = await readFile(mailboxPath, 'utf8');
    return raw
      .split('\n')
      .filter(Boolean)
      .map((line) => JSON.parse(line));
  } catch (error) {
    if (error?.code === 'ENOENT') {
      return [];
    }
    throw error;
  }
}

async function waitForResetUrl(recipient) {
  let resolved = '';

  await expect.poll(async () => {
    const records = await mailboxRecords();
    const message = records.findLast(
      (record) =>
        record.recipient === recipient &&
        record.templateKey === 'account_password_reset',
    );
    resolved = message?.templateData?.reset_url || '';
    return resolved;
  }, { timeout: 10_000 }).toMatch(
    /^https:\/\/www\.condorapp\.com\.co\/admin\/restablecer-contrasena#token=[a-f0-9]{64}$/,
  );

  return resolved;
}

test.describe('Seguridad de contraseña', () => {
  test.skip(!baseURL, 'Requiere aplicación E2E ejecutándose.');

  test('navega login, solicitud y canje sin exponer el fragmento', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByRole('link', { name: '¿Olvidaste tu contraseña?' }).click();

    await expect(page).toHaveURL(/\/admin\/recuperar-contrasena$/);
    await expect(
      page.getByRole('heading', { name: 'Recuperar contraseña' }),
    ).toBeVisible();

    const token = 'a'.repeat(64);
    await page.goto('/admin/restablecer-contrasena#token=' + token);

    await expect(
      page.getByRole('heading', { name: 'Restablecer contraseña' }),
    ).toBeVisible();
    await expect(page.locator('#password-reset-token')).toHaveValue(token);
    await expect(
      page.getByRole('button', { name: 'Actualizar contraseña' }),
    ).toBeEnabled();
    await expect(page).toHaveURL(/\/admin\/restablecer-contrasena$/);
  });

  test('recupera, autentica y cambia contraseña con transporte E2E aislado', async ({ page }) => {
    test.skip(
      !recoveryEmail ||
        !recoveryPassword ||
        !recoveryNewPassword ||
        !recoveryChangedPassword ||
        !mailboxPath,
      'Requiere identidad y mailbox E2E dedicados.',
    );

    await page.goto('/admin/recuperar-contrasena');
    await page.getByLabel('Correo').fill(recoveryEmail);
    await page.getByRole('button', { name: 'Enviar instrucciones' }).click();
    await expect(page.locator('output.alert')).toContainText(
      'Si el correo corresponde a una cuenta activa',
    );

    const resetUrl = new URL(await waitForResetUrl(recoveryEmail));
    const token = new URLSearchParams(resetUrl.hash.slice(1)).get('token');
    expect(token).toMatch(/^[a-f0-9]{64}$/);

    await page.goto(resetUrl.pathname + resetUrl.hash);
    await expect(page.locator('#password-reset-token')).toHaveValue(token);
    await page.getByLabel('Nueva contraseña').fill(recoveryNewPassword);
    await page.getByLabel('Confirmar contraseña').fill(recoveryNewPassword);
    await page.getByRole('button', { name: 'Actualizar contraseña' }).click();
    await expect(page).toHaveURL(/\/admin\/login$/);

    await login(page, recoveryEmail, recoveryNewPassword);
    await expect(page).toHaveURL(/\/admin$/);

    await page.goto('/admin/seguridad/contrasena');
    await page.getByLabel('Contraseña actual').fill(recoveryNewPassword);
    await page.getByLabel('Nueva contraseña').fill(recoveryChangedPassword);
    await page.getByLabel('Confirmar contraseña').fill(recoveryChangedPassword);
    await page.getByRole('button', { name: 'Actualizar contraseña' }).click();
    await expect(page.locator('output.alert')).toContainText('Contraseña actualizada.');

    await page.context().clearCookies();
    await login(page, recoveryEmail, recoveryNewPassword);
    await expect(page).toHaveURL(/\/admin\/login$/);
    await expect(page.getByRole('alert')).toContainText(
      'No pudimos iniciar sesión con esos datos.',
    );

    await login(page, recoveryEmail, recoveryChangedPassword);
    await expect(page).toHaveURL(/\/admin$/);
  });

  test('renderiza el cambio autenticado sin alterar el login existente', async ({ page }) => {
    test.skip(
      !email || !password || !appVersion,
      'Requiere usuario E2E autenticable y versión resuelta.',
    );

    await login(page, email, password);
    await expect(page).toHaveURL(/\/admin$/);

    await page.goto('/admin/seguridad/contrasena');
    await expect(
      page.getByRole('heading', { name: 'Cambiar contraseña' }),
    ).toBeVisible();
    await expect(page.locator('.version-line')).toHaveText('V ' + appVersion);
  });
});
