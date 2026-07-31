<?php

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use App\Services\Clients\ClienteAccessService;
use Filament\Resources\Pages\CreateRecord;

class CreateCliente extends CreateRecord
{
    protected static string $resource = ClienteResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    private ?string $accessPassword = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->accessPassword = $data['access_password'] ?? null;
        unset($data['access_password']);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(ClienteAccessService::class)->synchronize(
            $this->getRecord(),
            $this->accessPassword,
        );
    }
}
