import { expect, test } from '@playwright/test';

test.describe('harness técnico de navegador', () => {
  test('renderiza, expone semántica accesible y responde a interacción', async ({ page }) => {
    await page.setContent(`
      <main>
        <h1>Condor App — harness E2E</h1>
        <p>Esta página existe únicamente para validar la infraestructura de navegador.</p>
        <button type="button" data-testid="accion-harness">Incrementar</button>
        <output role="status" aria-live="polite">0</output>
        <script>
          const button = document.querySelector('[data-testid="accion-harness"]');
          const output = document.querySelector('[role="status"]');
          button.addEventListener('click', () => {
            output.textContent = String(Number(output.textContent) + 1);
          });
        </script>
      </main>
    `);

    await expect(
      page.getByRole('heading', { name: 'Condor App — harness E2E' })
    ).toBeVisible();

    await page.getByTestId('accion-harness').click();
    await expect(page.getByRole('status')).toHaveText('1');
  });
});
