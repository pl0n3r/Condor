<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Opt-in sanitization for untrusted *rich-text fragments*, never whole pages.
 *
 * Existing full-page HTTP security headers are provided by SecurityHeadersSubscriber.
 * Only explicitly trusted templates may use the condor_safe_html Twig filter.
 */
final class SafeResponsePolicy extends AbstractExtension
{
    /** @var array<string, true> */
    private const ELEMENTS = [
        'p' => true,
        'br' => true,
        'strong' => true,
        'em' => true,
        'b' => true,
        'i' => true,
        'code' => true,
        'blockquote' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'a' => true,
    ];

    /** @var array<string, true> */
    private const TRANSPARENT_CONTAINERS = ['div' => true, 'span' => true];

    public function getFilters(): array
    {
        return [
            new TwigFilter('condor_safe_html', $this->sanitizeRichHtml(...), ['is_safe' => ['html']]),
        ];
    }

    public function sanitizeRichHtml(string $input): string
    {
        // Bounded parsing prevents maliciously large untrusted fragments.
        if ($input === '' || strlen($input) > 65536) {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            // No external entity or network access; the wrapper bounds the fragment.
            $valid = $doc->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
                .'<div data-condor-fragment="1">'.$input.'</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($valid === false) {
            return '';
        }

        $container = $doc->getElementsByTagName('div')->item(0);
        if (!$container instanceof DOMElement || $container->getAttribute('data-condor-fragment') !== '1') {
            return '';
        }

        return $this->renderChildren($container);
    }

    private function renderChildren(DOMNode $parent): string
    {
        $safe = '';
        foreach ($parent->childNodes as $node) {
            $safe .= $this->renderNode($node);
        }

        return $safe;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->textContent ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        if (!$node instanceof DOMElement) {
            // Drop comments, processing instructions and document type declarations.
            return '';
        }

        $tag = strtolower($node->tagName);
        if (isset(self::TRANSPARENT_CONTAINERS[$tag])) {
            // Strips every container attribute, including styles and event handlers.
            return $this->renderChildren($node);
        }

        if (!isset(self::ELEMENTS[$tag])) {
            // Fail closed: unknown/active elements lose their whole subtree.
            return '';
        }

        if ($tag === 'br') {
            return '<br>';
        }

        $content = $this->renderChildren($node);
        if ($tag !== 'a') {
            return '<'.$tag.'>'.$content.'</'.$tag.'>';
        }

        $href = $this->allowedLink($node->getAttribute('href'));
        if ($href === null) {
            return $content;
        }

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            .'" rel="noopener noreferrer nofollow">'.$content.'</a>';
    }

    private function allowedLink(string $raw): ?string
    {
        $uri = trim($raw);
        // Validate repeatedly decoded entities, not only the browser's first pass.
        for ($index = 0; $index < 3; ++$index) {
            $decoded = html_entity_decode($uri, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $uri) {
                break;
            }
            $uri = $decoded;
        }

        // Reject control characters, whitespace, backslashes and HTML delimiters.
        if ($uri === '' || preg_match('/[\x00-\x20\x7f\\\\<>"\x27]/', $uri) === 1) {
            return null;
        }

        // Accept only root-relative paths (never protocol-relative URLs) or
        // absolute HTTP(S) with a real host and without user-info credentials.
        if (str_starts_with($uri, '/') && !str_starts_with($uri, '//')) {
            return $uri;
        }

        $components = parse_url($uri);
        if (!is_array($components) || !in_array(strtolower((string) ($components['scheme'] ?? '')), ['https', 'http'], true)
            || !is_string($components['host'] ?? null) || $components['host'] === ''
            || isset($components['user']) || isset($components['pass'])
            || filter_var($uri, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        return $uri;
    }
}
