<?php

namespace App\Support;

final class InvoiceBalanceExpressions
{
    public static function paid(): string
    {
        return "COALESCE((SELECT SUM(amount_cents) FROM payment_transactions WHERE payment_transactions.invoice_id = invoices.id AND type = 'payment' AND status = 'succeeded'), 0)";
    }

    public static function refunded(): string
    {
        return "COALESCE((SELECT SUM(amount_cents) FROM payment_transactions WHERE payment_transactions.invoice_id = invoices.id AND type IN ('refund', 'reversal') AND status = 'succeeded'), 0)";
    }

    public static function net(): string
    {
        return '('.self::paid().' - '.self::refunded().')';
    }

    public static function balance(): string
    {
        return '(invoices.total_cents - '.self::paid().' + '.self::refunded().')';
    }
}
