<?php

declare(strict_types=1);

namespace App\Services\Odoo;

use App\Models\Ordine;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OdooQuoteRequestService
{
    private const LEAD_MODEL = 'crm.lead';
    private const PARTNER_MODEL = 'res.partner';
    private const ATTACHMENT_MODEL = 'ir.attachment';

    private readonly OdooClient $client;

    public function __construct(?OdooClient $client = null)
    {
        $this->client = $client ?? OdooClient::fromConfig();
    }

    public function sync(Ordine $ordine, string $pdfContent, string $pdfFileName): void
    {
        if ($ordine->odoo_synced_at !== null) {
            Log::info('Skipping Odoo quote request sync because it is already completed', [
                'ordine_id' => $ordine->id,
                'odoo_lead_id' => $ordine->odoo_lead_id,
                'odoo_synced_at' => $ordine->odoo_synced_at?->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        $ordine->loadMissing(['user.cliente', 'centroCosto', 'items.prodotto']);

        $leadId = $this->ensureLead($ordine);
        $this->upsertPdfAttachment($leadId, $pdfFileName, $pdfContent);

        $ordine->forceFill([
            'odoo_synced_at' => now(),
        ])->save();

        Log::info('Order quote request synced to Odoo', [
            'ordine_id' => $ordine->id,
            'odoo_lead_id' => $leadId,
        ]);
    }

    private function ensureLead(Ordine $ordine): int
    {
        if ($ordine->odoo_lead_id !== null && $ordine->odoo_lead_id > 0) {
            return (int) $ordine->odoo_lead_id;
        }

        $leadId = $this->createLead($ordine);

        $ordine->forceFill([
            'odoo_lead_id' => $leadId,
        ])->save();

        Log::info('Created Odoo CRM lead for order quote request', [
            'ordine_id' => $ordine->id,
            'odoo_lead_id' => $leadId,
        ]);

        return $leadId;
    }

    private function createLead(Ordine $ordine): int
    {
        $payload = $this->buildLeadPayload($ordine);
        $leadId = $this->client->executeKw(self::LEAD_MODEL, 'create', [[$payload]]);

        if (!is_int($leadId) && !ctype_digit((string) $leadId)) {
            throw new RuntimeException(sprintf(
                'Unexpected Odoo lead id response for order %d: %s',
                $ordine->id,
                get_debug_type($leadId)
            ));
        }

        return (int) $leadId;
    }

    /**
     * @return array<string, int|string>
     */
    private function buildLeadPayload(Ordine $ordine): array
    {
        $cliente = $ordine->user?->cliente;
        $payload = [
            'name' => $this->buildLeadTitle($ordine),
            'partner_name' => trim((string) ($cliente?->nome ?? $ordine->user?->name ?? 'Cliente Enjoy')),
            'contact_name' => trim((string) ($ordine->user?->name ?? '')),
            'email_from' => trim((string) ($ordine->user?->email ?? $cliente?->email ?? '')),
            'phone' => trim((string) ($cliente?->telefono ?? '')),
            'description' => $this->buildLeadDescription($ordine),
        ];

        $partnerId = $this->resolvePartnerId($ordine);
        if ($partnerId !== null) {
            $payload['partner_id'] = $partnerId;
        }

        $teamId = $this->normalizeInteger(config('services.odoo.quote_request_team_id'));
        if ($teamId !== null) {
            $payload['team_id'] = $teamId;
        }

        $userId = $this->normalizeInteger(config('services.odoo.quote_request_user_id'));
        if ($userId !== null) {
            $payload['user_id'] = $userId;
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    private function buildLeadTitle(Ordine $ordine): string
    {
        $reference = trim((string) ($ordine->riferimento_cliente ?? ''));

        if ($reference !== '') {
            return sprintf('Richiesta preventivo Portale Enjoy - Conferma %s', $reference);
        }

        return sprintf('Richiesta preventivo Portale Enjoy - Ordine %d', $ordine->id);
    }

    private function buildLeadDescription(Ordine $ordine): string
    {
        $cliente = $ordine->user?->cliente;
        $lines = [
            'Origine: Portale Clienti Enjoy',
            sprintf('Ordine locale: %d', $ordine->id),
        ];

        if ($ordine->riferimento_cliente !== null && trim($ordine->riferimento_cliente) !== '') {
            $lines[] = sprintf('Conferma ordine: %s', trim($ordine->riferimento_cliente));
        }

        if ($cliente?->nome) {
            $lines[] = sprintf('Cliente: %s', $cliente->nome);
        }

        if ($cliente?->partita_iva) {
            $lines[] = sprintf('Partita IVA: %s', $cliente->partita_iva);
        }

        if ($ordine->user?->name) {
            $lines[] = sprintf('Contatto: %s', $ordine->user->name);
        }

        if ($ordine->user?->email) {
            $lines[] = sprintf('Email contatto: %s', $ordine->user->email);
        }

        if ($cliente?->telefono) {
            $lines[] = sprintf('Telefono: %s', $cliente->telefono);
        }

        if ($ordine->centroCosto?->nome) {
            $lines[] = sprintf('Centro di costo: %s', $ordine->centroCosto->nome);
        }

        $lines[] = '';
        $lines[] = 'Righe richieste:';

        foreach ($ordine->items as $item) {
            $productCode = trim((string) ($item->prodotto?->codice ?? ''));
            $productName = trim((string) ($item->prodotto?->nome ?? 'Prodotto non disponibile'));
            $unit = trim((string) ($item->unita ?? $item->prodotto?->unita_misura ?? 'NR'));

            $label = $productCode !== ''
                ? sprintf('%s - %s', $productCode, $productName)
                : $productName;

            $lines[] = sprintf('- %s | UDM: %s | Quantita: %d', $label, $unit, (int) $item->quantita);
        }

        return implode(PHP_EOL, $lines);
    }

    private function upsertPdfAttachment(int $leadId, string $pdfFileName, string $pdfContent): void
    {
        $attachmentValues = [
            'name' => $pdfFileName,
            'datas' => base64_encode($pdfContent),
            'datas_fname' => $pdfFileName,
            'res_model' => self::LEAD_MODEL,
            'res_id' => $leadId,
            'type' => 'binary',
            'mimetype' => 'application/pdf',
        ];

        $attachmentId = $this->findAttachmentId($leadId, $pdfFileName);

        if ($attachmentId === null) {
            $createdAttachmentId = $this->client->executeKw(
                self::ATTACHMENT_MODEL,
                'create',
                [[$attachmentValues]]
            );

            Log::info('Created Odoo attachment for order quote request', [
                'odoo_lead_id' => $leadId,
                'attachment_id' => $createdAttachmentId,
                'filename' => $pdfFileName,
            ]);

            return;
        }

        $this->client->executeKw(
            self::ATTACHMENT_MODEL,
            'write',
            [[$attachmentId], $attachmentValues]
        );

        Log::info('Updated Odoo attachment for order quote request', [
            'odoo_lead_id' => $leadId,
            'attachment_id' => $attachmentId,
            'filename' => $pdfFileName,
        ]);
    }

    private function findAttachmentId(int $leadId, string $pdfFileName): ?int
    {
        $matches = $this->client->searchRead(
            self::ATTACHMENT_MODEL,
            [
                ['res_model', '=', self::LEAD_MODEL],
                ['res_id', '=', $leadId],
                ['name', '=', $pdfFileName],
            ],
            ['id'],
            [
                'limit' => 1,
                'order' => 'id desc',
            ]
        );

        if ($matches === []) {
            return null;
        }

        return $this->normalizeInteger($matches[0]['id'] ?? null);
    }

    private function resolvePartnerId(Ordine $ordine): ?int
    {
        $cliente = $ordine->user?->cliente;

        $domains = [];

        if ($cliente?->partita_iva) {
            $domains[] = [['vat', '=', trim($cliente->partita_iva)]];
        }

        if ($ordine->user?->email) {
            $domains[] = [['email', '=', trim($ordine->user->email)]];
        } elseif ($cliente?->email) {
            $domains[] = [['email', '=', trim($cliente->email)]];
        }

        if ($cliente?->nome) {
            $domains[] = [['name', '=', trim($cliente->nome)]];
        }

        foreach ($domains as $domain) {
            $matches = $this->client->searchRead(
                self::PARTNER_MODEL,
                $domain,
                ['id'],
                [
                    'limit' => 1,
                    'order' => 'id desc',
                ]
            );

            if ($matches === []) {
                continue;
            }

            return $this->normalizeInteger($matches[0]['id'] ?? null);
        }

        return null;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
