import { useState, useCallback } from 'react';

/**
 * React hook for managing statutory discount state during a POS transaction.
 * This state is transient and is used to compute the final sale totals before submission.
 *
 * Usage:
 *   const discount = useDiscountStore();
 *   discount.activeDiscountType  // DiscountType or null
 *   discount.calculationResult   // Calculation preview from backend
 *   discount.setDiscountType(type)
 *   discount.updateOption('eligible_person_count', 2)
 *   discount.addBeneficiary()
 *   discount.clearDiscount()
 */
export function useDiscountStore() {
    const [activeDiscountType, setActiveDiscountType] = useState(null);
    const [discountOptions, setDiscountOptions] = useState({
        application_mode: 'standard',
        eligible_person_count: 1,
        total_pax_count: 1,
        memc_base_value: 0,
        beneficiaries: [],
    });
    const [calculationResult, setCalculationResult] = useState(null);

    const updateOption = useCallback((key, value) => {
        setDiscountOptions((prev) => ({ ...prev, [key]: value }));
    }, []);

    const updateBeneficiary = useCallback((index, data) => {
        setDiscountOptions((prev) => {
            const updated = [...prev.beneficiaries];
            updated[index] = { ...updated[index], ...data };
            return { ...prev, beneficiaries: updated };
        });
    }, []);

    const addBeneficiary = useCallback(() => {
        setDiscountOptions((prev) => ({
            ...prev,
            beneficiaries: [...prev.beneficiaries, { beneficiary_name: '', id_number: '', tin: '', spic_number: '' }],
        }));
    }, []);

    const removeBeneficiary = useCallback((index) => {
        setDiscountOptions((prev) => ({
            ...prev,
            beneficiaries: prev.beneficiaries.filter((_, i) => i !== index),
        }));
    }, []);

    const clearDiscount = useCallback(() => {
        setActiveDiscountType(null);
        setDiscountOptions({
            application_mode: 'standard',
            eligible_person_count: 1,
            total_pax_count: 1,
            memc_base_value: 0,
            beneficiaries: [],
        });
        setCalculationResult(null);
    }, []);

    return {
        activeDiscountType,
        discountOptions,
        calculationResult,
        setDiscountType: setActiveDiscountType,
        updateOption,
        updateBeneficiary,
        addBeneficiary,
        removeBeneficiary,
        setCalculationResult,
        clearDiscount,
    };
}
