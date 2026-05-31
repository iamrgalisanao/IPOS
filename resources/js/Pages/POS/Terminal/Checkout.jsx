import React from 'react';
import TabletPOSLayout from '@/Layouts/TabletPOSLayout';
import POSIndex from '@/Pages/POS/Index';

export default function Checkout(props) {
    return <POSIndex {...props} />;
}

Checkout.layout = (page) => <TabletPOSLayout>{page}</TabletPOSLayout>;
