import { expect, test } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;

test.describe('Plan Configurator público', () => {
  test.skip(!baseURL, 'Requiere aplicación E2E ejecutándose.');

  test('consume APIs canónicas, recalcula preview y conserva resumen accesible', async ({ page }) => {
    await page.route('**/api/public/configurator/catalog', async route => {
      await route.fulfill({json:{
        plans:[
          {key:'business',name:'Negocio',version:1,monthly_amount:199900,annual_amount:1999000,quote_required:false,verticals:['commerce','legal']},
          {key:'enterprise',name:'Enterprise',version:1,monthly_amount:null,annual_amount:null,quote_required:true,verticals:['legal']}
        ],
        verticals:[{key:'commerce',name:'Comercio'},{key:'legal',name:'Legal / abogados'}]
      }});
    });
    await page.route('**/api/public/configurator/options**', async route => {
      const legal=new URL(route.request().url()).searchParams.get('vertical')==='legal';
      await route.fulfill({json:{
        plan:{key:'business',name:'Negocio',version:1,currency:'COP',monthly_amount:199900,annual_amount:1999000,quote_required:false},
        vertical:{key:legal?'legal':'commerce',name:legal?'Legal / abogados':'Comercio'},
        limits:{companies:1,locations:3,users:10},
        capabilities:legal
          ? [{key:'legal-cases',name:'Casos / expedientes',priority:1}]
          : [{key:'inventory',name:'Inventario',priority:1}],
        addons:[{key:'production-lite',name:'Producción Lite',monthly_amount:99900,quote_required:false,selectable:true}]
      }});
    });

    let previews=0;
    await page.route('**/api/public/configurator/quote', async route => {
      previews += 1;
      const request=route.request().postDataJSON();
      const proposal=request.plan==='enterprise';
      await route.fulfill({json:{quote:{
        cycle:request.cycle,
        quantities:request.quantities,
        addons:request.addons,
        base_amount:proposal?null:199900,
        addon_amount:0,
        total_amount:proposal?null:199900,
        proposal_required:proposal,
        valid_until:'2026-10-27T12:00:00+00:00'
      }}});
    });

    await page.goto('/configurar-condor');
    await expect(page.getByRole('heading',{name:'Encuentra una configuración que se adapte a tu operación.'})).toBeVisible();
    await expect(page.getByRole('complementary',{name:'Resumen de configuración'})).toBeVisible();

    await page.getByLabel('Vertical').selectOption('legal');
    await expect(page.getByText('Casos / expedientes')).toBeVisible();
    await expect(page.getByText('Inventario')).toHaveCount(0);

    await page.getByLabel('users').fill('12');
    await expect.poll(()=>previews).toBeGreaterThan(0);

    await page.getByLabel('Enterprise').check();
    await expect(page.getByText('Propuesta personalizada')).toBeVisible();
    await expect(page.getByText('No mostramos un precio inventado.')).toBeVisible();
  });

  test('mantiene resumen sticky usable en móvil', async ({ page }) => {
    await page.route('**/api/public/configurator/catalog', route => route.fulfill({json:{
      plans:[{key:'business',name:'Negocio',version:1,monthly_amount:199900,annual_amount:1999000,quote_required:false,verticals:['legal']}],
      verticals:[{key:'legal',name:'Legal / abogados'}]
    }}));
    await page.route('**/api/public/configurator/options**', route => route.fulfill({json:{
      plan:{key:'business',name:'Negocio',version:1,currency:'COP',monthly_amount:199900,annual_amount:1999000,quote_required:false},
      vertical:{key:'legal',name:'Legal / abogados'},limits:{companies:1,locations:3,users:10},capabilities:[],addons:[]
    }}));
    await page.route('**/api/public/configurator/quote', route => route.fulfill({json:{quote:{
      cycle:'monthly',quantities:{companies:1,locations:3,users:10},addons:[],base_amount:199900,addon_amount:0,total_amount:199900,proposal_required:false,valid_until:'2026-10-27T12:00:00+00:00'
    }}}));
    await page.setViewportSize({width:390,height:844});
    await page.goto('/configurar-condor');
    const summary=page.getByRole('complementary',{name:'Resumen de configuración'});
    await expect(summary).toBeVisible();
    await expect(summary).toHaveCSS('position','sticky');
  });
});
