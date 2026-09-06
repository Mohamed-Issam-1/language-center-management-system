import { Info } from 'lucide-react';

export default function PaymentInfoBanner() {
    return (
        <section className="records-alert info payment-info">
            <Info size={16} className="records-alert-icon" />
            <p className="records-alert-copy" style={{ margin: 0 }}>
                This page shows your payment information only. To
                make a payment, please visit the center
                administration office.
            </p>
        </section>
    );
}
