<?php

namespace App\Enums;

enum LoanPurpose: string
{
    /** Drugs, consumables and other stock. */
    case InventoryRestock = 'inventory_restock';
    case MedicalEquipment = 'medical_equipment';
    case WorkingCapital = 'working_capital';
    /** Urgent cash need, e.g. to keep emergency care running. */
    case EmergencyFinancing = 'emergency_financing';
    case FacilityExpansion = 'facility_expansion';
    case Other = 'other';
}
