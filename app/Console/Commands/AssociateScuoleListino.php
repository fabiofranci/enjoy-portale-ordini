<?php

namespace App\Console\Commands;

use App\Models\CentroCosto;
use App\Models\Cliente;
use App\Models\Listino;
use App\Models\Product;
use App\Models\User;
use App\Services\PrezziService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssociateScuoleListino extends Command
{
    protected $signature = 'scuole:associate-listino
        {--listino=Scuole : Nome del listino da associare}
        {--search=SCUOLE : Testo per cercare cliente o centro di costo}
        {--cliente-id= : ID cliente esistente}
        {--centro-costo-id= : ID centro di costo esistente}
        {--user-id= : ID utente cliente da usare per verificare PrezziService}
        {--execute : Esegue l’associazione reale}';

    protected $description = 'Associa il listino Scuole a un centro di costo esistente senza creare anagrafiche.';

    public function handle(): int
    {
        $listinoName = trim((string) $this->option('listino'));
        $listinoName = $listinoName !== '' ? $listinoName : 'Scuole';
        $listino = Listino::query()->where('nome_listino', $listinoName)->first();

        if (!$listino instanceof Listino) {
            $this->error("Listino {$listinoName} non trovato.");

            return self::FAILURE;
        }

        $centroCosto = $this->resolveCentroCosto();

        if (!$centroCosto instanceof CentroCosto) {
            return self::FAILURE;
        }

        $centroCosto->loadMissing('cliente');
        $this->line('Centro di costo selezionato:');
        $this->table(
            ['centro_costo_id', 'centro_costo', 'cliente_id', 'cliente', 'listino_id', 'listino'],
            [[
                (string) $centroCosto->id,
                (string) $centroCosto->nome,
                (string) $centroCosto->cliente_id,
                (string) ($centroCosto->cliente?->nome ?? ''),
                (string) $listino->id,
                (string) $listino->nome_listino,
            ]]
        );

        if (!$this->option('execute')) {
            $this->warn('Associazione non eseguita. Riesegui con --execute per scrivere centro_costo_listino.');
            $this->verifyUserIfRequested($listino, $centroCosto);

            return self::SUCCESS;
        }

        $alreadyExists = DB::table('centro_costo_listino')
            ->where('centro_costo_id', $centroCosto->id)
            ->where('listino_id', $listino->id)
            ->exists();

        if (!$alreadyExists) {
            DB::table('centro_costo_listino')->insert([
                'centro_costo_id' => $centroCosto->id,
                'listino_id' => $listino->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info($alreadyExists ? 'Associazione gia\' presente.' : 'Associazione creata.');
        $this->verifyUserIfRequested($listino, $centroCosto);

        return self::SUCCESS;
    }

    private function resolveCentroCosto(): ?CentroCosto
    {
        $centroCostoId = $this->option('centro-costo-id');

        if ($centroCostoId !== null && $centroCostoId !== '') {
            $centroCosto = CentroCosto::query()->with('cliente')->find((int) $centroCostoId);

            if (!$centroCosto instanceof CentroCosto) {
                $this->error("Centro di costo {$centroCostoId} non trovato.");

                return null;
            }

            return $centroCosto;
        }

        $clienteId = $this->option('cliente-id');

        if ($clienteId !== null && $clienteId !== '') {
            $cliente = Cliente::query()->find((int) $clienteId);

            if (!$cliente instanceof Cliente) {
                $this->error("Cliente {$clienteId} non trovato.");

                return null;
            }

            return $this->singleCentroCostoForCliente($cliente);
        }

        $search = trim((string) $this->option('search'));
        $search = $search !== '' ? $search : 'SCUOLE';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        $centriCosto = CentroCosto::query()
            ->with('cliente')
            ->where('nome', 'like', $like)
            ->orWhereHas('cliente', static fn ($query) => $query->where('nome', 'like', $like))
            ->orderBy('id')
            ->get();

        if ($centriCosto->count() === 1) {
            return $centriCosto->first();
        }

        if ($centriCosto->count() > 1) {
            $this->error('Trovati piu\' centri di costo compatibili. Indica --centro-costo-id.');
            $this->renderCentriCosto($centriCosto);

            return null;
        }

        $clienti = Cliente::query()
            ->where('nome', 'like', $like)
            ->orderBy('id')
            ->get();

        if ($clienti->count() > 1) {
            $this->error('Trovati piu\' clienti compatibili. Indica --cliente-id o --centro-costo-id.');
            $this->table(
                ['cliente_id', 'nome', 'partita_iva'],
                $clienti->map(static fn (Cliente $cliente): array => [
                    (string) $cliente->id,
                    (string) $cliente->nome,
                    (string) $cliente->partita_iva,
                ])->all()
            );

            return null;
        }

        if ($clienti->count() === 1) {
            return $this->singleCentroCostoForCliente($clienti->first());
        }

        $this->error("Nessun cliente o centro di costo compatibile con {$search}.");

        return null;
    }

    private function singleCentroCostoForCliente(Cliente $cliente): ?CentroCosto
    {
        $centriCosto = $cliente->centriCosto()->with('cliente')->orderBy('id')->get();

        if ($centriCosto->count() === 1) {
            return $centriCosto->first();
        }

        if ($centriCosto->count() > 1) {
            $this->error('Il cliente ha piu\' centri di costo. Indica --centro-costo-id.');
            $this->renderCentriCosto($centriCosto);

            return null;
        }

        $this->error('Il cliente esiste ma non ha centri di costo associabili. Nessuna anagrafica e\' stata creata.');

        return null;
    }

    private function renderCentriCosto($centriCosto): void
    {
        $this->table(
            ['centro_costo_id', 'centro_costo', 'cliente_id', 'cliente'],
            $centriCosto->map(static fn (CentroCosto $centroCosto): array => [
                (string) $centroCosto->id,
                (string) $centroCosto->nome,
                (string) $centroCosto->cliente_id,
                (string) ($centroCosto->cliente?->nome ?? ''),
            ])->all()
        );
    }

    private function verifyUserIfRequested(Listino $listino, CentroCosto $centroCosto): void
    {
        $userId = $this->option('user-id');

        if ($userId === null || $userId === '') {
            return;
        }

        $user = User::query()->find((int) $userId);

        if (!$user instanceof User) {
            $this->warn("Utente {$userId} non trovato: verifica PrezziService saltata.");

            return;
        }

        if ((int) ($user->cliente_id ?? 0) !== (int) $centroCosto->cliente_id) {
            $this->warn('L\'utente indicato non appartiene al cliente del centro di costo selezionato.');

            return;
        }

        $product = $listino->products()
            ->wherePivot('ordinabile', true)
            ->whereNotNull('listino_prodotto.prezzo')
            ->first();

        if (!$product instanceof Product) {
            $this->warn('Nessun prodotto ordinabile sul listino: verifica PrezziService saltata.');

            return;
        }

        PrezziService::clearCaches();
        $pricing = PrezziService::prezzoVisibile($product, $user);

        if (($pricing['listino_id'] ?? null) === $listino->id) {
            $this->info('PrezziService seleziona il listino indicato per l\'utente richiesto.');

            return;
        }

        $this->warn('PrezziService non ha selezionato il listino indicato per l\'utente richiesto.');
    }
}
