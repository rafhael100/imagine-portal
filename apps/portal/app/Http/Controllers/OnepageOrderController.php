<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Commerce\OnepageOrderStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Native private checkout transport; SQL reauthorizes every business operation. */
final readonly class OnepageOrderController
{
    public function __construct(private OnepageOrderStore $orders) {}

    public function create(Request $request): JsonResponse
    {
        $reference = $this->reference($request);
        if ($reference === null) return $this->error('authentication_required', 401);
        $input = $request->json()->all();
        if (!$this->only($input, ['request_key', 'selection'])
            || !$this->requestKey($input['request_key'] ?? null)
            || !is_array($input['selection'] ?? null) || $request->query->count() !== 0) {
            return $this->error('invalid_selection', 422);
        }
        try {
            return $this->result($this->orders->createDraft($reference, $input['request_key'], $input['selection']));
        } catch (Throwable) {
            return $this->error('order_unavailable', 503);
        }
    }

    public function resume(Request $request, string $quoteId): JsonResponse
    {
        $reference = $this->reference($request);
        if ($reference === null) return $this->error('authentication_required', 401);
        if (!$this->uuid($quoteId) || $request->query->count() !== 0) return $this->error('not_found', 404);
        try {
            return $this->result($this->orders->resumeDraft($reference, $quoteId));
        } catch (Throwable) {
            return $this->error('order_unavailable', 503);
        }
    }

    public function review(Request $request, string $quoteId): JsonResponse
    {
        $reference = $this->reference($request);
        if ($reference === null) return $this->error('authentication_required', 401);
        if (!$this->uuid($quoteId) || $request->query->count() !== 0) return $this->error('not_found', 404);
        try {
            $review = $this->orders->review($reference, $quoteId);
            if ($review === null) return $this->error('not_found', 404);
            // Buyer/company are authorization inputs inside SQL, not browser inputs.
            $fields = ['quote_id', 'quote', 'offer_digest', 'terms', 'terms_digest',
                'contract_ref', 'contract_revision', 'contract_text', 'contract_digest'];
            if (array_diff($fields, array_keys($review)) !== []) return $this->error('order_unavailable', 503);
            return response()->json(['status' => 'review', 'review' => array_intersect_key($review, array_flip($fields))]);
        } catch (Throwable) {
            return $this->error('order_unavailable', 503);
        }
    }

    public function accept(Request $request, string $quoteId): JsonResponse
    {
        $reference = $this->reference($request);
        if ($reference === null) return $this->error('authentication_required', 401);
        $input = $request->json()->all();
        if (!$this->uuid($quoteId) || $request->query->count() !== 0
            || !$this->only($input, ['request_key', 'acceptance'])
            || !$this->requestKey($input['request_key'] ?? null)
            || !is_array($input['acceptance'] ?? null)) {
            return $this->error('invalid_acceptance', 422);
        }
        try {
            return $this->result($this->orders->accept($reference, $input['request_key'], $quoteId, $input['acceptance']));
        } catch (Throwable) {
            return $this->error('order_unavailable', 503);
        }
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $reference = $this->reference($request);
        if ($reference === null) return $this->error('authentication_required', 401);
        if (!$this->uuid($orderId) || $request->query->count() !== 0) return $this->error('not_found', 404);
        try {
            $order = $this->orders->findOwnedAcceptedOrder($reference, $orderId);
            if ($order === null) return $this->error('not_found', 404);
            return response()->json(['status' => 'pending_payment', 'order' => [
                'id' => $order->id, 'quote_id' => $order->quoteId, 'product' => 'onepage',
                'quote' => $order->quoteSnapshot, 'offer_digest' => $order->offerDigest,
                'terms' => $order->terms, 'terms_digest' => $order->termsDigest,
                'contract_ref' => $order->contractRef, 'contract_revision' => $order->contractRevision,
                'contract_text' => $order->contractText, 'contract_digest' => $order->contractDigest,
                'accepted_at' => $order->acceptedAt,
            ]]);
        } catch (Throwable) {
            return $this->error('order_unavailable', 503);
        }
    }

    private function reference(Request $request): ?string
    {
        if (!$request->hasSession()) return null;
        $state = $request->session()->get('portal.browser_auth');
        if (!is_array($state) || ($state['phase'] ?? null) !== 'business'
            || !is_string($state['reference'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $state['reference']) !== 1) return null;
        return $state['reference'];
    }

    private function result(array $result): JsonResponse
    {
        return match ($result['status'] ?? null) {
            'draft' => $this->success($result, ['status', 'id', 'quote', 'order_created',
                'payment_requested', 'site_provisioned']),
            'pending_payment' => $this->success($result, ['status', 'id', 'quote_id',
                'accepted_at', 'payment_requested', 'site_provisioned']),
            'denied' => $this->error('not_found', 404),
            'conflict' => $this->error('order_conflict', 409),
            'invalid_selection', 'invalid_acceptance' => $this->error($result['status'], 422),
            'terms_unavailable' => $this->error('terms_unavailable', 503),
            default => $this->error('order_unavailable', 503),
        };
    }

    private function success(array $result, array $fields): JsonResponse
    {
        if (array_diff($fields, array_keys($result)) !== []
            || !is_string($result['id'] ?? null) || !$this->uuid($result['id'])) {
            return $this->error('order_unavailable', 503);
        }
        return response()->json(array_intersect_key($result, array_flip($fields)));
    }

    private function only(array $input, array $keys): bool
    {
        $actual = array_keys($input); sort($actual); sort($keys);
        return $actual === $keys;
    }

    private function requestKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) === 1;
    }

    private function uuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private function error(string $error, int $status): JsonResponse
    {
        return response()->json(['error' => $error], $status);
    }
}
