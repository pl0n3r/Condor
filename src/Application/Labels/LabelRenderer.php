<?php

declare(strict_types=1);

namespace App\Application\Labels;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identifiers\ScanIdentifier;
use App\Domain\Organization\Entity\Tenant;
use DomainException;

/** Deterministic tenant-checked print HTML. It never queries other tenants. */
final readonly class LabelRenderer
{
    public function render(
        Tenant $tenant,
        ProductVariant $variant,
        ScanIdentifier $identifier,
        LabelTemplate $template,
    ): string {
        if ($variant->tenant()->id() !== $tenant->id()
            || !$variant->isActive()
            || $identifier->tenantId() !== $tenant->id()
            || $identifier->kind() !== ScanIdentifier::VARIANT
            || $identifier->entityId() !== $variant->id()
        ) {
            throw new DomainException('La etiqueta no corresponde a una variante activa del tenant.');
        }

        $code = htmlspecialchars($identifier->code(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $variantName = htmlspecialchars($variant->name(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $templateName = htmlspecialchars($template->name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $width = $template->widthMillimeters;
        $height = $template->heightMillimeters;

        // Literal, bounded CSS values; all untrusted text is escaped separately.
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<title>Etiqueta '.$templateName.'</title>'
            .'<style>@page{size:'.$width.'mm '.$height.'mm;margin:0}'
            .'html,body{margin:0;padding:0;font-family:sans-serif}'
            .'.label{box-sizing:border-box;width:'.$width.'mm;height:'.$height.'mm;padding:3mm;'
            .'overflow:hidden;break-inside:avoid}'
            .'.name{font-size:11pt;font-weight:bold;overflow-wrap:anywhere}'
            .'.code{font-family:monospace;font-size:12pt;letter-spacing:.4mm;overflow-wrap:anywhere}'
            .'</style></head><body><section class="label">'
            .'<div class="name">'.$variantName.'</div>'
            .'<div class="code" data-scan-code="'.$code.'">'.$code.'</div>'
            .'</section></body></html>';
    }
}
