<?php

namespace App\Filament\Resources\Clientes\Pages;

use App\Filament\Resources\Clientes\ClienteResource;
use App\Services\Clients\ClienteAccessService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCliente extends EditRecord
{
    protected static string $resource = ClienteResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    private ?string $accessPassword = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['email'] = $this->getRecord()->accessUser?->email ?? $data['email'];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->accessPassword = $data['access_password'] ?? null;
        unset($data['access_password']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(ClienteAccessService::class)->synchronize(
            $this->getRecord(),
            $this->accessPassword,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
