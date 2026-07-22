<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Services\PrezziService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * Mostra il carrello
     */
    public function show()
    {
        $cart = session()->get('cart', []);

        $totale = collect($cart)->sum(
            fn ($item) => $item['prezzo_unitario'] * $item['quantita']
        );

        return view('filament.client.carrello.index', compact('cart', 'totale'));
    }

    /**
     * Aggiunge un prodotto al carrello
     * Supporta unità (NR / CF / CT)
     */
    public function add(Request $request, Product $prodotto)
    {
        $cart = session()->get('cart', []);

        // Unità selezionata (fallback = unità base del prodotto)
        $unita = trim((string) $request->input('unita', $prodotto->unita_misura ?? 'NR'));
        $unita = $unita !== '' ? $unita : 'NR';

        $user = $request->user();
        $pricing = PrezziService::prezzoVisibile($prodotto, $user instanceof User ? $user : null);

        if (($pricing['ordinabile'] ?? false) !== true || ($pricing['prezzo'] ?? null) === null) {
            return back()->with('error', 'Prodotto non ordinabile');
        }

        // Calcolo prezzo in base all’unità
        try {
            $prezzoUnitario = $unita === ($prodotto->unita_misura ?? 'NR')
                ? (float) ($pricing['prezzo_lordo'] ?? $pricing['prezzo'])
                : $prodotto->priceForUnit($unita, (float) ($pricing['prezzo_lordo'] ?? $pricing['prezzo']));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // Chiave univoca: prodotto + unità
        $key = $prodotto->id . '_' . $unita;

        $cart[$key] = [
            'prodotto_id'     => $prodotto->id,
            'nome'            => $prodotto->nome,
            'unita'           => $unita,
            'prezzo_unitario' => round($prezzoUnitario, 2),
            'sconto_percentuale' => (float) ($pricing['sconto_percentuale'] ?? 0),
            'iva_percentuale' => (float) ($pricing['iva_percentuale'] ?? 22),
            'quantita'        => ($cart[$key]['quantita'] ?? 0) + 1,
        ];

        session()->put('cart', $cart);

        return back()->with(
            'success',
            "{$prodotto->nome} ({$unita}) aggiunto al carrello"
        );
    }

    /**
     * Aggiorna le quantità (bulk)
     * qty[key] => quantità
     */
    public function update(Request $request)
    {
        $cart = session()->get('cart', []);

        if ($request->has('qty') && is_array($request->qty)) {
            foreach ($request->qty as $key => $qta) {
                $qta = max(0, (int) $qta);

                if (isset($cart[$key])) {
                    if ($qta === 0) {
                        unset($cart[$key]);
                    } else {
                        $cart[$key]['quantita'] = $qta;
                    }
                }
            }
        }

        session()->put('cart', $cart);

        return back()->with('success', 'Carrello aggiornato');
    }

    /**
     * Rimuove una riga dal carrello
     */
    public function remove(string $key)
    {
        $cart = session()->get('cart', []);

        unset($cart[$key]);

        session()->put('cart', $cart);

        return redirect()->route('filament.clienti.pages.carrello');
    }

    /**
     * Checkout (placeholder)
     */
    public function checkout()
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return back()->with('error', 'Il carrello è vuoto');
        }

        // TODO:
        // - creare ordine
        // - creare righe ordine
        // - congelare prezzo, unità, quantità

        session()->forget('cart');

        return redirect()
            ->route('clienti.dashboard')
            ->with('success', 'Ordine inviato con successo');
    }
}
