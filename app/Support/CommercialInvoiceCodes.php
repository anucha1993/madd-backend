<?php

namespace App\Support;

/**
 * Commercial Invoice choices on /shipment/create and the code each carrier expects for them.
 * DHL values are MyDHL API enums (exportReasonType, lineItems quantity.unitOfMeasurement,
 * registrationNumbers typeCode); UPS values are InternationalForms ReasonForExport /
 * Product Unit UnitOfMeasurement codes.
 */
class CommercialInvoiceCodes
{
    /** Purpose of shipment → [UPS ReasonForExport, DHL exportReasonType]. */
    public const EXPORT_REASONS = [
        'SALE' => ['SALE', 'commercial_purpose_or_sale'],
        'GIFT' => ['GIFT', 'gift'],
        'SAMPLE' => ['SAMPLE', 'sample'],
        'PERSONAL' => ['PERSONAL EFFECTS', 'personal_belongings_or_personal_use'],
        'RETURN' => ['RETURN', 'return'],
        'REPAIR' => ['REPAIR', 'temporary'],
    ];

    /** Unit of measure per invoice line → [UPS, DHL]. */
    public const UNITS = [
        'PCS' => ['PCS', 'PCS'],
        'PRS' => ['PRS', 'PRS'],
        'SET' => ['SET', 'SET'],
        'BOX' => ['BOX', 'BOX'],
        'DOZ' => ['DOZ', 'DOZ'],
        'KG' => ['KGS', 'KG'],
        'GM' => ['OTH', 'GM'],
        'M' => ['M', 'M'],
        'ROLL' => ['RL', 'ROLL'],
        'OTHER' => ['OTH', 'PCS'],
    ];

    /** Receiver's tax / ID number type (DHL registrationNumbers typeCode). UPS takes the number alone. */
    public const TAX_ID_TYPES = ['VAT', 'EOR', 'NID', 'PAS', 'SSN', 'EIN', 'CNP'];

    /** UPS ShipTo.TaxIdentificationNumber is at most this long (DHL registrationNumbers takes 35). */
    public const UPS_TAX_ID_MAX = 15;

    /**
     * The receiver's tax number as UPS gets it: spaces, dashes, dots and slashes dropped
     * (e.g. Singapore GST "M2-1234567-8" → "M212345678"), so a number typed with separators fits.
     */
    public static function upsTaxId(?string $taxId): ?string
    {
        $clean = preg_replace('#[\s\-./]+#u', '', (string) $taxId);

        return $clean !== '' ? $clean : null;
    }

    public static function upsReason(?string $reason): string
    {
        return self::EXPORT_REASONS[$reason ?? 'SALE'][0] ?? 'SALE';
    }

    public static function dhlReason(?string $reason): string
    {
        return self::EXPORT_REASONS[$reason ?? ''][1] ?? 'permanent';
    }

    public static function upsUnit(?string $unit): string
    {
        return self::UNITS[$unit ?? 'PCS'][0] ?? 'PCS';
    }

    public static function dhlUnit(?string $unit): string
    {
        return self::UNITS[$unit ?? 'PCS'][1] ?? 'PCS';
    }
}
