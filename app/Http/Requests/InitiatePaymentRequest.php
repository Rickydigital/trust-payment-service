<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authentication itself is handled by the auth.delegated middleware
        // before this request class runs. This just confirms a request is
        // allowed to proceed once authenticated, so always true here.
        return true;
    }

    public function rules(): array
    {
        return [
            'order_reference' => ['required', 'string', 'max:64'],
            'provider_key' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'callback_url' => ['required', 'url', 'max:2048'],
            // Needed later by POST /escrow/release to calculate seller vs
            // delivery vs platform splits. Optional because not every
            // payment is for a marketplace order with shipping (e.g. a
            // Trust Deal escrow funding has no shipping_fee concept).
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0'],

            // Seller identity + payout account, needed to build the
            // escrow_splits row for this order's seller.
            // Seller payout account, accepted as a pre-built array —
            // matches the shape the main platform's PaymentService sends
            // server-to-server (method/account_name/account_number/
            // mobile_network/mobile_phone/bank_name). Flat fields are
            // also still accepted as a fallback for direct/manual calls.
            'seller_payout_account' => ['nullable', 'array'],
            'seller_payout_account.method' => ['nullable', 'in:mobile,bank'],
            'seller_payout_account.mobile_phone' => ['nullable', 'string', 'max:20'],
            'seller_payout_account.bank_name' => ['nullable', 'string', 'max:120'],
            'seller_payout_account.account_name' => ['nullable', 'string', 'max:120'],
            'seller_payout_account.account_number' => ['nullable', 'string', 'max:80'],
            'seller_payout_account.mobile_network' => ['nullable', 'string', 'max:50'],

            'seller_id' => ['nullable', 'string', 'max:64'],
            'seller_payout_method' => ['nullable', 'in:mobile,bank'],
            'seller_payout_phone' => ['nullable', 'required_if:seller_payout_method,mobile', 'string', 'max:20'],
            'seller_bank_name' => ['nullable', 'required_if:seller_payout_method,bank', 'string', 'max:120'],
            'seller_bank_account_name' => ['nullable', 'required_if:seller_payout_method,bank', 'string', 'max:120'],
            'seller_bank_account_number' => ['nullable', 'required_if:seller_payout_method,bank', 'string', 'max:80'],

            'delivery_service_id' => ['nullable', 'string', 'max:64'],
            'delivery_payout_account' => ['nullable', 'array'],
            'delivery_payout_phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}