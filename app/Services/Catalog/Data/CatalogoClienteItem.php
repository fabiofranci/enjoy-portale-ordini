<?php

declare(strict_types=1);

namespace App\Services\Catalog\Data;

use App\Models\ListinoReferenza;
use LogicException;

final readonly class CatalogoClienteItem
{
    /**
     * @param  array<int, array{unita_contenitore:string, unita_contenuta:string, quantita:string}>  $packaging
     */
    public function __construct(
        public int $referenzaFornitoreId,
        public int $listinoReferenzaId,
        public int $listinoId,
        public string $fornitoreCode,
        public string $supplierCode,
        public ?string $customerArticleCode,
        public string $descrizione,
        public ?string $descrizioneEstesa,
        public string $prezzo,
        public ?string $priceUnit,
        public ?string $salesUnit,
        public ?string $immaginePath,
        public ?string $categoria,
        public string $listinoNome,
        public array $packaging,
    ) {}

    public static function fromModel(ListinoReferenza $price): self
    {
        $reference = $price->referenza;
        $listino = $price->listino;
        $supplier = $reference?->fornitore;

        if ($reference === null || $listino === null || $supplier === null) {
            throw new LogicException('La voce catalogo non contiene tutte le relazioni richieste.');
        }

        return new self(
            referenzaFornitoreId: (int) $reference->getKey(),
            listinoReferenzaId: (int) $price->getKey(),
            listinoId: (int) $listino->getKey(),
            fornitoreCode: (string) $supplier->code,
            supplierCode: (string) $reference->supplier_code,
            customerArticleCode: $reference->customer_article_code,
            descrizione: (string) $reference->descrizione,
            descrizioneEstesa: $reference->descrizione_estesa,
            prezzo: (string) $price->prezzo,
            priceUnit: $price->price_unit,
            salesUnit: $reference->sales_unit,
            immaginePath: $reference->immagine_path,
            categoria: $reference->categorie->first()?->nome,
            listinoNome: (string) $listino->nome_listino,
            packaging: $reference->packagings
                ->map(static fn ($packaging): array => [
                    'unita_contenitore' => (string) $packaging->unita_contenitore,
                    'unita_contenuta' => (string) $packaging->unita_contenuta,
                    'quantita' => (string) $packaging->quantita,
                ])
                ->values()
                ->all(),
        );
    }
}
