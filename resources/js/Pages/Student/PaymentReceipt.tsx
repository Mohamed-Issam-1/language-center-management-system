import '../../../css/student-payment-receipt.css';

import whiteLogo from '@/Assets/lcms-logo-white.svg';
import StudentLayout from '@/Layouts/StudentLayout';
import type { PageProps } from '@/types';
import type {
    PaymentReceiptData,
} from '@/types/student-payments';
import {
    Head,
    Link,
    usePage,
} from '@inertiajs/react';
import {
    Download,
    Printer,
} from 'lucide-react';

type PaymentReceiptPageProps =
    PageProps & {
        paymentReceipt?: PaymentReceiptData;
    };

function money(
    currency: string,
    value: number,
    decimals = false,
) {
    return `${currency} ${value.toLocaleString(
        'en-US',
        {
            minimumFractionDigits:
                decimals
                    ? 2
                    : 0,

            maximumFractionDigits: 2,
        },
    )}`;
}

function ReceiptRow({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="payment-receipt-row">
            <span>
                {label}
            </span>

            <strong>
                {value}
            </strong>
        </div>
    );
}

export default function PaymentReceipt() {
    const page =
        usePage<PaymentReceiptPageProps>();

    const data =
        page.props
            .paymentReceipt;

    if (!data) {
        throw new Error(
            'Payment Receipt data was not provided by Laravel.',
        );
    }

    const receipt =
        data.receipt;

    const print = () => {
        window.print();
    };

    return (
        <StudentLayout
            studentName={
                data.student.name
            }
            studentId={
                data.student.studentId
            }
            centerName={
                data.center.name
            }
            branchName={
                data.center.branch
            }
            pageTitle="Payment Receipt"
            activeNav="payments"
            fluid
        >
            <Head
                title={`Receipt ${receipt.receiptNo}`}
            />

            <div className="payment-receipt-page">
                <div className="payment-receipt-actions">
                    <Link
                        href="/payments"
                        className="payment-receipt-back"
                    >
                        ← Back to Payments
                    </Link>

                    <div className="payment-receipt-action-buttons">
                        <button
                            type="button"
                            className="payment-receipt-print"
                            onClick={
                                print
                            }
                        >
                            <Printer
                                size={
                                    15
                                }
                            />

                            Print
                        </button>

                        <button
                            type="button"
                            className="payment-receipt-pdf"
                            onClick={
                                print
                            }
                        >
                            <Download
                                size={
                                    15
                                }
                            />

                            Save as PDF
                        </button>
                    </div>
                </div>

                <article className="official-receipt">
                    <header className="official-receipt-header">
                        <div className="official-receipt-brand">
                            <img
                                src={
                                    whiteLogo
                                }
                                alt="LCMS"
                            />

                            <span className="official-receipt-divider" />

                            <div>
                                <h2>
                                    {
                                        data
                                            .center
                                            .name
                                    }
                                </h2>

                                <p>
                                    {
                                        data
                                            .center
                                            .branch
                                    }
                                </p>
                            </div>
                        </div>

                        <div className="official-receipt-number">
                            <span>
                                Official
                                Receipt
                            </span>

                            <strong>
                                {
                                    receipt.receiptNo
                                }
                            </strong>

                            <small>
                                Issued:{' '}
                                {
                                    receipt.issuedAt
                                }
                            </small>
                        </div>
                    </header>

                    <div className="official-receipt-body">
                        <div className="official-receipt-status">
                            <span>
                                ● Paid
                            </span>
                        </div>

                        <div className="official-receipt-two-columns">
                            <section className="receipt-information-box">
                                <h3>
                                    Issued To
                                </h3>

                                <strong className="receipt-person-name">
                                    {
                                        receipt
                                            .issuedTo
                                            .name
                                    }
                                </strong>

                                <p>
                                    ID:{' '}
                                    {
                                        receipt
                                            .issuedTo
                                            .studentId
                                    }
                                </p>

                                <p>
                                    {
                                        receipt
                                            .issuedTo
                                            .email
                                    }
                                </p>

                                <p>
                                    {
                                        receipt
                                            .issuedTo
                                            .phone
                                    }
                                </p>
                            </section>

                            <section className="receipt-information-box">
                                <h3>
                                    Receipt
                                    Details
                                </h3>

                                <ReceiptRow
                                    label="Receipt No."
                                    value={
                                        receipt.receiptNo
                                    }
                                />

                                <ReceiptRow
                                    label="Payment Date"
                                    value={
                                        receipt
                                            .paymentDetails
                                            .paymentDate
                                    }
                                />

                                <ReceiptRow
                                    label="Due Date"
                                    value={
                                        receipt
                                            .paymentDetails
                                            .dueDate
                                    }
                                />

                                <ReceiptRow
                                    label="Method"
                                    value={
                                        receipt
                                            .paymentDetails
                                            .method
                                    }
                                />
                            </section>
                        </div>

                        <section className="receipt-section">
                            <h3>
                                Enrollment /
                                Course Details
                            </h3>

                            <div className="receipt-section-body">
                                <ReceiptRow
                                    label="Course"
                                    value={
                                        receipt
                                            .course
                                            .name
                                    }
                                />

                                <ReceiptRow
                                    label="Level"
                                    value={
                                        receipt
                                            .course
                                            .level
                                    }
                                />

                                <ReceiptRow
                                    label="Section"
                                    value={
                                        receipt
                                            .course
                                            .section
                                    }
                                />

                                <ReceiptRow
                                    label="Teacher"
                                    value={
                                        receipt
                                            .course
                                            .teacher
                                    }
                                />

                                <ReceiptRow
                                    label="Branch"
                                    value={
                                        receipt
                                            .course
                                            .branch
                                    }
                                />

                                <ReceiptRow
                                    label="Start Date"
                                    value={
                                        receipt
                                            .course
                                            .startDate
                                    }
                                />

                                <ReceiptRow
                                    label="End Date"
                                    value={
                                        receipt
                                            .course
                                            .endDate
                                    }
                                />

                                <ReceiptRow
                                    label="Enrollment Date"
                                    value={
                                        receipt
                                            .course
                                            .enrollmentDate
                                    }
                                />
                            </div>
                        </section>

                        <section className="receipt-section">
                            <h3>
                                Payment Details
                            </h3>

                            <div className="receipt-section-body">
                                <ReceiptRow
                                    label="Payment Type"
                                    value={
                                        receipt
                                            .payment
                                            .type
                                    }
                                />

                                <ReceiptRow
                                    label="Installment Amount"
                                    value={money(
                                        receipt.currency,
                                        receipt
                                            .payment
                                            .installmentAmount,
                                    )}
                                />

                                <ReceiptRow
                                    label="Total Course Fees"
                                    value={money(
                                        receipt.currency,
                                        receipt
                                            .payment
                                            .totalCourseFees,
                                    )}
                                />

                                <ReceiptRow
                                    label="Total Paid to Date"
                                    value={money(
                                        receipt.currency,
                                        receipt
                                            .payment
                                            .totalPaidToDate,
                                    )}
                                />

                                <ReceiptRow
                                    label="Remaining Balance"
                                    value={money(
                                        receipt.currency,
                                        receipt
                                            .payment
                                            .remainingBalance,
                                    )}
                                />
                            </div>
                        </section>

                        <section className="receipt-amount-bar">
                            <div>
                                <span>
                                    Amount
                                    Received This
                                    Receipt
                                </span>

                                <strong>
                                    {money(
                                        receipt.currency,
                                        receipt
                                            .payment
                                            .amountReceived,
                                        true,
                                    )}
                                </strong>
                            </div>

                            <div className="receipt-remaining">
                                <span>
                                    Remaining
                                </span>

                                <strong>
                                    {money(
                                        receipt.currency,
                                        receipt
                                            .payment
                                            .remainingBalance,
                                        true,
                                    )}
                                </strong>
                            </div>
                        </section>

                        <section className="receipt-authorization">
                            <div>
                                <span>
                                    Received By
                                </span>

                                <strong>
                                    {
                                        receipt
                                            .receivedBy
                                            .name
                                    }
                                </strong>

                                <p>
                                    {
                                        receipt
                                            .receivedBy
                                            .role
                                    }
                                    {' · '}
                                    {
                                        receipt
                                            .receivedBy
                                            .branch
                                    }
                                </p>

                                <div className="receipt-signature-line" />

                                <small>
                                    Authorized
                                    Signature
                                </small>
                            </div>

                            <div>
                                <span>
                                    Official
                                    Stamp
                                </span>

                                <div className="receipt-stamp">
                                    Stamp
                                    <br />
                                    Here
                                </div>
                            </div>
                        </section>

                        <footer className="official-receipt-footer">
                            This receipt is
                            an official
                            document and
                            serves as proof
                            of payment issued
                            by{' '}
                            <strong>
                                {
                                    data
                                        .center
                                        .name
                                }
                            </strong>
                            .
                        </footer>
                    </div>
                </article>
            </div>
        </StudentLayout>
    );
}