<?php

declare(strict_types=1);

namespace App\Tests\Domain\Commerce;

use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalesChannelTest extends TestCase
{
    public function testChannelKeepsExplicitCommercialScope(): void
    {
        [$tenant, $source, $priceList] = $this->scope();

        $channel = new SalesChannel(
            $tenant,
            '  Tienda web  ',
            '  tienda-web  ',
            $source,
            $priceList,
        );

        self::assertSame('Tienda web', $channel->name());
        self::assertSame('tienda-web', $channel->slug());
        self::assertSame(SalesChannel::TYPE_ECOMMERCE, $channel->type());
        self::assertSame($tenant->id(), $channel->tenant()->id());
        self::assertSame($source->legalEntity()->id(), $channel->legalEntity()->id());
        self::assertSame($source->id(), $channel->inventorySource()->id());
        self::assertSame($priceList->id(), $channel->priceList()->id());
        self::assertTrue($channel->isPublishable());
    }

    public function testChannelRejectsCrossTenantInventorySource(): void
    {
        [$tenant, , $priceList] = $this->scope();
        [, $foreignSource] = $this->scope('otra');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('deben pertenecer al mismo tenant');

        new SalesChannel(
            $tenant,
            'Web',
            'web',
            $foreignSource,
            $priceList,
        );
    }

    public function testChannelRejectsCrossTenantPriceList(): void
    {
        [$tenant, $source] = $this->scope();
        [, , $foreignPriceList] = $this->scope('otra');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('deben pertenecer al mismo tenant');

        new SalesChannel(
            $tenant,
            'Web',
            'web',
            $source,
            $foreignPriceList,
        );
    }

    public function testChannelFailsClosedWhenEffectiveReferencesAreInactive(): void
    {
        [$tenant, $source, $priceList] = $this->scope();
        $channel = new SalesChannel(
            $tenant,
            'Web',
            'web',
            $source,
            $priceList,
        );

        $source->deactivate();
        self::assertFalse($channel->isPublishable());

        $source->reactivate($source->name(), $source->slug());
        self::assertTrue($channel->isPublishable());

        $priceList->deactivate();
        self::assertFalse($channel->isPublishable());

        $priceList->activate();
        $channel->deactivate();
        self::assertFalse($channel->isPublishable());

        $channel->activate();
        self::assertTrue($channel->isPublishable());
    }

    public function testChannelRejectsUnsupportedTypeAndInvalidSlug(): void
    {
        [$tenant, $source, $priceList] = $this->scope();

        try {
            new SalesChannel(
                $tenant,
                'Web',
                'slug invalido',
                $source,
                $priceList,
            );
            self::fail('El slug inválido debía rechazarse.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tipo de canal no está soportado');

        new SalesChannel(
            $tenant,
            'Punto físico',
            'punto-fisico',
            $source,
            $priceList,
            'physical',
        );
    }

    /**
     * @return array{0: Tenant, 1: InventorySource, 2: PriceList}
     */
    private function scope(string $prefix = 'empresa'): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant(
            ucfirst($prefix).' '.$suffix,
            $prefix.'-'.$suffix,
        );
        $legalEntity = new LegalEntity(
            $tenant,
            ucfirst($prefix).' SAS',
            null,
            true,
        );
        $source = new InventorySource(
            $tenant,
            $legalEntity,
            'Bodega web',
            'bodega-web',
            InventorySource::TYPE_LOGICAL,
        );
        $priceList = new PriceList(
            $tenant,
            'Lista web',
            'lista-web',
        );

        return [$tenant, $source, $priceList];
    }
}
