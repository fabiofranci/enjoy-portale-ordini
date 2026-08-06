<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ordine;
use App\Models\User;
use App\Services\Orders\OrderDocumentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        Ordine $ordine,
        string $format,
        OrderDocumentService $documents,
    ): Response {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless(
            $user->hasRole('admin')
                || ($user->hasRole('cliente') && $ordine->user_id === $user->getKey()),
            403,
        );

        $document = $documents->document($ordine, $format);

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
