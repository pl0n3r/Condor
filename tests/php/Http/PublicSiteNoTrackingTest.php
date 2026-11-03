<?php

declare(strict_types=1);

namespace App\Tests\Http;

use DOMElement;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #787: el sitio informativo no debe fijar cookies ni descargar recursos
 * de terceros. La prueba hace GET reales al kernel Symfony, no mocks.
 */
final class PublicSiteNoTrackingTest extends WebTestCase
{
    public function testPublicPagesDoNotTrackOrLoadThirdPartyResources(): void
    {
        $pages = [
            '/' => 'Digitaliza tu negocio',
            '/producto' => 'Ocho módulos',
            '/modulos' => 'Más allá de vender',
            '/empezar' => 'Empieza vendiendo',
            '/soluciones' => 'Hay un Condor para cada negocio',
            '/seguridad' => 'Tu información, aparte',
        ];

        foreach ($pages as $path => $heading) {
            $client = static::createClient();
            $crawler = $client->request(
                'GET',
                $path,
                server: ['HTTP_HOST' => 'www.condorapp.com.co'],
            );

            self::assertResponseIsSuccessful('Ruta pública: '.$path);
            self::assertSelectorTextContains('h1', $heading);

            $response = $client->getResponse();
            self::assertSame(
                [],
                $response->headers->all('set-cookie'),
                'La ruta '.$path.' no debe establecer cookies mediante Set-Cookie.',
            );
            self::assertSame(
                [],
                $response->headers->getCookies(),
                'La ruta '.$path.' no debe emitir cookies HTTP.',
            );

            // Solo los scripts propios del movimiento: del mismo dominio, ninguno en línea ni de terceros.
            $approvedScripts = [
                '/cookie-consent.js',
                '/marketing-motion-gate.js',
                '/lib/gsap/gsap.min.js',
                '/lib/gsap/SplitText.min.js',
                '/marketing-motion.js',
            ];
            foreach ($crawler->filter('script') as $script) {
                self::assertContains(
                    $script->getAttribute('src'),
                    $approvedScripts,
                    $path.': script no aprobado o en línea',
                );
            }

            // No iframes, embeds, actualizaciones automáticas ni
            // elementos que puedan descargar un recurso sin clic explícito.
            foreach ([
                'iframe', 'object', 'embed',
                'meta[http-equiv="refresh"]', 'form[action^="http"]',
            ] as $selector) {
                self::assertCount(
                    0,
                    $crawler->filter($selector),
                    $path.': contenido activo inesperado '.$selector,
                );
            }

            // Los enlaces de navegación y canonical no son peticiones de
            // recursos: solo verificamos atributos que cargan al renderizar.
            foreach ([
                'img[src]' => 'src',
                'img[srcset]' => 'srcset',
                'source[src]' => 'src',
                'source[srcset]' => 'srcset',
                'video[src]' => 'src',
                'video[poster]' => 'poster',
                'audio[src]' => 'src',
                'track[src]' => 'src',
                'input[src]' => 'src',
                'image[href]' => 'href',
                'use[href]' => 'href',
            ] as $selector => $attribute) {
                foreach ($crawler->filter($selector) as $node) {
                    self::assertInstanceOf(DOMElement::class, $node);
                    $value = (string) $node->getAttribute($attribute);
                    if ($attribute === 'srcset') {
                        foreach (explode(',', $value) as $src) {
                            $parts = preg_split('/\s+/', trim($src));
                            self::assertLocalAsset((string) ($parts[0] ?? ''), $path);
                        }
                    } else {
                        self::assertLocalAsset($value, $path);
                    }
                }
            }

            foreach ($crawler->filter('link[href]') as $link) {
                self::assertInstanceOf(DOMElement::class, $link);
                $rel = strtolower(trim($link->getAttribute('rel')));
                if ($rel === 'canonical' || $rel === 'alternate') {
                    // Metadata: no dispara peticiones al cargarse la página.
                    continue;
                }
                self::assertLocalAsset($link->getAttribute('href'), $path);
            }

            foreach ($crawler->filter('style') as $style) {
                self::assertNoRemoteCss((string) $style->textContent, $path);
            }
            foreach ($crawler->filter('[style]') as $styled) {
                self::assertInstanceOf(DOMElement::class, $styled);
                self::assertNoRemoteCss($styled->getAttribute('style'), $path);
            }

            // No basta mirar HTML: un CSS same-origin puede cargar fuentes,
            // píxeles o @import remotos. Verificar los dos CSS del sitio.
            foreach (['public/app.css', 'public/marketing.css'] as $file) {
                $fullPath = dirname(__DIR__, 3).'/'.$file;
                self::assertFileExists($fullPath);
                $css = file_get_contents($fullPath);
                self::assertIsString($css);
                self::assertNoRemoteCss($css, $file);
            }

            self::ensureKernelShutdown();
        }
    }

    private static function assertLocalAsset(string $value, string $source): void
    {
        $resource = trim($value);
        self::assertNotSame('', $resource, $source.': recurso vacío.');
        self::assertFalse(
            str_starts_with($resource, '//')
            || (bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $resource),
            $source.': recurso remoto o esquema externo: '.$resource,
        );
    }

    private static function assertNoRemoteCss(string $css, string $source): void
    {
        self::assertDoesNotMatchRegularExpression(
            '~@import\s+(?:url\(\s*)?["\x27]?(?:https?:)?//~i',
            $css,
            $source.': @import externo.',
        );
        self::assertDoesNotMatchRegularExpression(
            '~url\(\s*["\x27]?(?:https?:)?//~i',
            $css,
            $source.': carga CSS externa.',
        );
    }
}
