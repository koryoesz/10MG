<?php

namespace App\Enums;

/**
 * Kinds of healthcare providers that can borrow.
 */
enum BusinessType: string
{
    case Pharmacy = 'pharmacy';
    case PatentMedicineStore = 'patent_medicine_store';
    case Clinic = 'clinic';
    case Hospital = 'hospital';
    case Laboratory = 'laboratory';
    case DiagnosticCentre = 'diagnostic_centre';

    /**
     * The body whose operating licence the business must hold (Nigerian context).
     */
    public function regulator(): string
    {
        return match ($this) {
            self::Pharmacy, self::PatentMedicineStore => 'Pharmacists Council of Nigeria (PCN)',
            self::Clinic => 'Medical and Dental Council of Nigeria (MDCN)',
            self::Laboratory => 'Medical Laboratory Science Council of Nigeria (MLSCN)',
            self::Hospital, self::DiagnosticCentre => 'State Ministry of Health',
        };
    }
}
